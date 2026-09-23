<?php

namespace App\Identification;

use App\Audit\Auditor;
use App\Clients\ClientTiers;
use App\Enums\IdChannel;
use App\Enums\IdMethod;
use App\Enums\IdSessionStatus;
use App\Enums\StepVerdict;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\IdSessionStep;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * State machine for a question-and-answer identification session.
 *
 *  - start: snapshot the rules, open the session
 *  - next: reveal one random, not yet shown, usable answer to the agent
 *  - decide: record the agent's verdict and resolve the outcome
 *
 * Outcome rules (all configurable):
 *  passed     accepted >= required_accepted
 *  failed     rejected >= max_rejected, or passing became unreachable
 *             (question limit, pool exhausted) with at least one rejection
 *  undecided  passing became unreachable without any rejection
 */
class QaSessionEngine
{
    public function __construct(
        private readonly ClientAnswers $answers,
        private readonly Settings $settings,
        private readonly Auditor $auditor,
        private readonly ClientTiers $tiers,
    ) {}

    public function start(Client $client, User $agent, ?Call $call = null, IdChannel $channel = IdChannel::Manual): IdSession
    {
        if (! $this->isIdentifiable($client)) {
            throw new IdentificationException(__('The client\'s account is closed or no longer entitled; it cannot be identified.'));
        }

        if (! $this->answers->isEligible($client)) {
            throw new IdentificationException(__('The client has not answered enough questions (:n required).', ['n' => $this->answers->requiredCount()]));
        }

        // Only another question-and-answer session blocks a new one: a PIN or
        // a manual session is decided in the same request, and a row left
        // open by a failure must not lock the client out for good.
        $open = IdSession::query()->open()->where('client_id', $client->id)->where('method', IdMethod::QuestionAnswer)->with('agent')->latest('id')->first();
        if ($open !== null && $open->agent_user_id === $agent->id) {
            return $open;
        }

        if ($open !== null) {
            throw new IdentificationException(__('Another agent (:name) is already identifying this client.', ['name' => $open->agent?->name ?? '?']));
        }

        $session = IdSession::query()->create([
            'client_id' => $client->id,
            'agent_user_id' => $agent->id,
            'call_id' => $call?->id,
            'channel' => $channel,
            'method' => IdMethod::QuestionAnswer,
            'status' => IdSessionStatus::Open,
            'required_accepted' => $this->settings->int(SettingKey::QaMinAcceptedToPass),
            'max_questions' => $this->settings->int(SettingKey::QaMaxQuestionsPerSession),
            'max_rejected' => $this->settings->int(SettingKey::QaMaxRejectedToFail),
            'started_at' => now(),
            'expires_at' => now()->addMinutes($this->settings->int(SettingKey::QaSessionTtlMinutes)),
        ]);

        $this->auditor->record('id_session.started', $session, ['client_id' => $client->id, 'call_id' => $call?->id], $agent);

        return $session;
    }

    /**
     * The step to show now: the pending one if any, otherwise a new random one.
     * Returns null when nothing more can be asked (the session is then resolved).
     * The acting agent, when given, has to be the one who opened the session.
     */
    public function next(IdSession $session, ?User $agent = null): ?IdSessionStep
    {
        $this->guardAgent($session, $agent);
        $this->guardOpen($session);

        $pending = $session->steps()->where('verdict', StepVerdict::Pending)->first();
        if ($pending !== null) {
            return $pending;
        }

        if ($session->askedCount() >= $session->max_questions) {
            $this->resolveExhausted($session, 'question_limit');

            return null;
        }

        $shown = $session->steps()->pluck('question_id')->all();
        $candidates = $this->answers->usable($session->client)->reject(fn ($a) => in_array($a->question_id, $shown, true));

        if ($candidates->isEmpty()) {
            $this->resolveExhausted($session, 'pool_exhausted');

            return null;
        }

        $answer = $candidates->random();

        $step = $session->steps()->create([
            'question_id' => $answer->question_id,
            'question_version_id' => $answer->question_version_id,
            'verdict' => StepVerdict::Pending,
            'shown_at' => now(),
        ]);

        $this->auditor->record('id_session.question_shown', $session, ['step_id' => $step->id, 'question_id' => $step->question_id]);

        return $step;
    }

    /**
     * The stored answer for a step, revealed to the agent only while pending.
     */
    public function revealAnswer(IdSessionStep $step): ?string
    {
        if (! $step->isPending() || $step->session->status !== IdSessionStatus::Open) {
            return null;
        }

        return $this->answers->usable($step->session->client)->firstWhere('question_id', $step->question_id)?->answer;
    }

    public function decide(IdSessionStep $step, StepVerdict $verdict, ?User $agent = null): IdSession
    {
        if ($verdict === StepVerdict::Pending) {
            throw new IdentificationException(__('A verdict is required.'));
        }

        $result = DB::transaction(function () use ($step, $verdict, $agent): IdSession|IdentificationException {
            // Lock the client first, like account closure, then the session.
            // Neither a stale model nor a concurrent closure may revive a decision.
            $client = Client::query()->lockForUpdate()->findOrFail($step->session->client_id);
            $session = IdSession::query()->lockForUpdate()->findOrFail($step->id_session_id);
            $session->setRelation('client', $client);

            try {
                $this->guardAgent($session, $agent);
                $this->guardOpen($session);
            } catch (IdentificationException $exception) {
                return $exception;
            }

            if (! $this->isIdentifiable($client)) {
                $this->finish($session, IdSessionStatus::Failed, 'account_closed');

                return new IdentificationException(__('The client\'s account is closed or no longer entitled; it cannot be identified.'));
            }

            $decided = IdSessionStep::query()
                ->whereKey($step->id)
                ->where('verdict', StepVerdict::Pending)
                ->update(['verdict' => $verdict, 'decided_at' => now()]);

            if ($decided !== 1) {
                throw new IdentificationException(__('This question has already been decided.'));
            }

            $step->forceFill(['verdict' => $verdict, 'decided_at' => now()])->syncOriginal();

            $column = match ($verdict) {
                StepVerdict::Accepted => 'accepted_count',
                StepVerdict::Rejected => 'rejected_count',
                StepVerdict::Undecided => 'undecided_count',
            };
            $session->increment($column);
            $session->refresh();

            $this->auditor->record('id_session.answer_judged', $session, ['step_id' => $step->id, 'verdict' => $verdict->value]);

            if ($session->accepted_count >= $session->required_accepted) {
                return $this->finish($session, IdSessionStatus::Passed, 'enough_accepted');
            }

            if ($session->rejected_count >= $session->max_rejected) {
                return $this->finish($session, IdSessionStatus::Failed, 'too_many_rejected');
            }

            if ($session->askedCount() >= $session->max_questions) {
                $this->resolveExhausted($session, 'question_limit');
            } elseif (! $this->canStillPass($session)) {
                $this->resolveExhausted($session, 'unreachable');
            }

            return $session->refresh();
        });

        // Persist expiry or loss of entitlement before reporting the rejection.
        if ($result instanceof IdentificationException) {
            throw $result;
        }

        return $result;
    }

    public function cancel(IdSession $session, string $reason = 'cancelled_by_agent', ?User $agent = null): IdSession
    {
        $this->guardAgent($session, $agent);
        $this->guardOpen($session);

        return $this->finish($session, IdSessionStatus::Cancelled, $reason);
    }

    /**
     * Close every open session of a client (only the given agent's when one
     * is passed), e.g. when the call turns out to belong to somebody else.
     */
    public function cancelOpenSessions(Client $client, ?User $agent = null, string $reason = 'cancelled'): int
    {
        $count = 0;

        IdSession::query()->open()
            ->where('client_id', $client->id)
            ->when($agent !== null, fn ($query) => $query->where('agent_user_id', $agent->id))
            ->each(function (IdSession $session) use ($reason, &$count): void {
                $this->finish($session, IdSessionStatus::Cancelled, $reason);
                $count++;
            });

        return $count;
    }

    /**
     * Expire every open session past its deadline. Run from the scheduler.
     */
    public function expireStale(): int
    {
        $count = 0;

        // Sessions without a deadline (PIN, manual) are decided in the same
        // request; one left open by a failure is closed by age.
        IdSession::query()->open()
            ->where(fn ($query) => $query->where('expires_at', '<', now())
                ->orWhere(fn ($stale) => $stale->whereNull('expires_at')->where('started_at', '<', now()->subMinutes($this->settings->int(SettingKey::QaSessionTtlMinutes)))))
            ->each(function (IdSession $session) use (&$count): void {
                $this->finish($session, IdSessionStatus::Expired, 'timeout');
                $count++;
            });

        return $count;
    }

    private function canStillPass(IdSession $session): bool
    {
        $remainingSlots = $session->max_questions - $session->askedCount();
        $remainingAnswers = $this->answers->usableCount($session->client) - $session->steps()->count();
        $needed = $session->required_accepted - $session->accepted_count;

        return min($remainingSlots, $remainingAnswers) >= $needed;
    }

    private function resolveExhausted(IdSession $session, string $reason): void
    {
        $status = $session->rejected_count > 0 ? IdSessionStatus::Failed : IdSessionStatus::Undecided;
        $this->finish($session, $status, $reason);
    }

    private function finish(IdSession $session, IdSessionStatus $status, string $reason): IdSession
    {
        $finished = IdSession::query()->whereKey($session->id)->open()
            ->update(['status' => $status, 'outcome_reason' => $reason, 'decided_at' => now()]);
        $session->refresh();

        if ($finished === 1) {
            $this->auditor->record('id_session.finished', $session, ['status' => $status->value, 'reason' => $reason]);
        }

        return $session;
    }

    private function isIdentifiable(Client $client): bool
    {
        return ! $client->isClosed() && $this->tiers->isEntitled($client);
    }

    /**
     * A session may only be driven by the agent who opened it.
     */
    private function guardAgent(IdSession $session, ?User $agent): void
    {
        if ($agent !== null && $session->agent_user_id !== null && $session->agent_user_id !== $agent->id) {
            throw new IdentificationException(__('This identification session belongs to another agent.'));
        }
    }

    private function guardOpen(IdSession $session): void
    {
        if ($session->isOpen() && $session->isExpired()) {
            $this->finish($session, IdSessionStatus::Expired, 'timeout');
        }

        if (! $session->isOpen()) {
            throw new IdentificationException(__('This identification session is closed (:status).', ['status' => __($session->status->value)]));
        }
    }
}
