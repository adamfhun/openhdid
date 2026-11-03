<?php

use App\Auth\Role;
use App\CallCenter\CallCenterService;
use App\Enums\CallStatus;
use App\Enums\IdMethod;
use App\Enums\IdSessionStatus;
use App\Enums\OneTimeCodePurpose;
use App\Identification\MobileOtpService;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/*
 * A code typed into the phone menu on a call of another client (owner
 * decision Q8): a call with no client gets the code's client; an ongoing,
 * unheld, unidentified call moves to it (the agent's way); any other call
 * keeps its client, the refusal is audited and the answer says so
 * (code_ok_call_kept).
 */

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(ThrottleRequests::class);
    config()->set('hdid.callcenter.api_key', 'key-123');
    config()->set('hdid.callcenter.hmac_secret', null);
    $this->agent = User::factory()->withRole(Role::Agent)->create();
    $this->anna = Client::factory()->synced()->create(['name' => 'Anna']);
    $this->bela = Client::factory()->synced()->create(['name' => 'Béla']);
});

function ivrCodeOn(string $callId, Client $client): TestResponse
{
    $code = app(MobileOtpService::class)->issue($client, null, OneTimeCodePurpose::IvrCode)['code'];

    return test()->withHeader('X-Api-Key', 'key-123')->postJson('/api/v1/callcenter/ivr/verify-code', ['call_id' => $callId, 'code' => $code])->assertOk();
}

function identifyOn(Call $call, Client $client): void
{
    IdSession::factory()->create(['client_id' => $client->id, 'agent_user_id' => $call->agent_user_id, 'call_id' => $call->id, 'method' => IdMethod::Pin, 'status' => IdSessionStatus::Passed, 'decided_at' => now()]);
}

it('keeps the client of a call it may not move, says so and audits the refusal', function (array $state, string $reason): void {
    $call = Call::factory()->create(['external_call_id' => 'c-1', 'client_id' => $this->anna->id] + $state + ['agent_user_id' => null, 'status' => CallStatus::Active]);
    if ($reason === 'identified') {
        identifyOn($call, $this->anna);
    }

    ivrCodeOn('c-1', $this->bela)->assertJsonPath('result', 'code_ok_call_kept')->assertJsonPath('identified', true)->assertJsonPath('client_id', $this->bela->id);

    expect($call->fresh()->client_id)->toBe($this->anna->id)
        ->and($call->fresh()->agent_user_id)->toBe($state['agent_user_id'] ?? null)
        ->and(AuditLog::query()->where('event', 'call.client_reassign_refused')->sole()->context)->toMatchArray(['from_client_id' => $this->anna->id, 'to_client_id' => $this->bela->id, 'reason' => $reason, 'channel' => 'ivr']);
})->with([
    'held, nobody identified yet' => [fn () => ['agent_user_id' => test()->agent->id], 'held'],
    'identified, nobody holds it' => [[], 'identified'],
    'ended' => [['status' => CallStatus::Ended, 'ended_at' => now()], 'ended'],
]);

it('moves an unheld, unidentified call to the client of an IVR code inside the call lock and records the reassignment', function (): void {
    $call = Call::factory()->create(['external_call_id' => 'c-2', 'client_id' => $this->anna->id, 'agent_user_id' => null, 'status' => CallStatus::Ringing]);
    $held = [];
    Event::listen('eloquent.updated: '.Call::class, function (Call $call) use (&$held): void {
        $probe = Cache::lock(CallCenterService::callLockName($call->external_call_id), 1);
        $free = $probe->get();
        if ($free) {
            $probe->release();
        }
        $held[] = ! $free;
    });

    ivrCodeOn('c-2', $this->bela)->assertJsonPath('result', 'code_ok');

    expect($call->fresh()->client_id)->toBe($this->bela->id)
        ->and($held)->not->toBeEmpty()->each->toBeTrue()
        ->and(AuditLog::query()->where('event', 'call.client_reassigned')->sole()->context)->toMatchArray(['from_client_id' => $this->anna->id, 'to_client_id' => $this->bela->id, 'channel' => 'ivr'])
        ->and($call->fresh()->isIdentified())->toBeTrue();
});

it('attaches the client of an IVR code to a held call that has no client yet', function (): void {
    $call = Call::factory()->create(['external_call_id' => 'c-3', 'client_id' => null, 'agent_user_id' => $this->agent->id, 'status' => CallStatus::Active]);

    ivrCodeOn('c-3', $this->bela)->assertJsonPath('result', 'code_ok');

    expect($call->fresh()->client_id)->toBe($this->bela->id)
        ->and(AuditLog::query()->where('event', 'call.client_attached')->sole()->context)->toMatchArray(['client_id' => $this->bela->id, 'channel' => 'ivr']);
});

it('does not let a pass kept by the phone menu identify the call after a later move', function (): void {
    $call = Call::factory()->create(['external_call_id' => 'c-4', 'client_id' => $this->anna->id, 'agent_user_id' => $this->agent->id, 'status' => CallStatus::Active]);
    ivrCodeOn('c-4', $this->bela)->assertJsonPath('result', 'code_ok_call_kept');

    app(CallCenterService::class)->reassignToClient($call->fresh(), $this->bela, $this->agent);

    expect($call->fresh()->client_id)->toBe($this->bela->id)
        ->and($call->fresh()->isIdentified())->toBeFalse();
});

it('does not tell the client they are identified when the identification was ended by a move', function (): void {
    IdSession::factory()->create(['client_id' => $this->anna->id, 'method' => IdMethod::MobileOtp, 'status' => IdSessionStatus::Passed, 'decided_at' => now(), 'voided_at' => now(), 'voided_reason' => 'call_moved']);

    expect(app(MobileOtpService::class)->recentIdentification($this->anna))->toBeNull();
});
