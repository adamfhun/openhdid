<?php

namespace App\Identification;

use App\Audit\Auditor;
use App\Enums\IdMethod;
use App\Enums\IdSessionStatus;
use App\Enums\StepVerdict;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Support\HuDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * How often one client may be tried with question and answer: a caller who
 * knows some of the answers could otherwise call again and again until the
 * random questions suit them. After the set number of unsuccessful sessions
 * within the set period (3 in 24 hours by default), a new session
 * starts only once the oldest of them leaves the period, or once staff with
 * the client management permission lift the lock. The count comes from the
 * identification sessions themselves; the unlock is a time mark in the
 * shared cache, so losing the cache brings the lock back, never lifts one.
 * The agents' own hourly limit (AgentAttemptLimiter) is a separate rule.
 *
 * Unsuccessful: failed or undecided, and also cancelled or expired once the
 * agent was shown a question that was not accepted (pending, rejected or
 * "cannot decide"): hanging up on an unknown question and having the
 * session cancelled, or letting it run out, is no free retry. A session
 * closed before any question was shown, or with every shown question
 * accepted, does not count (owner decision, 2026-10-25).
 */
class QaRetryLimit
{
    /** The longest period the setting accepts; the unlock mark lives this long. */
    public const MAX_WINDOW_HOURS = 168;

    private const UNLOCK_PREFIX = 'hdid:qa-unlocked:';

    /** Failures of the client's account, not of the caller, do not count. */
    private const NOT_COUNTED_REASONS = ['account_closed'];

    /** These count only after a shown question that was not accepted. */
    private const CLOSED_EARLY = [IdSessionStatus::Cancelled, IdSessionStatus::Expired];

    public function __construct(
        private readonly Settings $settings,
        private readonly Auditor $auditor,
    ) {}

    public function limit(): int
    {
        return max(1, $this->settings->int(SettingKey::QaMaxUnsuccessfulSessions));
    }

    public function windowHours(): int
    {
        return max(1, min(self::MAX_WINDOW_HOURS, $this->settings->int(SettingKey::QaUnsuccessfulWindowHours)));
    }

    /**
     * Unsuccessful question-and-answer sessions of the client within the
     * period, after the last unlock.
     */
    public function unsuccessfulCount(Client $client): int
    {
        return $this->counted($client)->count();
    }

    /**
     * When the client may be tried again with question and answer; null
     * while a new session may start now. The lock ends when so many of the
     * counted sessions have left the period that fewer than the limit remain.
     */
    public function lockedUntil(Client $client): ?CarbonInterface
    {
        $times = $this->counted($client)->pluck('decided_at')->sort()->values();

        if ($times->count() < $this->limit()) {
            return null;
        }

        return CarbonImmutable::parse($times[$times->count() - $this->limit()])->addHours($this->windowHours());
    }

    /**
     * Why question and answer cannot start for the client now, or null.
     */
    public function refusal(Client $client): ?string
    {
        $until = $this->lockedUntil($client);

        if ($until === null) {
            return null;
        }

        return __('Question-and-answer is locked for this client until :until: :n unsuccessful sessions in the last :h hours (failed, undecided, or cancelled or expired with a shown question that was not accepted), :max allowed. Use another method, or staff with the client management permission can lift the lock.', [
            'until' => $until->format(HuDate::DATETIME),
            'n' => $this->unsuccessfulCount($client),
            'h' => $this->windowHours(),
            'max' => $this->limit(),
        ]);
    }

    /**
     * Refuses a new session while the client is locked; the refusal is
     * audited. Called by QaSessionEngine::start inside the client's lock,
     * so the count and the new session cannot interleave.
     *
     * @throws IdentificationException
     */
    public function assertAllowed(Client $client, User $agent, ?Call $call = null): void
    {
        $until = $this->lockedUntil($client);

        if ($until === null) {
            return;
        }

        $this->auditor->record('id_session.qa_refused', $client, [
            'reason' => 'too_many_unsuccessful',
            'count' => $this->unsuccessfulCount($client),
            'limit' => $this->limit(),
            'window_hours' => $this->windowHours(),
            'until' => $until->toIso8601String(),
            'call_id' => $call?->id,
        ], $agent);

        throw new IdentificationException((string) $this->refusal($client));
    }

    /**
     * Lifts the lock: the sessions so far no longer count; new failures do.
     * Whether the lock is gone afterwards: false when the cache did not keep
     * the mark (a cache that stores nothing, or one cleared at once), so
     * the page can say the client is still locked instead of claiming it.
     */
    public function unlock(Client $client, User $by): bool
    {
        $count = $this->unsuccessfulCount($client);

        Cache::put(self::UNLOCK_PREFIX.$client->id, now()->getTimestamp(), now()->addHours(self::MAX_WINDOW_HOURS));

        $lifted = $this->lockedUntil($client) === null;

        $this->auditor->record('client.qa_unlocked', $client, ['count' => $count, 'limit' => $this->limit(), 'window_hours' => $this->windowHours(), 'lifted' => $lifted], $by);

        return $lifted;
    }

    /**
     * Unsuccessful identification attempts of the client by any method
     * within the period, on any call and whatever an unlock did: what the
     * agent sees on the identification page.
     */
    public function recentUnsuccessfulAttempts(Client $client): int
    {
        return IdSession::query()
            ->where('client_id', $client->id)
            ->where(fn (Builder $query) => $this->whereUnsuccessful($query))
            ->where('decided_at', '>', now()->subHours($this->windowHours()))
            ->count();
    }

    /**
     * Whether a cancelled or expired session counts as unsuccessful because
     * a question was shown in it and not accepted; the identification page
     * says so next to the attempt. Reads the loaded steps when present.
     */
    public function closedEarlyAfterUnacceptedQuestion(IdSession $session): bool
    {
        if ($session->method !== IdMethod::QuestionAnswer
            || ! in_array($session->status, self::CLOSED_EARLY, true)
            || in_array($session->outcome_reason, self::NOT_COUNTED_REASONS, true)) {
            return false;
        }

        return $session->steps->contains(fn ($step): bool => $step->verdict !== StepVerdict::Accepted);
    }

    /**
     * Failed or undecided; or cancelled or expired, not for the client's
     * account, after a shown question that was not accepted. A step exists
     * only once its question was shown (QaSessionEngine::next).
     *
     * @param  Builder<IdSession>  $query
     */
    private function whereUnsuccessful(Builder $query): void
    {
        $query->whereIn('status', [IdSessionStatus::Failed, IdSessionStatus::Undecided])
            ->orWhere(fn (Builder $query) => $query
                ->whereIn('status', self::CLOSED_EARLY)
                ->where('method', IdMethod::QuestionAnswer)
                ->where(fn (Builder $query) => $query->whereNull('outcome_reason')->orWhereNotIn('outcome_reason', self::NOT_COUNTED_REASONS))
                ->whereHas('steps', fn (Builder $query) => $query->where('verdict', '!=', StepVerdict::Accepted)));
    }

    /**
     * @return Collection<int, IdSession>
     */
    private function counted(Client $client): Collection
    {
        $since = now()->subHours($this->windowHours());
        // Redis hands a stored number back as text, the array store as an integer.
        $mark = Cache::get(self::UNLOCK_PREFIX.$client->id);
        $unlocked = is_int($mark) || (is_string($mark) && ctype_digit($mark)) ? (int) $mark : null;

        if ($unlocked !== null && $unlocked > $since->getTimestamp()) {
            $since = CarbonImmutable::createFromTimestamp($unlocked, $since->getTimezone());
        }

        return IdSession::query()
            ->where('client_id', $client->id)
            ->where('method', IdMethod::QuestionAnswer)
            ->where(fn (Builder $query) => $this->whereUnsuccessful($query))
            ->where(fn (Builder $query) => $query->whereNull('outcome_reason')->orWhereNotIn('outcome_reason', self::NOT_COUNTED_REASONS))
            ->where('decided_at', '>', $since)
            ->get(['id', 'decided_at']);
    }
}
