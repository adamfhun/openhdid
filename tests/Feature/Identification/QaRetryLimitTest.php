<?php

use App\Auth\Role;
use App\Enums\CallStatus;
use App\Enums\IdChannel;
use App\Enums\IdMethod;
use App\Enums\IdSessionStatus;
use App\Enums\StepVerdict;
use App\Filament\Admin\Pages\Identify;
use App\Filament\Admin\Pages\ManageSettings;
use App\Identification\AgentAttemptLimiter;
use App\Identification\ClientAnswers;
use App\Identification\IdentificationException;
use App\Identification\QaRetryLimit;
use App\Identification\QaSessionEngine;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\Question;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Support\HuDate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;

/**
 * F08: question-and-answer identification could be retried without end. A
 * client may have a set number of failed or undecided sessions within a
 * period (3 in 24 hours by default); then a new session waits, and staff
 * with the client management permission can lift the lock on the
 * identification page. The agents' hourly limit is not touched.
 *
 * D2: a cancelled or expired session counts as well once a question was
 * shown in it and not accepted; one closed before any question does not.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->engine = app(QaSessionEngine::class);
    $this->agentA = User::factory()->withRole(Role::Agent)->create(['name' => 'Agent A']);
    $this->agentB = User::factory()->withRole(Role::Agent)->create(['name' => 'Agent B']);

    $questions = Question::factory()->count(5)->create();
    $this->client = Client::factory()->synced()->create(['name' => 'Kovács Anna']);
    foreach ($questions as $i => $question) {
        app(ClientAnswers::class)->save($this->client, $question, 'answer '.$i);
    }
});

/**
 * Starts a session for the client and rejects two answers: failed.
 */
function failQaSessionOf(QaSessionEngine $engine, Client $client, User $agent, ?Call $call = null): IdSession
{
    $session = $engine->start($client, $agent, $call);
    $engine->decide($engine->next($session, $agent), StepVerdict::Rejected, $agent);

    return $engine->decide($engine->next($session->refresh(), $agent), StepVerdict::Rejected, $agent);
}

/**
 * Starts a session and answers "cannot decide" until passing is out of reach: undecided.
 */
function undecideQaSessionOf(QaSessionEngine $engine, Client $client, User $agent): IdSession
{
    $session = $engine->start($client, $agent);

    while ($session->isOpen()) {
        $session = $engine->decide($engine->next($session->refresh(), $agent), StepVerdict::Undecided, $agent);
    }

    return $session;
}

function heldCallOf(User $agent, Client $client): Call
{
    return Call::factory()->create(['client_id' => $client->id, 'agent_user_id' => $agent->id, 'status' => CallStatus::Active, 'answered_at' => now()]);
}

it('refuses a new question-and-answer session once the client had three unsuccessful ones within a day, whichever agent asks', function (): void {
    expect(failQaSessionOf($this->engine, $this->client, $this->agentA)->status)->toBe(IdSessionStatus::Failed)
        ->and(failQaSessionOf($this->engine, $this->client, $this->agentB)->status)->toBe(IdSessionStatus::Failed)
        ->and(undecideQaSessionOf($this->engine, $this->client, $this->agentA)->status)->toBe(IdSessionStatus::Undecided);

    expect(fn () => $this->engine->start($this->client, $this->agentA, heldCallOf($this->agentA, $this->client)))
        ->toThrow(IdentificationException::class, 'locked for this client until');
    expect(fn () => $this->engine->start($this->client, $this->agentB))
        ->toThrow(IdentificationException::class, 'locked for this client until');

    expect(IdSession::query()->where('client_id', $this->client->id)->count())->toBe(3);

    $refusals = AuditLog::query()->where('event', 'id_session.qa_refused')->where('subject_id', $this->client->id)->get();
    expect($refusals)->toHaveCount(2)
        ->and($refusals->first()->context)->toMatchArray(['count' => 3, 'limit' => 3, 'window_hours' => 24])
        ->and($refusals->pluck('actor_id')->all())->toBe([$this->agentA->id, $this->agentB->id]);

    // The agents' own hourly limit is a separate rule and stays as it was.
    $limiter = app(AgentAttemptLimiter::class);
    expect($limiter->remaining($this->agentA))->toBe(50)->and($limiter->remaining($this->agentB))->toBe(50);
});

it('lets a new session start once the oldest unsuccessful one has left the period', function (): void {
    $this->travelTo(now()->startOfMinute());
    $first = now()->toImmutable();

    failQaSessionOf($this->engine, $this->client, $this->agentA);
    $this->travel(1)->hours();
    failQaSessionOf($this->engine, $this->client, $this->agentA);
    $this->travel(1)->hours();
    failQaSessionOf($this->engine, $this->client, $this->agentA);

    $this->travelTo($first->addHours(23));
    $until = app(QaRetryLimit::class)->lockedUntil($this->client);
    expect($until?->format(HuDate::DATETIME))->toBe($first->addHours(24)->format(HuDate::DATETIME));
    expect(fn () => $this->engine->start($this->client, $this->agentA))
        ->toThrow(IdentificationException::class, $first->addHours(24)->format(HuDate::DATETIME));

    $this->travelTo($first->addHours(24)->addMinute());
    expect(app(QaRetryLimit::class)->lockedUntil($this->client))->toBeNull()
        ->and($this->engine->start($this->client, $this->agentA)->isOpen())->toBeTrue();
});

it('does not count passed sessions, sessions closed before any question was shown, or other methods', function (): void {
    // Passed sessions, sessions cancelled or expired before any question, and failed PIN checks, leave the count alone.
    $passed = $this->engine->start($this->client, $this->agentA);
    $this->engine->decide($this->engine->next($passed, $this->agentA), StepVerdict::Accepted, $this->agentA);
    $this->engine->decide($this->engine->next($passed->refresh(), $this->agentA), StepVerdict::Accepted, $this->agentA);
    $this->engine->cancel($this->engine->start($this->client, $this->agentA), agent: $this->agentA);
    $expired = $this->engine->start($this->client, $this->agentA);
    $expired->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->engine->expireStale();
    IdSession::query()->forceCreate([
        'client_id' => $this->client->id, 'agent_user_id' => $this->agentA->id, 'channel' => IdChannel::Ivr,
        'method' => IdMethod::Pin, 'status' => IdSessionStatus::Failed, 'outcome_reason' => 'wrong_pin', 'started_at' => now(), 'decided_at' => now(),
    ]);
    failQaSessionOf($this->engine, $this->client, $this->agentA);
    failQaSessionOf($this->engine, $this->client, $this->agentA);

    $limit = app(QaRetryLimit::class);
    expect($limit->unsuccessfulCount($this->client))->toBe(2)
        ->and($limit->lockedUntil($this->client))->toBeNull()
        ->and($limit->recentUnsuccessfulAttempts($this->client))->toBe(3)
        ->and($this->engine->start($this->client, $this->agentA)->isOpen())->toBeTrue();
});

it('follows the configured number of sessions and period', function (): void {
    app(Settings::class)->setMany([
        SettingKey::QaMaxUnsuccessfulSessions->value => 1,
        SettingKey::QaUnsuccessfulWindowHours->value => 2,
    ]);

    failQaSessionOf($this->engine, $this->client, $this->agentA);
    expect(fn () => $this->engine->start($this->client, $this->agentB))->toThrow(IdentificationException::class);

    $this->travel(121)->minutes();
    expect($this->engine->start($this->client, $this->agentB)->isOpen())->toBeTrue();
});

it('counts the unsuccessful sessions inside the client lock that guards the start', function (): void {
    foreach (range(1, 2) as $i) {
        failQaSessionOf($this->engine, $this->client, $this->agentA);
    }

    $lockWasFree = null;
    DB::listen(function ($query) use (&$lockWasFree): void {
        if ($lockWasFree !== null || ! str_contains($query->sql, 'id_sessions') || ! str_contains($query->sql, 'decided_at')) {
            return;
        }

        $lock = Cache::lock('hdid:qa:'.$this->client->id, 10);
        $lockWasFree = $lock->get();

        if ($lockWasFree) {
            $lock->release();
        }
    });

    $this->engine->start($this->client, $this->agentB);

    expect($lockWasFree)->toBeFalse('the unsuccessful sessions are counted while the client\'s start lock is held');
});

it('shows the client\'s recent unsuccessful attempts and the lock on the identification page, also on a new call', function (): void {
    foreach ([$this->agentB, $this->agentB, $this->agentB] as $agent) {
        failQaSessionOf($this->engine, $this->client, $agent, heldCallOf($agent, $this->client));
    }

    $this->actingAs($this->agentA);
    $call = heldCallOf($this->agentA, $this->client);
    $until = app(QaRetryLimit::class)->lockedUntil($this->client)->format(HuDate::DATETIME);

    $page = Livewire::test(Identify::class, ['client' => $this->client->id, 'call' => $call->id])
        ->assertSee('Unsuccessful attempts of this client in the last 24 hours, on any call: 3')
        ->assertSee('Question-and-answer is locked for this client until '.$until)
        ->assertSee('No attempts on this call yet.')
        ->assertActionHidden('unlockQa');

    $page->call('startQa')->assertNotified();
    // The hidden action cannot be run by a direct call either.
    $page->call('mountAction', 'unlockQa')->call('callMountedAction');

    expect(IdSession::query()->where('client_id', $this->client->id)->count())->toBe(3)
        ->and(app(QaRetryLimit::class)->lockedUntil($this->client))->not->toBeNull('an agent without the client management permission cannot lift the lock');
});

it('lets staff with the client management permission lift the lock on the identification page', function (): void {
    foreach (range(1, 3) as $i) {
        failQaSessionOf($this->engine, $this->client, $this->agentA);
    }

    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin);

    Livewire::test(Identify::class, ['client' => $this->client->id])
        ->assertActionVisible('unlockQa')
        ->callAction('unlockQa')
        ->assertNotified()
        ->assertDontSee('Question-and-answer is locked for this client until');

    $unlocked = AuditLog::query()->where('event', 'client.qa_unlocked')->where('subject_id', $this->client->id)->sole();
    expect($unlocked->actor_id)->toBe($admin->id)
        ->and($unlocked->context)->toMatchArray(['count' => 3, 'limit' => 3])
        ->and($this->engine->start($this->client, $this->agentA)->isOpen())->toBeTrue('the sessions before the unlock no longer count');
});

it('validates the retry limit settings on the settings page', function (): void {
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create());

    foreach ([[SettingKey::QaMaxUnsuccessfulSessions, 0], [SettingKey::QaMaxUnsuccessfulSessions, 21], [SettingKey::QaUnsuccessfulWindowHours, 0], [SettingKey::QaUnsuccessfulWindowHours, 169]] as [$key, $value]) {
        Livewire::test(ManageSettings::class)
            ->fillForm([ManageSettings::fieldName($key) => $value])
            ->call('save')
            ->assertHasFormErrors([ManageSettings::fieldName($key)]);
    }

    Livewire::test(ManageSettings::class)
        ->fillForm([
            ManageSettings::fieldName(SettingKey::QaMaxUnsuccessfulSessions) => 5,
            ManageSettings::fieldName(SettingKey::QaUnsuccessfulWindowHours) => 12,
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect(app(Settings::class)->int(SettingKey::QaMaxUnsuccessfulSessions))->toBe(5)
        ->and(app(Settings::class)->int(SettingKey::QaUnsuccessfulWindowHours))->toBe(12)
        ->and(SettingKey::QaMaxUnsuccessfulSessions->default())->toBe(3)
        ->and(SettingKey::QaUnsuccessfulWindowHours->default())->toBe(24);
});

/**
 * Starts a session, shows one question and leaves it unjudged, then cancels
 * the session: the caller hung up on a question they did not know.
 */
function cancelQaSessionOnShownQuestionOf(QaSessionEngine $engine, Client $client, User $agent): IdSession
{
    $session = $engine->start($client, $agent);
    $engine->next($session, $agent);

    return $engine->cancel($session->refresh(), agent: $agent);
}

/**
 * Starts a session, shows one question and lets the session run out.
 */
function expireQaSessionOnShownQuestionOf(QaSessionEngine $engine, Client $client, User $agent): IdSession
{
    $session = $engine->start($client, $agent);
    $engine->next($session, $agent);
    $session->refresh()->forceFill(['expires_at' => now()->subMinute()])->save();
    $engine->expireStale();

    return $session->refresh();
}

it('counts a cancelled question-and-answer session in which a shown question was not accepted', function (): void {
    // D2: hanging up on an unknown question and cancelling must not be a free retry.
    expect(cancelQaSessionOnShownQuestionOf($this->engine, $this->client, $this->agentA)->status)->toBe(IdSessionStatus::Cancelled);

    // A rejected answer before the cancellation counts as well.
    $rejected = $this->engine->start($this->client, $this->agentB);
    $this->engine->decide($this->engine->next($rejected, $this->agentB), StepVerdict::Rejected, $this->agentB);
    $this->engine->cancel($rejected->refresh(), agent: $this->agentB);

    // So does a session the call took away while a question was on screen.
    $call = heldCallOf($this->agentA, $this->client);
    $released = $this->engine->start($this->client, $this->agentA, $call);
    $this->engine->next($released, $this->agentA);
    $this->engine->cancelCallSessions($call, $this->agentA, 'call_released');

    $limit = app(QaRetryLimit::class);
    expect($limit->unsuccessfulCount($this->client))->toBe(3)
        ->and($limit->recentUnsuccessfulAttempts($this->client))->toBe(3)
        ->and($limit->lockedUntil($this->client))->not->toBeNull();

    expect(fn () => $this->engine->start($this->client, $this->agentB))
        ->toThrow(IdentificationException::class, 'cancelled or expired with a shown question that was not accepted');
});

it('does not count a session closed before any question was shown, or one whose shown questions were all accepted', function (): void {
    foreach (range(1, 3) as $i) {
        $this->engine->cancel($this->engine->start($this->client, $this->agentA), agent: $this->agentA);

        $accepted = $this->engine->start($this->client, $this->agentA);
        $this->engine->decide($this->engine->next($accepted, $this->agentA), StepVerdict::Accepted, $this->agentA);
        $this->engine->cancel($accepted->refresh(), agent: $this->agentA);

        $unasked = $this->engine->start($this->client, $this->agentA);
        $unasked->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->engine->expireStale();
    }

    $limit = app(QaRetryLimit::class);
    expect(IdSession::query()->where('client_id', $this->client->id)->whereIn('status', [IdSessionStatus::Cancelled, IdSessionStatus::Expired])->count())->toBe(9)
        ->and($limit->unsuccessfulCount($this->client))->toBe(0)
        ->and($limit->recentUnsuccessfulAttempts($this->client))->toBe(0)
        ->and($this->engine->start($this->client, $this->agentA)->isOpen())->toBeTrue();
});

it('counts an expired question-and-answer session with a shown question that was not accepted', function (): void {
    expect(expireQaSessionOnShownQuestionOf($this->engine, $this->client, $this->agentA)->status)->toBe(IdSessionStatus::Expired);

    // Expiry noticed by the next step, not by the scheduler, counts the same way.
    $late = $this->engine->start($this->client, $this->agentA);
    $this->engine->next($late, $this->agentA);
    $late->refresh()->forceFill(['expires_at' => now()->subMinute()])->save();
    expect(fn () => $this->engine->next($late->refresh(), $this->agentA))->toThrow(IdentificationException::class);
    expect($late->refresh()->status)->toBe(IdSessionStatus::Expired);

    expireQaSessionOnShownQuestionOf($this->engine, $this->client, $this->agentB);

    expect(app(QaRetryLimit::class)->unsuccessfulCount($this->client))->toBe(3)
        ->and(fn () => $this->engine->start($this->client, $this->agentA))->toThrow(IdentificationException::class, 'locked for this client until');
});

it('does not count a session cancelled because the client\'s account was closed', function (): void {
    $session = $this->engine->start($this->client, $this->agentA);
    $this->engine->next($session, $this->agentA);
    $this->engine->cancelOpenSessions($this->client, null, 'account_closed');

    expect($session->refresh()->status)->toBe(IdSessionStatus::Cancelled)
        ->and(app(QaRetryLimit::class)->unsuccessfulCount($this->client))->toBe(0);
});

it('shows on the identification page that cancelled and expired sessions with an unaccepted question count, and disables the start with the reason', function (): void {
    // Cancelled before any question: listed, but not counted.
    $this->engine->cancel($this->engine->start($this->client, $this->agentB), agent: $this->agentB);
    cancelQaSessionOnShownQuestionOf($this->engine, $this->client, $this->agentB);
    cancelQaSessionOnShownQuestionOf($this->engine, $this->client, $this->agentB);
    expireQaSessionOnShownQuestionOf($this->engine, $this->client, $this->agentB);

    $this->actingAs($this->agentA);

    Livewire::test(Identify::class, ['client' => $this->client->id])
        ->assertSee('Unsuccessful attempts of this client in the last 24 hours, on any call: 3')
        ->assertSee('Question-and-answer is locked for this client until')
        ->assertSee('cancelled or expired with a shown question that was not accepted')
        ->assertSeeHtml('data-hdid-qa-locked')
        ->assertSee('Unsuccessful attempt: cancelled after a question was shown and not accepted.')
        ->assertSee('Unsuccessful attempt: expired after a question was shown and not accepted.')
        ->call('startQa')
        ->assertNotified();

    expect(substr_count(
        Livewire::test(Identify::class, ['client' => $this->client->id])->html(),
        'data-hdid-qa-counted',
    ))->toBe(3, 'the session cancelled before any question carries no note');

    expect(IdSession::query()->where('client_id', $this->client->id)->count())->toBe(4);

    // The note helper takes a session: it must not be callable from the browser.
    expect(fn () => Livewire::test(Identify::class, ['client' => $this->client->id])->call('getAttemptNote', IdSession::query()->value('id')))
        ->toThrow(MethodNotFoundException::class);
});

it('lifts the lock when the cache hands the unlock mark back as text, as Redis does', function (): void {
    foreach (range(1, 3) as $i) {
        failQaSessionOf($this->engine, $this->client, $this->agentA);
    }
    $limit = app(QaRetryLimit::class);
    expect($limit->lockedUntil($this->client))->not->toBeNull();

    $this->travel(1)->seconds();
    // The Redis store keeps a number as a raw value and returns it as a string.
    Cache::put('hdid:qa-unlocked:'.$this->client->id, (string) now()->getTimestamp(), now()->addDay());

    expect($limit->lockedUntil($this->client))->toBeNull()
        ->and($limit->unsuccessfulCount($this->client))->toBe(0);
});

it('reports a lock that the unlock could not lift', function (): void {
    foreach (range(1, 3) as $i) {
        failQaSessionOf($this->engine, $this->client, $this->agentA);
    }
    $admin = User::factory()->withRole(Role::Admin)->create();
    // A cache that keeps nothing: the unlock mark is lost at once.
    config()->set('cache.default', 'null');

    expect(app(QaRetryLimit::class)->unlock($this->client, $admin))->toBeFalse()
        ->and(app(QaRetryLimit::class)->lockedUntil($this->client))->not->toBeNull();
});

it('counts a session cancelled right after the start on the identification page, as its help says', function (): void {
    $this->actingAs($this->agentA);
    $call = heldCallOf($this->agentA, $this->client);

    $page = Livewire::test(Identify::class, ['client' => $this->client->id, 'call' => $call->id])
        ->call('startQa')
        // The first question shows with the start.
        ->assertSee(__('Cancel session'))
        ->assertSeeHtml('since this question was shown and not accepted')
        ->call('cancelQa');

    expect(app(QaRetryLimit::class)->unsuccessfulCount($this->client))->toBe(1);
    $page->call('$refresh')->assertSee('Unsuccessful attempt: cancelled after a question was shown and not accepted.');

    expect(SettingKey::QaMaxUnsuccessfulSessions->details())->toContain('the first question shows as soon as the session starts');
});
