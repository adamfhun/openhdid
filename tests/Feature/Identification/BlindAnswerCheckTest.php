<?php

use App\Auth\Role;
use App\Enums\AnswerCheckResult;
use App\Enums\IdSessionStatus;
use App\Enums\StepVerdict;
use App\Filament\Admin\Pages\Identify;
use App\Identification\AgentAttemptLimiter;
use App\Identification\AnswerMatcher;
use App\Identification\ClientAnswers;
use App\Identification\IdentificationException;
use App\Identification\QaSessionEngine;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\IdSessionStep;
use App\Models\Question;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->agent = User::factory()->withRole(Role::Agent)->create();
    $this->actingAs($this->agent);
    Filament::setCurrentPanel('admin');
    app(Settings::class)->set(SettingKey::QaBlindCheck, true);

    // Every question carries the same answer, so whichever comes up, the typed text is known.
    $this->client = Client::factory()->synced()->create();
    foreach (Question::factory()->count(5)->create() as $question) {
        app(ClientAnswers::class)->save($this->client, $question, 'Árvíztűrő Tükörfúrógép');
    }
    $this->engine = app(QaSessionEngine::class);
});

it('compares answers ignoring case, accents, punctuation, spaces and compatibility forms', function (string $stored, string $given, AnswerCheckResult $expected, int $tolerance = 1): void {
    expect(app(AnswerMatcher::class)->compare($stored, $given, $tolerance)['result'])->toBe($expected);
})->with([
    'case, accents and spaces' => ['Árvíztűrő Tükörfúrógép', 'arvizturo-tukorfurogep', AnswerCheckResult::Matched],
    'NFKC: ligature and full-width letters' => ["Kiss \u{FB01}lep", 'ｋｉｓｓ filep', AnswerCheckResult::Matched],
    'one typo within the tolerance' => ['Budapest', 'Budapets', AnswerCheckResult::Matched],
    'swapped neighbours count once' => ['Debrecen', 'Debrceen', AnswerCheckResult::Matched],
    'no tolerance set' => ['Budapest', 'Budapets', AnswerCheckResult::Near, 0],
    'digits must be the same' => ['1985. május', '1986. május', AnswerCheckResult::Mismatch],
    'five letters allow a typo' => ['Bodri', 'Bodr', AnswerCheckResult::Matched],
    'a shorter answer must match exactly' => ['Rex', 'Rax', AnswerCheckResult::Near],
    'two typos beyond one allowed: close' => ['Szeged', 'Szaget', AnswerCheckResult::Near],
    'a different answer' => ['Szeged', 'Miskolc', AnswerCheckResult::Mismatch],
    'an empty answer' => ['Szeged', ' ?! ', AnswerCheckResult::Mismatch],
]);

it('keeps the stored answer hidden, accepts a matching typed answer and only then shows it', function (): void {
    $session = $this->engine->start($this->client, $this->agent);
    $step = $this->engine->next($session, $this->agent);

    expect($session->blind_check)->toBeTrue()
        ->and($session->typo_tolerance)->toBe(1)
        ->and($this->engine->revealAnswer($step))->toBeNull();

    $checked = $this->engine->check($step, 'arvizturo tukorfurogep', $this->agent);

    expect($checked->verdict)->toBe(StepVerdict::Accepted)
        ->and($checked->check_result)->toBe(AnswerCheckResult::Matched)
        ->and($checked->check_distance)->toBe(0)
        ->and($this->engine->revealAnswer($checked))->toBe('Árvíztűrő Tükörfúrógép');

    $this->engine->check($this->engine->next($session->fresh(), $this->agent), 'Árvíztűrő Tükörfúrógépp', $this->agent);
    expect($session->fresh()->status)->toBe(IdSessionStatus::Passed);
});

it('rejects a clearly different answer without showing it and counts it toward the hourly limit', function (): void {
    $session = $this->engine->start($this->client, $this->agent);
    $step = $this->engine->next($session, $this->agent);
    $before = app(AgentAttemptLimiter::class)->remaining($this->agent);

    $checked = $this->engine->check($step, 'Kutyafüle', $this->agent);

    expect($checked->verdict)->toBe(StepVerdict::Rejected)
        ->and($checked->check_result)->toBe(AnswerCheckResult::Mismatch)
        ->and($this->engine->revealAnswer($checked))->toBeNull()
        ->and(app(AgentAttemptLimiter::class)->remaining($this->agent))->toBe($before - 1)
        ->and($session->fresh()->rejected_count)->toBe(1);
});

it('gives a close answer one retry, then counts the question as cannot decide', function (): void {
    $session = $this->engine->start($this->client, $this->agent);
    $step = $this->engine->next($session, $this->agent);

    // Three edits against a tolerance of 1: close.
    $near = $this->engine->check($step, 'Árvíztűrő Tükörfúrógépxyz', $this->agent);
    expect($near->isPending())->toBeTrue()
        ->and($near->check_result)->toBe(AnswerCheckResult::Near)
        ->and($near->check_attempts)->toBe(1)
        ->and($this->engine->revealAnswer($near))->toBeNull();

    $again = $this->engine->check($near, 'Árvíztűrő Tükörfúrógépxyz', $this->agent);
    expect($again->verdict)->toBe(StepVerdict::Undecided)
        ->and($again->check_attempts)->toBe(2)
        ->and($this->engine->revealAnswer($again))->toBeNull();
});

it('refuses to accept or reject by hand in a blind session, cannot decide stays', function (): void {
    $session = $this->engine->start($this->client, $this->agent);
    $step = $this->engine->next($session, $this->agent);

    expect(fn () => $this->engine->decide($step, StepVerdict::Accepted, $this->agent))->toThrow(IdentificationException::class)
        ->and(fn () => $this->engine->decide($step, StepVerdict::Rejected, $this->agent))->toThrow(IdentificationException::class)
        ->and($step->fresh()->isPending())->toBeTrue();

    $this->engine->decide($step, StepVerdict::Undecided, $this->agent);
    expect($step->fresh()->verdict)->toBe(StepVerdict::Undecided);
});

it('never stores or logs the typed answer', function (): void {
    $session = $this->engine->start($this->client, $this->agent);
    $this->engine->check($this->engine->next($session, $this->agent), 'Titkos Tipp Szöveg', $this->agent);

    $logged = AuditLog::query()->get()->map(fn (AuditLog $log): string => json_encode($log->context))->implode("\n");
    $checked = AuditLog::query()->where('event', 'id_session.answer_checked')->sole()->context;

    expect($logged)->not->toContain('Titkos')
        ->and(json_encode(IdSessionStep::query()->get()->toArray()))->not->toContain('Titkos')
        ->and($checked)->toMatchArray(['result' => 'mismatch', 'attempt' => 1])
        ->and($checked)->toHaveKeys(['step_id', 'distance']);
});

it('keeps the mode of the session when the setting changes, and refuses checks in a session with the buttons', function (): void {
    $blind = $this->engine->start($this->client, $this->agent);
    app(Settings::class)->set(SettingKey::QaBlindCheck, false);

    expect($blind->fresh()->blind_check)->toBeTrue();
    $this->engine->cancel($blind, agent: $this->agent);

    $plain = $this->engine->start($this->client, $this->agent);
    $step = $this->engine->next($plain, $this->agent);

    expect($plain->blind_check)->toBeFalse()
        ->and($this->engine->revealAnswer($step))->toBe('Árvíztűrő Tükörfúrógép')
        ->and(fn () => $this->engine->check($step, 'Árvíztűrő Tükörfúrógép', $this->agent))->toThrow(IdentificationException::class);
});

it('refuses a check by another agent and once the hourly limit is used up', function (): void {
    $session = $this->engine->start($this->client, $this->agent);
    $step = $this->engine->next($session, $this->agent);
    $colleague = User::factory()->withRole(Role::Agent)->create();

    expect(fn () => $this->engine->check($step, 'Árvíztűrő Tükörfúrógép', $colleague))->toThrow(IdentificationException::class);

    app(Settings::class)->set(SettingKey::AgentAttemptsPerHour, 1);
    app(AgentAttemptLimiter::class)->hit($this->agent);

    expect(fn () => $this->engine->check($step, 'Árvíztűrő Tükörfúrógép', $this->agent))->toThrow(IdentificationException::class)
        ->and($step->fresh()->isPending())->toBeTrue();
});

it('runs the blind check on the identification page without ever rendering the hidden answer', function (): void {
    $page = Livewire::test(Identify::class, ['client' => $this->client->id])->call('startQa');

    $page->assertSee(__('What did the caller say?'))->assertSee(__('Check'))->assertSee(__('What counts as a match?'))
        ->assertDontSee('Árvíztűrő Tükörfúrógép')->assertDontSeeHtml("judge('accepted')");

    $page->call('judge', 'accepted')->assertNotified(__('In the blind check the system compares the answer you type; only "Cannot decide" can be chosen by hand.'));
    expect(IdSession::query()->sole()->accepted_count)->toBe(0);

    // A close answer stays on the question and says so under the field.
    $page->set('givenAnswer', 'Árvíztűrő Tükörfúrógépxyz')->call('checkAnswer')
        ->assertSet('givenAnswer', '')
        ->assertSee(__('Almost: ask the caller to say it again. One more try.'))
        ->assertDontSee('Árvíztűrő Tükörfúrógép');

    // Close again: the question stays undecided.
    $page->set('givenAnswer', 'Árvíztűrő Tükörfúrógépxyz')->call('checkAnswer')
        ->assertSee(__('The previous question stayed undecided.'));

    $page->set('givenAnswer', 'Teljesen más')->call('checkAnswer')
        ->assertSee(__('The previous answer did not match.'))
        ->assertDontSee('Árvíztűrő Tükörfúrógép');

    // One dot per question: undecided, rejected, and the current one.
    $page->assertSeeHtml('bg-gray-400')->assertSeeHtml('bg-danger-500')->assertSeeHtml('bg-primary-600');

    $page->set('givenAnswer', 'árvíztűrő tükörfúrógép')->call('checkAnswer')
        ->assertSee(trans_choice('{0} The previous answer matched: :answer|{1} The previous answer matched with 1 typo: :answer|[2,*] The previous answer matched with :n typos: :answer', 0, ['n' => 0, 'answer' => 'Árvíztűrő Tükörfúrógép']));
});
