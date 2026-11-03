<?php

use App\Auth\Role;
use App\CallCenter\CallCenterService;
use App\Enums\CallStatus;
use App\Enums\IdChannel;
use App\Enums\IdSessionStatus;
use App\Enums\OneTimeCodePurpose;
use App\Enums\PhoneNumberSource;
use App\Enums\StepVerdict;
use App\Identification\ClientAnswers;
use App\Identification\IdentificationException;
use App\Identification\ManualIdentification;
use App\Identification\MobileOtpService;
use App\Identification\PinService;
use App\Identification\QaSessionEngine;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\Question;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

/*
 * Every identification decision on a call runs under the call's lock
 * (CallLock), like the take-over and the release: a colleague taking the
 * call over, or the agent letting it go on another tab, cannot interleave
 * with a decision that would make the call identified.
 */

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(ThrottleRequests::class);
    config()->set('hdid.callcenter.api_key', 'key-123');
    config()->set('hdid.callcenter.hmac_secret', null);
    app(Settings::class)->set(SettingKey::PinAgentVerificationEnabled, true);
    $this->agent = User::factory()->withRole(Role::Agent)->create();
    $this->client = Client::factory()->synced()->withPin('123456')->create();
    $this->client->phoneNumbers()->create(['number_e164' => '+36301234500', 'source' => PhoneNumberSource::Sync, 'is_primary' => true]);
    $this->call = Call::factory()->create(['external_call_id' => 'c-lock', 'client_id' => $this->client->id, 'agent_user_id' => $this->agent->id, 'status' => CallStatus::Active, 'caller_number_e164' => '+36301234500']);
});

/**
 * Records, for every decision audit entry of a session on the call, whether
 * the call lock was held at that moment.
 *
 * @return Closure(): list<bool>
 */
function probeCallLockOnDecisions(Call $call): Closure
{
    $held = [];
    Event::listen('eloquent.created: '.AuditLog::class, function (AuditLog $entry) use ($call, &$held): void {
        if (! in_array($entry->event, ['id_session.finished', 'id_session.manual', 'id_session.answer_checked', 'id_session.answer_judged'], true)) {
            return;
        }

        if (IdSession::query()->whereKey($entry->subject_id)->value('call_id') !== $call->id) {
            return;
        }

        $probe = Cache::lock(CallCenterService::callLockName($call->external_call_id), 1);
        $free = $probe->get();
        if ($free) {
            $probe->release();
        }
        $held[] = ! $free;
    });

    return function () use (&$held): array {
        return $held;
    };
}

function clientAnswersAll(Client $client): void
{
    foreach (Question::factory()->count(6)->create() as $i => $question) {
        app(ClientAnswers::class)->save($client, $question, 'answer '.$i);
    }
}

it('decides every identification on a call while holding the call lock', function (string $path): void {
    $held = probeCallLockOnDecisions($this->call);

    match ($path) {
        'agent PIN' => app(PinService::class)->verify($this->client, '123456', IdChannel::Manual, $this->agent, $this->call),
        'manual' => app(ManualIdentification::class)->record($this->client, $this->agent, $this->call, 'Callback to the registered number.'),
        'one-time code' => app(CallCenterService::class)->verifyCodeForAgent(app(MobileOtpService::class)->issue($this->client, 'client')['code'], $this->agent, $this->call->id),
        'question and answer' => (function (): void {
            clientAnswersAll($this->client);
            app(Settings::class)->set(SettingKey::QaBlindCheck, false);
            $engine = app(QaSessionEngine::class);
            $session = $engine->start($this->client, $this->agent, $this->call);
            $engine->decide($engine->next($session, $this->agent), StepVerdict::Accepted, $this->agent);
        })(),
        'blind check' => (function (): void {
            clientAnswersAll($this->client);
            app(Settings::class)->set(SettingKey::QaBlindCheck, true);
            $engine = app(QaSessionEngine::class);
            $session = $engine->start($this->client, $this->agent, $this->call);
            $engine->check($engine->next($session, $this->agent), 'wrong answer entirely', $this->agent);
        })(),
        'IVR PIN' => $this->withHeader('X-Api-Key', 'key-123')->postJson('/api/v1/callcenter/ivr/verify-pin', ['call_id' => 'c-lock', 'pin' => '123456'])->assertOk(),
        'IVR code' => $this->withHeader('X-Api-Key', 'key-123')->postJson('/api/v1/callcenter/ivr/verify-code', ['call_id' => 'c-lock', 'code' => app(MobileOtpService::class)->issue($this->client, null, OneTimeCodePurpose::IvrCode)['code']])->assertOk(),
    };

    expect($held())->not->toBeEmpty()->each->toBeTrue();
})->with(['agent PIN', 'manual', 'one-time code', 'question and answer', 'blind check', 'IVR PIN', 'IVR code']);

it('does not turn a PIN session closed during the check into a pass', function (): void {
    $hasher = app('hash')->driver();
    Hash::partialMock()->shouldReceive('check')->andReturnUsing(function (string $value, string $hash) use ($hasher): bool {
        // What a take-over's cancelCallSessions does to the open session meanwhile.
        IdSession::query()->where('call_id', $this->call->id)->update(['status' => IdSessionStatus::Cancelled, 'outcome_reason' => 'call_taken_over']);

        return $hasher->check($value, $hash);
    });

    $session = app(PinService::class)->verify($this->client, '123456', IdChannel::Manual, $this->agent, $this->call);

    expect($session->fresh()->status)->toBe(IdSessionStatus::Cancelled)
        ->and(AuditLog::query()->where('event', 'id_session.finished')->where('context->status', 'passed')->exists())->toBeFalse()
        ->and($this->call->fresh()->isIdentified())->toBeFalse();
});

it('refuses a PIN check and a manual identification on a call a colleague took after the page was loaded', function (string $path): void {
    $stale = $this->call->fresh();
    $this->call->forceFill(['agent_user_id' => User::factory()->withRole(Role::Agent)->create()->id])->save();

    expect(fn () => $path === 'PIN'
        ? app(PinService::class)->verify($this->client, '123456', IdChannel::Manual, $this->agent, $stale)
        : app(ManualIdentification::class)->record($this->client, $this->agent, $stale, 'Callback to the registered number.'))->toThrow(IdentificationException::class)
        ->and(IdSession::query()->where('call_id', $this->call->id)->exists())->toBeFalse();
})->with(['PIN', 'manual']);

it('answers the phone menu with a retry when the call is busy, not with a server error', function (string $uri, array $payload): void {
    Cache::lock(CallCenterService::callLockName('c-lock'), 30)->get();

    $this->withHeader('X-Api-Key', 'key-123')->postJson($uri, $payload)
        ->assertStatus(503)->assertHeader('Retry-After');

    expect(IdSession::query()->exists())->toBeFalse();
})->with([
    'PIN' => ['/api/v1/callcenter/ivr/verify-pin', ['call_id' => 'c-lock', 'pin' => '123456']],
    'code' => ['/api/v1/callcenter/ivr/verify-code', ['call_id' => 'c-lock', 'code' => '12345678']],
]);

it('checks the phone menu PIN of the client the call has when the check runs', function (): void {
    $other = Client::factory()->synced()->withPin('654321')->create();
    // The call moves to another client right after the phone menu's request read it.
    $moved = false;
    Call::retrieved(function (Call $call) use (&$moved, $other): void {
        if (! $moved) {
            $moved = true;
            Call::query()->whereKey($call->id)->update(['client_id' => $other->id]);
        }
    });

    $result = app(CallCenterService::class)->ivrVerifyPin('c-lock', '123456');
    Call::flushEventListeners();

    expect($result->result)->toBe('unknown_caller')
        ->and(IdSession::query()->where('client_id', $this->client->id)->where('call_id', $this->call->id)->exists())->toBeFalse();
});

it('refuses a manual identification whose reason is shorter than ten characters without the surrounding spaces', function (): void {
    expect(fn () => app(ManualIdentification::class)->record($this->client, $this->agent, null, '    rövid    '))->toThrow(IdentificationException::class)
        ->and(IdSession::query()->exists())->toBeFalse();
});
