<?php

use App\Enums\IdMethod;
use App\Enums\IdSessionStatus;
use App\Enums\StepVerdict;
use App\Identification\ClientAnswers;
use App\Identification\IdentificationException;
use App\Identification\QaSessionEngine;
use App\Identification\QuestionCatalog;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\IdSessionStep;
use App\Models\Question;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const QA_START_LOCK_PREFIX = 'hdid:qa:';

/**
 * More rows than one offset page of the default 1000, to prove keyset paging.
 */
const SESSIONS_BEYOND_ONE_PAGE = 1001;

/**
 * A client with `$answered` usable answers in an active pool of `$poolSize` questions.
 */
function clientWithAnswers(int $answered, int $poolSize = 6): Client
{
    $questions = Question::factory()->count($poolSize)->create();

    $client = Client::factory()->synced()->create();
    foreach ($questions->take($answered) as $i => $question) {
        app(ClientAnswers::class)->save($client, $question, 'answer '.$i);
    }

    return $client;
}

function openQaSessionsFor(Client $client): int
{
    return IdSession::query()->open()->where('client_id', $client->id)->where('method', IdMethod::QuestionAnswer)->count();
}

/**
 * Bulk-insert open, already expired sessions for one client and agent with
 * time-ordered ids exactly like the model would generate (factories would
 * be far too slow at this row count).
 */
function insertExpiredOpenSessions(Client $client, User $agent, int $count, ?string $expiresAt): void
{
    $now = now();
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'id' => (string) Str::orderedUuid(),
            'client_id' => $client->id,
            'agent_user_id' => $agent->id,
            'channel' => 'manual',
            'method' => 'qa',
            'status' => IdSessionStatus::Open->value,
            'required_accepted' => 2,
            'max_questions' => 4,
            'max_rejected' => 2,
            'started_at' => $now->copy()->subHours(3),
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 250) as $chunk) {
        DB::table('id_sessions')->insert($chunk);
    }
}

beforeEach(function (): void {
    $this->agent = User::factory()->create();
    $this->engine = app(QaSessionEngine::class);
});

afterEach(function (): void {
    IdSession::flushEventListeners();
});

it('refuses to start when the client has too few answers', function (): void {
    $client = clientWithAnswers(4);

    expect(fn () => $this->engine->start($client, $this->agent))->toThrow(IdentificationException::class);
});

it('reveals one random answered question at a time and passes after enough accepted', function (): void {
    $client = clientWithAnswers(5);
    $session = $this->engine->start($client, $this->agent);

    expect($session->required_accepted)->toBe(2)->and($session->max_questions)->toBe(4)->and($session->max_rejected)->toBe(2);

    $step1 = $this->engine->next($session);
    expect($this->engine->revealAnswer($step1))->toStartWith('answer ')
        ->and($this->engine->next($session)->id)->toBe($step1->id, 'pending step is returned again');

    $this->engine->decide($step1, StepVerdict::Accepted);
    $step2 = $this->engine->next($session->refresh());
    expect($step2->question_id)->not->toBe($step1->question_id);

    $session = $this->engine->decide($step2, StepVerdict::Accepted);

    expect($session->status)->toBe(IdSessionStatus::Passed)
        ->and($session->outcome_reason)->toBe('enough_accepted')
        ->and($this->engine->revealAnswer($step2))->toBeNull('answers are hidden once decided');

    expect(fn () => $this->engine->next($session))->toThrow(IdentificationException::class);
    expect(AuditLog::query()->where('event', 'id_session.answer_judged')->count())->toBe(2)
        ->and(AuditLog::query()->where('event', 'id_session.finished')->exists())->toBeTrue();
});

it('fails after the configured number of rejected answers', function (): void {
    $client = clientWithAnswers(5);
    $session = $this->engine->start($client, $this->agent);

    $this->engine->decide($this->engine->next($session), StepVerdict::Rejected);
    $session = $this->engine->decide($this->engine->next($session->refresh()), StepVerdict::Rejected);

    expect($session->status)->toBe(IdSessionStatus::Failed)->and($session->outcome_reason)->toBe('too_many_rejected');
});

it('ends undecided when the question limit is hit without a rejection', function (): void {
    app(Settings::class)->set(SettingKey::QaMinAcceptedToPass, 1);
    $client = clientWithAnswers(6);
    $session = $this->engine->start($client, $this->agent);

    foreach (range(1, 4) as $i) {
        $session = $this->engine->decide($this->engine->next($session->refresh()), StepVerdict::Undecided);
    }

    expect($session->status)->toBe(IdSessionStatus::Undecided)
        ->and($session->outcome_reason)->toBe('question_limit')
        ->and($session->undecided_count)->toBe(4);
});

it('ends undecided early once passing is unreachable without a rejection', function (): void {
    app(Settings::class)->set(SettingKey::QaMinAcceptedToPass, 3);
    $client = clientWithAnswers(6);
    $session = $this->engine->start($client, $this->agent);

    $this->engine->decide($this->engine->next($session), StepVerdict::Accepted);
    $this->engine->decide($this->engine->next($session->refresh()), StepVerdict::Undecided);
    $session = $this->engine->decide($this->engine->next($session->refresh()), StepVerdict::Undecided);

    expect($session->status)->toBe(IdSessionStatus::Undecided)
        ->and($session->outcome_reason)->toBe('unreachable')
        ->and($session->askedCount())->toBe(3);
});

it('fails early when passing is no longer reachable and something was rejected', function (): void {
    app(Settings::class)->set(SettingKey::QaMinAcceptedToPass, 3);
    app(Settings::class)->set(SettingKey::QaMaxRejectedToFail, 5);
    $client = clientWithAnswers(5);
    $session = $this->engine->start($client, $this->agent);

    // 4 slots, need 3 accepted: one rejection + one undecided make it unreachable.
    $this->engine->decide($this->engine->next($session), StepVerdict::Rejected);
    $session = $this->engine->decide($this->engine->next($session->refresh()), StepVerdict::Undecided);

    expect($session->status)->toBe(IdSessionStatus::Failed)->and($session->outcome_reason)->toBe('unreachable');
});

it('never shows inactive questions or answers given to an outdated wording', function (): void {
    app(Settings::class)->set(SettingKey::QaMinAnsweredQuestionsRequired, 4);
    app(Settings::class)->set(SettingKey::QaMinAcceptedToPass, 4);
    $client = clientWithAnswers(6);
    $answers = $client->answers()->get();
    Question::query()->whereKey($answers[0]->question_id)->update(['is_active' => false]);
    // answers[5]'s question gets a meaning-changing new version: the old answer no longer counts
    app(QuestionCatalog::class)->publishVersion(Question::query()->find($answers[5]->question_id), 'Completely new question?', null, keepsAnswers: false);

    $session = $this->engine->start($client, $this->agent);
    $seen = [];
    foreach (range(1, 4) as $i) {
        $step = $this->engine->next($session->refresh());
        $seen[] = $step->question_id;
        $this->engine->decide($step, StepVerdict::Accepted);
    }

    expect($seen)->toHaveCount(4)
        ->and($seen)->not->toContain($answers[0]->question_id)
        ->and($seen)->not->toContain($answers[5]->question_id);
});

it('expires a stale session on touch and via the sweeper', function (): void {
    $client = clientWithAnswers(5);
    $session = $this->engine->start($client, $this->agent);
    $this->travel(31)->minutes();

    expect(fn () => $this->engine->next($session))->toThrow(IdentificationException::class);
    expect($session->fresh()->status)->toBe(IdSessionStatus::Expired);

    $other = $this->engine->start(clientWithAnswers(5), $this->agent);
    $this->travel(31)->minutes();
    expect($this->engine->expireStale())->toBe(1)->and($other->fresh()->status)->toBe(IdSessionStatus::Expired);
});

it('returns the same open session for the same client and agent', function (): void {
    $client = clientWithAnswers(5);

    expect($this->engine->start($client, $this->agent)->id)->toBe($this->engine->start($client, $this->agent)->id);
});

it('holds a per-client lock while a question-and-answer session is being opened', function (): void {
    $client = clientWithAnswers(5);
    $lockWasFree = null;

    IdSession::creating(function (IdSession $session) use (&$lockWasFree): void {
        if ($lockWasFree !== null) {
            return;
        }

        $lock = Cache::lock(QA_START_LOCK_PREFIX.$session->client_id, 10);
        $lockWasFree = $lock->get();

        if ($lockWasFree) {
            $lock->release();
        }
    });

    $this->engine->start($client, $this->agent);

    expect($lockWasFree)->toBeFalse('the open-session check and the insert must run inside Cache::lock(\'hdid:qa:<client>\')');
});

it('keeps a single open question-and-answer session when two agents start at the same moment', function (): void {
    $client = clientWithAnswers(5);
    $first = User::factory()->create(['name' => 'First Agent']);
    $second = User::factory()->create(['name' => 'Second Agent']);
    $interleaved = false;
    $interleavedResult = null;

    // Simulate the race: a second agent's start() runs between the first
    // agent's open-session check and its insert. It can only interleave when
    // the engine does not hold the client's lock at that moment.
    IdSession::creating(function (IdSession $session) use ($client, $second, &$interleaved, &$interleavedResult): void {
        if ($interleaved) {
            return;
        }
        $interleaved = true;

        $lock = Cache::lock(QA_START_LOCK_PREFIX.$session->client_id, 10);
        if (! $lock->get()) {
            $interleavedResult = 'blocked by lock';

            return;
        }
        $lock->release();

        try {
            $interleavedResult = $this->engine->start($client, $second);
        } catch (Throwable $exception) {
            $interleavedResult = $exception;
        }
    });

    $session = $this->engine->start($client, $first);

    expect($interleaved)->toBeTrue()
        ->and(openQaSessionsFor($client))->toBe(1, 'exactly one open question-and-answer session may exist per client')
        ->and($interleavedResult)->not->toBeInstanceOf(IdSession::class, 'the interleaved second start must not open a session of its own')
        ->and($session->agent_user_id)->toBe($first->id);

    // Once the first agent's session exists, the sequential path still refuses the colleague by name.
    expect(fn () => $this->engine->start($client, $second))
        ->toThrow(IdentificationException::class, 'First Agent');
    expect(openQaSessionsFor($client))->toBe(1);
});

it('expires every stale session in one sweep even when there are more than one page of them', function (): void {
    $client = Client::factory()->synced()->create();
    insertExpiredOpenSessions($client, $this->agent, SESSIONS_BEYOND_ONE_PAGE, now()->subMinutes(5)->toDateTimeString());

    expect(IdSession::query()->open()->count())->toBe(SESSIONS_BEYOND_ONE_PAGE);

    $expired = $this->engine->expireStale();

    expect($expired)->toBe(SESSIONS_BEYOND_ONE_PAGE, 'the sweeper must report every stale session it found')
        ->and(IdSession::query()->open()->count())->toBe(0, 'no stale session may survive a single sweep')
        ->and(IdSession::query()->where('status', IdSessionStatus::Expired)->where('outcome_reason', 'timeout')->count())->toBe(SESSIONS_BEYOND_ONE_PAGE)
        ->and(AuditLog::query()->where('event', 'id_session.finished')->count())->toBe(SESSIONS_BEYOND_ONE_PAGE, 'every closed session leaves an audit trace');
});

it('cancels every open session of a client in one call even beyond one page', function (): void {
    $client = Client::factory()->synced()->create();
    insertExpiredOpenSessions($client, $this->agent, SESSIONS_BEYOND_ONE_PAGE, null);

    $cancelled = $this->engine->cancelOpenSessions($client, null, 'account_closed');

    expect($cancelled)->toBe(SESSIONS_BEYOND_ONE_PAGE)
        ->and(IdSession::query()->open()->where('client_id', $client->id)->count())->toBe(0, 'closing the account must not leave a session open');
});

it('refuses to start for a closed or unentitled client', function (): void {
    $closed = clientWithAnswers(5);
    $closed->close('test');

    expect(fn () => $this->engine->start($closed, $this->agent))->toThrow(IdentificationException::class);

    $unentitled = clientWithAnswers(5);
    $unentitled->forceFill(['explicit_package' => null, 'implicit_package' => null])->save();

    expect(fn () => $this->engine->start($unentitled, $this->agent))->toThrow(IdentificationException::class);
    expect(IdSession::query()->count())->toBe(0);
});

it('fails the session when the client is closed mid-session', function (): void {
    $client = clientWithAnswers(5);
    $session = $this->engine->start($client, $this->agent);
    $step = $this->engine->next($session);

    // Closing cancels the open session; a stale step must not decide anything afterwards.
    $client->close('test');

    expect($session->fresh()->status)->toBe(IdSessionStatus::Cancelled)
        ->and($session->fresh()->outcome_reason)->toBe('account_closed');
    expect(fn () => $this->engine->decide($step, StepVerdict::Accepted))->toThrow(IdentificationException::class);
    expect($session->fresh()->accepted_count)->toBe(0);

    // Losing the entitlement without a closure is caught at decision time.
    $other = clientWithAnswers(5);
    $session = $this->engine->start($other, $this->agent);
    $step = $this->engine->next($session);
    $other->forceFill(['explicit_package' => null, 'implicit_package' => null])->save();

    expect(fn () => $this->engine->decide($step, StepVerdict::Accepted))->toThrow(IdentificationException::class);
    expect($session->fresh()->status)->toBe(IdSessionStatus::Failed)
        ->and($session->fresh()->outcome_reason)->toBe('account_closed')
        ->and($session->fresh()->accepted_count)->toBe(0);
});

it('decides a step only once and never double counts', function (): void {
    $client = clientWithAnswers(5);
    $session = $this->engine->start($client, $this->agent);
    $step = $this->engine->next($session);
    $stale = IdSessionStep::query()->find($step->id);

    $this->engine->decide($step, StepVerdict::Accepted);

    expect(fn () => $this->engine->decide($stale, StepVerdict::Rejected))->toThrow(IdentificationException::class, 'already been decided');

    $session->refresh();
    expect($session->accepted_count)->toBe(1)
        ->and($session->rejected_count)->toBe(0)
        ->and($step->fresh()->verdict)->toBe(StepVerdict::Accepted)
        ->and(AuditLog::query()->where('event', 'id_session.answer_judged')->count())->toBe(1);
});

it('lets only the agent who opened the session drive it', function (): void {
    $client = clientWithAnswers(5);
    $other = User::factory()->create();
    $session = $this->engine->start($client, $this->agent);
    $step = $this->engine->next($session, $this->agent);

    expect(fn () => $this->engine->next($session, $other))->toThrow(IdentificationException::class);
    expect(fn () => $this->engine->decide($step, StepVerdict::Accepted, $other))->toThrow(IdentificationException::class);
    expect(fn () => $this->engine->cancel($session, agent: $other))->toThrow(IdentificationException::class);

    expect($session->fresh()->status)->toBe(IdSessionStatus::Open)
        ->and($step->fresh()->isPending())->toBeTrue();

    $this->engine->cancel($session, agent: $this->agent);
    expect($session->fresh()->status)->toBe(IdSessionStatus::Cancelled);
});

it('cancels the open session when the client is closed', function (): void {
    $client = clientWithAnswers(5);
    $session = $this->engine->start($client, $this->agent);
    $untouched = $this->engine->start(clientWithAnswers(5), $this->agent);

    $client->close('admin');

    expect($session->fresh()->status)->toBe(IdSessionStatus::Cancelled)
        ->and($session->fresh()->outcome_reason)->toBe('account_closed')
        ->and($untouched->fresh()->status)->toBe(IdSessionStatus::Open);
});

it('keeps a cancelled identification closed when an already loaded decision arrives', function (): void {
    $client = Client::factory()->synced()->create();
    $agent = User::factory()->create();
    foreach (Question::factory()->count(5)->create() as $question) {
        app(ClientAnswers::class)->save($client, $question, 'test answer');
    }
    $engine = app(QaSessionEngine::class);
    $session = $engine->start($client, $agent);
    $engine->decide($engine->next($session, $agent), StepVerdict::Accepted, $agent);
    $step = $engine->next($session->refresh(), $agent);
    $step->load('session.client');

    $client->fresh()->close('manual');
    expect($session->fresh()->status)->toBe(IdSessionStatus::Cancelled);
    expect(fn () => $engine->decide($step, StepVerdict::Accepted, $agent))->toThrow(IdentificationException::class);

    expect($session->fresh()->status)->toBe(IdSessionStatus::Cancelled)
        ->and($session->fresh()->accepted_count)->toBe(1)
        ->and($step->fresh()->verdict)->toBe(StepVerdict::Pending);
});

it('rechecks entitlement even when the decision already loaded the client', function (): void {
    $client = clientWithAnswers(5);
    $session = $this->engine->start($client, $this->agent);
    $step = $this->engine->next($session, $this->agent);
    $step->load('session.client');
    $client->update(['implicit_package' => null, 'explicit_package' => null]);

    expect(fn () => $this->engine->decide($step, StepVerdict::Accepted, $this->agent))->toThrow(IdentificationException::class);

    expect($session->fresh()->status)->toBe(IdSessionStatus::Failed)
        ->and($session->fresh()->accepted_count)->toBe(0)
        ->and($step->fresh()->verdict)->toBe(StepVerdict::Pending);
});

it('does not overwrite a finished session when an earlier cancellation arrives', function (): void {
    $session = $this->engine->start(clientWithAnswers(5), $this->agent);
    $stale = $session->fresh();
    $this->engine->decide($this->engine->next($session), StepVerdict::Accepted, $this->agent);
    $this->engine->decide($this->engine->next($session->refresh()), StepVerdict::Accepted, $this->agent);

    $this->engine->cancel($stale, agent: $this->agent);

    expect($session->fresh()->status)->toBe(IdSessionStatus::Passed)
        ->and(AuditLog::query()->where('event', 'id_session.finished')->where('subject_id', $session->id)->count())->toBe(1);
});

it('is not blocked by an open pin session and closes a session left open without a deadline', function (): void {
    $client = clientWithAnswers(5);
    $agent = User::factory()->create();

    // A PIN session is decided in the same request; one left open by a
    // failure has no deadline at all.
    $stuck = IdSession::factory()->create([
        'client_id' => $client->id,
        'agent_user_id' => User::factory()->create()->id,
        'method' => IdMethod::Pin,
        'status' => IdSessionStatus::Open,
        'expires_at' => null,
        'started_at' => now()->subHours(3),
    ]);

    $session = app(QaSessionEngine::class)->start($client, $agent);
    expect($session->method)->toBe(IdMethod::QuestionAnswer);

    expect(app(QaSessionEngine::class)->expireStale())->toBeGreaterThanOrEqual(1)
        ->and($stuck->fresh()->status)->toBe(IdSessionStatus::Expired);
});
