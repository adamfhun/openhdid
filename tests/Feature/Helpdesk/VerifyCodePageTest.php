<?php

use App\Auth\Role;
use App\CallCenter\CallCenterService;
use App\Enums\CallStatus;
use App\Enums\ClientTier;
use App\Enums\IdSessionStatus;
use App\Filament\Admin\Pages\VerifyCode;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Identification\MobileOtpService;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\User;
use App\Support\HuDate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->agent = User::factory()->withRole(Role::Agent)->create();
    $this->actingAs($this->agent);
    Filament::setCurrentPanel('admin');
});

it('identifies the client from a dictated code and attaches the call', function (): void {
    $client = Client::factory()->synced()->create();
    $call = Call::factory()->create(['client_id' => null, 'agent_user_id' => $this->agent->id]);
    $code = app(MobileOtpService::class)->issue($client)['code'];

    $this->get(VerifyCode::getUrl())->assertOk();

    // The last digit typed checks the code at once (live field); a wrong one empties the fields.
    Livewire::test(VerifyCode::class, ['call' => $call->id])
        ->set('data.code', '00000000')
        ->assertSet('failed', true)
        ->assertSet('data.code', '')
        ->assertNoRedirect()
        ->set('data.code', $code)
        ->assertSet('failed', false)
        // Identified: on to the client's record, which carries the "Identified" bar (AZO-07).
        ->assertRedirect(ClientResource::getUrl('view', ['record' => $client]));

    $session = IdSession::query()->where('client_id', $client->id)->first();
    expect($session->status)->toBe(IdSessionStatus::Passed)
        ->and($session->agent_user_id)->toBe($this->agent->id)
        ->and($session->call_id)->toBe($call->id)
        ->and($call->fresh()->client_id)->toBe($client->id);
});

it('is hidden from users without the identification permission', function (): void {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->get(VerifyCode::getUrl())->assertForbidden();
});

it('offers the way back to the queue on the client record after a successful check', function (): void {
    $client = Client::factory()->synced()->create();
    $code = app(MobileOtpService::class)->issue($client)['code'];

    // Typing the last digit checks the code at once (live field) and moves on to the record.
    Livewire::test(VerifyCode::class)
        ->assertSee(__('Codes requested by the mobile app are IVR codes: only the phone menu accepts them, this page does not.'))
        ->set('data.code', $code)
        ->assertRedirect(ClientResource::getUrl('view', ['record' => $client]));

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertSee(__('Identified'))
        ->assertSee(__('Back to the call queue'))
        ->assertSee(now()->format(HuDate::DATETIME));
});

it('refuses to attach a dictated code to a call held by another agent', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $actor = User::factory()->withRole(Role::Agent)->create();
    $holder = User::factory()->withRole(Role::Agent)->create();
    $previous = Client::factory()->synced()->create();
    $caller = Client::factory()->synced()->create();
    $call = Call::factory()->create(['agent_user_id' => $holder->id, 'client_id' => $previous->id, 'status' => CallStatus::Active]);
    $code = app(MobileOtpService::class)->issue($caller)['code'];
    $this->actingAs($actor);

    Livewire::test(VerifyCode::class, ['call' => $call->id])->assertForbidden();
    expect(fn () => app(CallCenterService::class)->verifyCodeForAgent($code, $actor, $call->id))->toThrow(AuthorizationException::class);
    expect(app(MobileOtpService::class)->current($caller)['code'])->toBe($code);

    expect($call->fresh()->client_id)->toBe($previous->id);
});

it('rechecks call ownership before consuming a code from an already open page', function (): void {
    $client = Client::factory()->synced()->create();
    $call = Call::factory()->create(['agent_user_id' => $this->agent->id]);
    $code = app(MobileOtpService::class)->issue($client)['code'];
    $page = Livewire::test(VerifyCode::class, ['call' => $call->id]);
    app(CallCenterService::class)->claim($call, User::factory()->withRole(Role::Agent)->create(), takeOver: true);

    $page->set('data.code', $code)->assertForbidden();

    expect(app(MobileOtpService::class)->current($client)['code'])->toBe($code)
        ->and(IdSession::query()->where('call_id', $call->id)->exists())->toBeFalse()
        ->and($call->fresh()->client_id)->toBeNull();
});

it('refuses code checks for unheld, unknown or unhandled-tier calls', function (string $scenario): void {
    $client = Client::factory()->synced()->create();
    $this->agent->update(['handles_tiers' => [ClientTier::Premium->value]]);
    $call = Call::factory()->create([
        'agent_user_id' => $scenario === 'unheld' ? null : $this->agent->id,
        'tier' => $scenario === 'tier' ? ClientTier::Standard : ClientTier::Premium,
    ]);
    $code = app(MobileOtpService::class)->issue($client)['code'];
    if ($scenario === 'unknown') {
        $call->delete();
    }

    Livewire::test(VerifyCode::class, ['call' => $call->id])->assertForbidden();
    expect(fn () => app(CallCenterService::class)->verifyCodeForAgent($code, $this->agent, $call->id))->toThrow(AuthorizationException::class);
    expect(app(MobileOtpService::class)->current($client)['code'])->toBe($code);
})->with(['unheld', 'unknown', 'tier']);

it('opens the verify code page for a missed call reached from the identification page', function (): void {
    $client = Client::factory()->synced()->create();
    // The identification page links to "Verify code" with the same call id.
    $missed = Call::factory()->create(['status' => CallStatus::Missed, 'client_id' => $client->id, 'agent_user_id' => null, 'ended_at' => now()]);

    Livewire::test(VerifyCode::class, ['call' => $missed->id])
        ->assertOk()
        ->assertSee($missed->callerNumber());
});

it('moves the call to the client of a one-time code through the reassignment, with its audit and the previous client\'s session closed', function (): void {
    $anna = Client::factory()->synced()->create(['name' => 'Anna']);
    $bela = Client::factory()->synced()->create(['name' => 'Béla']);
    $call = Call::factory()->create(['client_id' => $anna->id, 'agent_user_id' => $this->agent->id, 'status' => CallStatus::Active]);
    $open = IdSession::factory()->create(['client_id' => $anna->id, 'agent_user_id' => $this->agent->id, 'call_id' => $call->id, 'status' => IdSessionStatus::Open]);
    $code = app(MobileOtpService::class)->issue($bela)['code'];

    Livewire::test(VerifyCode::class, ['call' => $call->id])->set('data.code', $code)->assertRedirect();

    expect($call->fresh()->client_id)->toBe($bela->id)
        ->and(AuditLog::query()->where('event', 'call.client_reassigned')->where('subject_id', $call->id)->sole()->context)->toMatchArray(['from_client_id' => $anna->id, 'to_client_id' => $bela->id])
        ->and($open->fresh()->status)->toBe(IdSessionStatus::Cancelled)
        ->and($open->fresh()->outcome_reason)->toBe('call_reassigned');
});

it('says the call was taken over, as a notification, when a colleague took it after the page was opened', function (): void {
    $client = Client::factory()->synced()->create();
    $call = Call::factory()->create(['client_id' => null, 'agent_user_id' => $this->agent->id, 'status' => CallStatus::Active]);
    $code = app(MobileOtpService::class)->issue($client)['code'];
    $page = Livewire::test(VerifyCode::class, ['call' => $call->id]);

    $call->forceFill(['agent_user_id' => User::factory()->withRole(Role::Agent)->create()->id])->save();
    $page->set('data.code', $code)->assertNotified()->assertNoRedirect();

    expect(app(MobileOtpService::class)->current($client))->not->toBeNull('the code stays usable')
        ->and(IdSession::query()->exists())->toBeFalse();
});

it('refuses a one-time code on a call that is already identified and keeps the code usable', function (): void {
    $anna = Client::factory()->synced()->create();
    $bela = Client::factory()->synced()->create();
    $call = Call::factory()->create(['client_id' => $anna->id, 'agent_user_id' => $this->agent->id, 'status' => CallStatus::Active]);
    IdSession::factory()->create(['client_id' => $anna->id, 'agent_user_id' => $this->agent->id, 'call_id' => $call->id, 'status' => IdSessionStatus::Passed, 'decided_at' => now()]);
    $code = app(MobileOtpService::class)->issue($bela)['code'];

    Livewire::test(VerifyCode::class, ['call' => $call->id])->set('data.code', $code)->assertNotified()->assertNoRedirect();

    expect($call->fresh()->client_id)->toBe($anna->id)
        ->and(app(MobileOtpService::class)->current($bela))->not->toBeNull();
});
