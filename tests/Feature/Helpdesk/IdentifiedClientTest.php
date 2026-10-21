<?php

use App\Auth\Role;
use App\CallCenter\CallCenterService;
use App\Enums\CallStatus;
use App\Enums\IdMethod;
use App\Enums\IdSessionStatus;
use App\Enums\PhoneNumberSource;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\Identify;
use App\Filament\Admin\Pages\VerifyCode;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Widgets\OngoingCallsWidget;
use App\Identification\ClientAnswers;
use App\Identification\CurrentIdentification;
use App\Identification\MobileOtpService;
use App\Identification\PinService;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\Question;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->agent = User::factory()->withRole(Role::Agent)->create();
    $this->actingAs($this->agent);
    Filament::setCurrentPanel('admin');
    $this->client = Client::factory()->synced()->create(['name' => 'Kovács Anna']);
});

function passedSession(Client $client, User $agent, ?Call $call = null, array $attributes = []): IdSession
{
    return IdSession::factory()->create([
        'client_id' => $client->id,
        'agent_user_id' => $agent->id,
        'call_id' => $call?->id,
        'status' => IdSessionStatus::Passed,
        'method' => IdMethod::Manual,
        'started_at' => now(),
        'decided_at' => now(),
        ...$attributes,
    ]);
}

function recordUrl(Client $client): string
{
    return ClientResource::getUrl('view', ['record' => $client]);
}

it('counts a pass as current while its call is in the agent\'s hands, and for thirty minutes without one', function (): void {
    $current = app(CurrentIdentification::class);
    expect($current->forAgent($this->agent))->toBeNull();

    // Without a call: thirty minutes, then history.
    $session = passedSession($this->client, $this->agent, null, ['decided_at' => now()->subMinutes(29)]);
    expect($current->forAgent($this->agent)?->id)->toBe($session->id)
        ->and($current->guards($session, $this->agent))->toBeFalse();
    $session->forceFill(['decided_at' => now()->subMinutes(31)])->save();
    expect($current->forAgent($this->agent))->toBeNull();

    // On a live call the agent holds: current as long as the call lasts, and guarded.
    $call = Call::factory()->create(['client_id' => $this->client->id, 'agent_user_id' => $this->agent->id, 'status' => CallStatus::Active]);
    $onCall = passedSession($this->client, $this->agent, $call, ['decided_at' => now()->subHours(2)]);
    expect($current->forAgent($this->agent)?->id)->toBe($onCall->id)
        ->and($current->guards($onCall->fresh(['call']), $this->agent))->toBeTrue();

    // The call ends: no longer guarded, and after the window no longer current.
    $call->forceFill(['status' => CallStatus::Ended, 'ended_at' => now()])->save();
    expect($current->guards($onCall->fresh(['call']), $this->agent))->toBeFalse()
        ->and($current->forAgent($this->agent))->toBeNull();
});

it('does not count another agent\'s pass, a call moved to another client, or an unserved client', function (): void {
    $current = app(CurrentIdentification::class);
    $colleague = User::factory()->withRole(Role::Agent)->create();
    $call = Call::factory()->create(['client_id' => $this->client->id, 'agent_user_id' => $colleague->id, 'status' => CallStatus::Active]);
    passedSession($this->client, $colleague, $call);
    expect($current->forAgent($this->agent))->toBeNull();

    $own = Call::factory()->create(['client_id' => $this->client->id, 'agent_user_id' => $this->agent->id, 'status' => CallStatus::Active]);
    passedSession($this->client, $this->agent, $own);
    expect($current->forAgent($this->agent))->not->toBeNull();

    // The call now belongs to someone else: the pass vouches for nobody on it.
    $own->forceFill(['client_id' => Client::factory()->synced()->create()->id])->save();
    expect($current->forAgent($this->agent))->toBeNull();

    // Premium-only mode: a standard client is not served, so no pass of theirs is current.
    $own->forceFill(['client_id' => $this->client->id])->save();
    $this->client->forceFill(['implicit_package' => 'Basic', 'explicit_package' => null])->save();
    app(Settings::class)->set(SettingKey::PackagesPremiumOnly, true);
    expect($current->forAgent($this->agent))->toBeNull();
});

it('sends the agent to the client record after a passed question-and-answer session', function (): void {
    foreach (Question::factory()->count(5)->create() as $i => $question) {
        app(ClientAnswers::class)->save($this->client, $question, 'válasz '.$i);
    }

    Livewire::test(Identify::class, ['client' => $this->client->id])
        ->call('startQa')
        ->call('judge', 'accepted')
        ->assertNoRedirect()
        ->call('judge', 'accepted')
        ->assertRedirect(recordUrl($this->client));

    expect(IdSession::query()->first()->status)->toBe(IdSessionStatus::Passed);
});

it('sends the agent to the client record after a pin check and a manual identification', function (): void {
    app(Settings::class)->set(SettingKey::PinAgentVerificationEnabled, true);
    $this->client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Sync, 'verified_at' => now()]);
    app(PinService::class)->setPin($this->client, '123456');

    Livewire::test(Identify::class, ['client' => $this->client->id, 'method' => 'pin'])
        ->set('pin', '123456')
        ->call('verifyPin')
        ->assertRedirect(recordUrl($this->client));

    Livewire::test(Identify::class, ['client' => $this->client->id])
        ->callAction('manualIdentify', ['reason' => 'Callback to the registered number, approved by the team lead.'])
        ->assertRedirect(recordUrl($this->client));

    expect(IdSession::query()->where('status', IdSessionStatus::Passed)->count())->toBe(2);
});

it('sends the agent to the client record after a correct one-time code, bound to the call they hold', function (): void {
    $call = Call::factory()->create(['client_id' => null, 'agent_user_id' => $this->agent->id, 'status' => CallStatus::Active]);
    $code = app(MobileOtpService::class)->issue($this->client)['code'];

    // Opened from the menu, without a call in the address: the agent's own call is used.
    // Typing the last digit checks the code at once (live field).
    Livewire::test(VerifyCode::class)
        ->assertSet('call', $call->id)
        ->assertSee($call->callerNumber())
        ->set('data.code', $code)
        ->assertRedirect(recordUrl($this->client));

    $session = IdSession::query()->where('client_id', $this->client->id)->firstOrFail();
    expect($session->status)->toBe(IdSessionStatus::Passed)
        ->and($session->call_id)->toBe($call->id)
        ->and($call->fresh()->client_id)->toBe($this->client->id);
});

it('does not bind a code check to a call the agent does not hold', function (): void {
    Call::factory()->create(['agent_user_id' => User::factory()->create()->id, 'status' => CallStatus::Active]);
    Call::factory()->create(['agent_user_id' => $this->agent->id, 'status' => CallStatus::Ended, 'ended_at' => now()]);

    Livewire::test(VerifyCode::class)->assertSet('call', null);
});

it('heads the client record with the identification and guards it while the call is in progress', function (): void {
    $call = Call::factory()->create(['client_id' => $this->client->id, 'agent_user_id' => $this->agent->id, 'status' => CallStatus::Active, 'caller_number_e164' => '+36301234567']);
    passedSession($this->client, $this->agent, $call);

    Livewire::test(ViewClient::class, ['record' => $this->client->id])
        ->assertSee(__('Identified'))
        ->assertSee('+36301234567')
        ->assertSee('data-hdid-identified-guard', false)
        ->assertSee(__('Back to the call queue'))
        ->assertSee(__('Call note'))
        ->callAction('wrapUpNote', ['note' => 'Asked about the invoice.'])
        ->assertHasNoActionErrors();

    expect($call->fresh()->wrap_up_note)->toBe('Asked about the invoice.');

    // The call is over: the bar stays for a while, the guard goes.
    $call->forceFill(['status' => CallStatus::Ended, 'ended_at' => now()])->save();
    Livewire::test(ViewClient::class, ['record' => $this->client->id])
        ->assertSee(__('Identified'))
        ->assertDontSee('data-hdid-identified-guard', false);

    // Another client's record, or a colleague: nothing.
    Livewire::test(ViewClient::class, ['record' => Client::factory()->synced()->create()->id])
        ->assertDontSee(__('Back to the call queue'));
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    Livewire::test(ViewClient::class, ['record' => $this->client->id])
        ->assertDontSee(__('Back to the call queue'));
});

it('shows the way back to the identified client in the header of every staff page', function (): void {
    $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('data-hdid-identified-client', false);

    passedSession($this->client, $this->agent);

    $this->get(Dashboard::getUrl())->assertOk()
        ->assertSee('data-hdid-identified-client', false)
        ->assertSee('Kovács Anna')
        ->assertSee(recordUrl($this->client), false);
    $this->get(VerifyCode::getUrl())->assertOk()->assertSee('data-hdid-identified-client', false);

    $this->travel(31)->minutes();
    $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('data-hdid-identified-client', false);
});

it('guards the identification page while a question-and-answer session is open, and shows the record link after a pass', function (): void {
    foreach (Question::factory()->count(5)->create() as $i => $question) {
        app(ClientAnswers::class)->save($this->client, $question, 'válasz '.$i);
    }

    Livewire::test(Identify::class, ['client' => $this->client->id])
        ->assertDontSee('data-hdid-identified-guard', false)
        ->call('startQa')
        ->assertSee('data-hdid-identified-guard', false)
        ->assertSee(__('Leave the identification in progress?'));

    // Another client, identified on the call the agent holds: the record link and the guard.
    $identified = Client::factory()->synced()->create();
    $call = Call::factory()->create(['client_id' => $identified->id, 'agent_user_id' => $this->agent->id, 'status' => CallStatus::Active]);
    passedSession($identified, $this->agent, $call);

    Livewire::test(Identify::class, ['client' => $identified->id, 'call' => $call->id])
        ->assertSee(__('Open the client record'))
        ->assertSee(recordUrl($identified), false)
        ->assertSee(__('Leave the identified client?'))
        ->assertDontSee(__('Leave the identification in progress?'));
});

it('turns the dashboard button into the client record for the agent\'s own identified call, and refuses to take over an identified call', function (): void {
    $colleague = User::factory()->withRole(Role::Agent)->create();
    $own = Call::factory()->create(['client_id' => $this->client->id, 'agent_user_id' => $this->agent->id, 'status' => CallStatus::Active]);
    $theirs = Call::factory()->create(['client_id' => $this->client->id, 'agent_user_id' => $colleague->id, 'status' => CallStatus::Active]);
    $fresh = Call::factory()->create(['client_id' => $this->client->id, 'agent_user_id' => $this->agent->id, 'status' => CallStatus::Active]);
    passedSession($this->client, $this->agent, $own);
    passedSession($this->client, $colleague, $theirs);

    Livewire::test(OngoingCallsWidget::class)
        ->assertTableActionHasUrl('take', recordUrl($this->client), $own)
        ->assertTableActionHasLabel('take', __('Client record'), $own)
        ->assertTableActionHasLabel('take', __('Identify'), $fresh)
        ->assertTableActionDisabled('take', $theirs)
        ->assertTableActionHasLabel('take', __('Take over'), $theirs);

    expect(fn () => app(CallCenterService::class)->claim($theirs->fresh(), $this->agent, takeOver: true, takeOverFrom: $colleague->id))
        ->toThrow(ValidationException::class);
    expect($theirs->fresh()->agent_user_id)->toBe($colleague->id);
});
