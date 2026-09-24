<?php

use App\Auth\Permission;
use App\Auth\Role;
use App\Enums\CallStatus;
use App\Enums\IdMethod;
use App\Enums\IdSessionStatus;
use App\Enums\PhoneNumberSource;
use App\Filament\Admin\Pages\Identify;
use App\Filament\Admin\Pages\SearchClients;
use App\Filament\Admin\Pages\VerifyCode;
use App\Filament\Admin\Resources\Calls\Pages\ManageCalls;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Filament\Admin\Widgets\MissedCallsWidget;
use App\Filament\Admin\Widgets\OngoingCallsWidget;
use App\Identification\ClientAnswers;
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
use App\Support\HuDate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->agent = User::factory()->withRole(Role::Agent)->create();
    $this->actingAs($this->agent);
    Filament::setCurrentPanel('admin');

    $questions = Question::factory()->count(5)->create();
    $this->client = Client::factory()->synced()->create(['name' => 'Kovács Anna']);
    foreach ($questions as $i => $q) {
        app(ClientAnswers::class)->save($this->client, $q, 'válasz '.$i);
    }
});

it('runs a full q-a session from the page', function (): void {
    $page = Livewire::test(Identify::class, ['client' => $this->client->id])
        ->assertSee('Kovács Anna')
        ->assertSee('5 / 5 answers')
        ->call('startQa');

    $session = IdSession::query()->first();
    expect($session)->not->toBeNull()->and($page->get('sessionId'))->toBe($session->id);

    $page->assertSee('Stored answer')->assertSee('válasz ');
    $page->call('judge', 'accepted')->call('judge', 'accepted');

    expect($session->fresh()->status)->toBe(IdSessionStatus::Passed);
    $page->assertSee('Identified')->assertDontSee('Stored answer');
});

it('attaches the client to the call and claims it from the dashboard', function (): void {
    $call = Call::factory()->create(['caller_number_e164' => '+36301111111']);

    Livewire::test(OngoingCallsWidget::class)
        ->assertSee('+36301111111')
        ->assertSee('Unknown caller')
        ->callTableAction('take', $call)
        ->assertRedirect(SearchClients::getUrl(['call' => $call->id]));

    expect($call->fresh()->agent_user_id)->toBe($this->agent->id);

    Livewire::test(Identify::class, ['client' => $this->client->id, 'call' => $call->id])->assertSee('Call');
    expect($call->fresh()->client_id)->toBe($this->client->id);
});

it('offers the pin method only when enabled and set', function (): void {
    Livewire::test(Identify::class, ['client' => $this->client->id])
        ->call('chooseMethod', 'pin')
        ->assertSee('Agents may not verify PINs');

    app(Settings::class)->set(SettingKey::PinAgentVerificationEnabled, true);
    app(PinService::class)->setPin($this->client, '123456');

    Livewire::test(Identify::class, ['client' => $this->client->id])
        ->call('chooseMethod', 'pin')
        ->assertSee('PIN told by the caller')
        ->set('pin', '123456')
        ->call('verifyPin')
        ->assertSee('Identified');

    expect(IdSession::query()->where('method', 'pin')->where('channel', 'manual')->first()->status)->toBe(IdSessionStatus::Passed);
});

it('lists missed calls with an identify link and lets the agent mark them handled', function (): void {
    $missed = Call::factory()->create(['status' => CallStatus::Missed, 'client_id' => $this->client->id, 'ended_at' => now()]);
    Call::factory()->create(['status' => CallStatus::Ended, 'agent_user_id' => $this->agent->id, 'ended_at' => now()]);

    Livewire::test(MissedCallsWidget::class)
        ->assertCanSeeTableRecords([$missed])
        ->assertCountTableRecords(1)
        ->assertSee('Kovács Anna')
        ->callTableAction('handled', $missed);

    expect($missed->fresh()->handled_at)->not->toBeNull();
    Livewire::test(MissedCallsWidget::class)->assertCountTableRecords(0);
});

it('links a manual identification started from the missed-calls list to that call', function (): void {
    $missed = Call::factory()->create(['status' => CallStatus::Missed, 'client_id' => $this->client->id, 'ended_at' => now()]);

    Livewire::test(MissedCallsWidget::class)
        ->assertCanSeeTableRecords([$missed])
        ->assertTableActionHasUrl('identify', Identify::getUrl(['client' => $this->client->id, 'call' => $missed->id]), $missed);

    Livewire::test(Identify::class, ['client' => $this->client->id, 'call' => $missed->id])
        ->assertSee(__('Call'))
        ->callAction('manualIdentify', data: ['reason' => 'Visszahívtam a rögzített számon, egyeztettük.'])
        ->assertHasNoActionErrors();

    $session = IdSession::query()->where('method', IdMethod::Manual)->first();

    expect($session)->not->toBeNull()
        ->and($session->status)->toBe(IdSessionStatus::Passed)
        ->and($session->call_id)->toBe($missed->id)
        ->and($missed->fresh()->isIdentified())->toBeTrue();
});

it('links a pin verification started from the missed-calls list to that call', function (): void {
    $missed = Call::factory()->create(['status' => CallStatus::Missed, 'client_id' => $this->client->id, 'ended_at' => now()]);
    app(Settings::class)->set(SettingKey::PinAgentVerificationEnabled, true);
    app(PinService::class)->setPin($this->client, '123456');

    $page = Livewire::test(Identify::class, ['client' => $this->client->id, 'call' => $missed->id])
        ->call('chooseMethod', 'pin')
        ->set('pin', '123456')
        ->call('verifyPin');

    $session = IdSession::query()->where('method', IdMethod::Pin)->first();

    expect($session)->not->toBeNull()
        ->and($session->status)->toBe(IdSessionStatus::Passed)
        ->and($session->call_id)->toBe($missed->id)
        ->and($missed->fresh()->isIdentified())->toBeTrue();

    // The page reads the outcome "by call", so without the link it would not
    // even show the agent that the caller has just been identified.
    $page->assertSee(__('Identified'));
});

it('links a question-answer session started from the missed-calls list to that call', function (): void {
    $missed = Call::factory()->create(['status' => CallStatus::Missed, 'client_id' => $this->client->id, 'ended_at' => now()]);

    $page = Livewire::test(Identify::class, ['client' => $this->client->id, 'call' => $missed->id])
        ->call('startQa');

    $session = IdSession::query()->where('method', IdMethod::QuestionAnswer)->first();
    expect($session)->not->toBeNull()
        ->and($session->call_id)->toBe($missed->id);

    $page->call('judge', 'accepted')->call('judge', 'accepted');

    expect($session->fresh()->status)->toBe(IdSessionStatus::Passed)
        ->and($missed->fresh()->isIdentified())->toBeTrue();
});

it('does not attach an identification to a missed call that has already been handled', function (): void {
    $handled = Call::factory()->create(['status' => CallStatus::Missed, 'client_id' => $this->client->id, 'ended_at' => now()]);
    $handled->markHandled($this->agent);

    expect($handled->fresh()->isAttachableBy($this->agent))->toBeFalse();

    Livewire::test(Identify::class, ['client' => $this->client->id, 'call' => $handled->id])
        ->assertActionHidden('wrapUpNote')
        ->call('startQa');

    $session = IdSession::query()->where('method', IdMethod::QuestionAnswer)->first();
    expect($session)->not->toBeNull()
        ->and($session->call_id)->toBeNull()
        ->and($handled->fresh()->isIdentified())->toBeFalse();
});

it('shows the client in the helpdesk client list with an identify action', function (): void {
    Livewire::test(ListClients::class)
        ->assertSee('Kovács Anna')
        ->assertTableActionExists('identify');
});

it('searches and links to the identify page', function (): void {
    $this->client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin]);

    Livewire::test(SearchClients::class)
        ->set('q', 'kov')
        ->assertSee('Kovács Anna')
        ->assertSee(Identify::getUrl(['client' => $this->client->id]));
});

it('is accessible with the identification permission', function (): void {
    $this->get(Identify::getUrl(['client' => $this->client->id]))->assertOk();
});

it('is not accessible without the identification permission', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(Permission::HelpdeskAccess->value);
    $this->flushSession();

    $this->actingAs($viewer)->get(Identify::getUrl(['client' => $this->client->id]))->assertForbidden();
});

it('does not present an old success as the current identification', function (): void {
    $other = User::factory()->withRole(Role::Agent)->create();
    IdSession::factory()->create([
        'client_id' => $this->client->id,
        'agent_user_id' => $other->id,
        'status' => IdSessionStatus::Passed,
        'started_at' => now()->subDay(),
        'decided_at' => now()->subDay(),
    ]);

    Livewire::test(Identify::class, ['client' => $this->client->id])
        ->assertDontSee(__('Back to the call queue'));

    $call = Call::factory()->create(['client_id' => $this->client->id, 'agent_user_id' => $this->agent->id]);
    IdSession::factory()->create([
        'client_id' => $this->client->id,
        'agent_user_id' => $this->agent->id,
        'call_id' => $call->id,
        'status' => IdSessionStatus::Passed,
        'decided_at' => now(),
    ]);

    Livewire::test(Identify::class, ['client' => $this->client->id, 'call' => $call->id])
        ->assertSee(__('Identified'))
        ->assertSee(__('Back to the call queue'))
        ->assertSee(now()->format(HuDate::DATE));
});

it('records a manual identification with a reason', function (): void {
    $call = Call::factory()->create(['client_id' => $this->client->id, 'agent_user_id' => $this->agent->id]);

    Livewire::test(Identify::class, ['client' => $this->client->id, 'call' => $call->id])
        ->callAction('manualIdentify', data: ['reason' => 'rövid'])
        ->assertHasActionErrors(['reason']);
    expect(IdSession::query()->where('method', IdMethod::Manual)->exists())->toBeFalse();

    Livewire::test(Identify::class, ['client' => $this->client->id, 'call' => $call->id])
        ->callAction('manualIdentify', data: ['reason' => 'Visszahívtam a rögzített számon, egyeztettük.'])
        ->assertHasNoActionErrors()
        ->assertSee(__('Identified'))
        ->assertSee(__('recorded manually'));

    $session = IdSession::query()->where('method', IdMethod::Manual)->first();
    expect($session->status)->toBe(IdSessionStatus::Passed)
        ->and($session->call_id)->toBe($call->id)
        ->and($session->agent_user_id)->toBe($this->agent->id)
        ->and($session->outcome_reason)->toStartWith('manual:Visszahívtam');

    expect(AuditLog::query()->where('event', 'id_session.manual')->exists())->toBeTrue();
});

it('hides manual identification from agents without the permission', function (): void {
    $limited = User::factory()->create();
    $limited->givePermissionTo([Permission::HelpdeskAccess->value, Permission::ClientsView->value, Permission::IdentificationRun->value]);
    $this->actingAs($limited);

    Livewire::test(Identify::class, ['client' => $this->client->id])
        ->assertActionHidden('manualIdentify');
});

it('saves a wrap-up note on the call and releases it back to the queue', function (): void {
    $call = Call::factory()->create(['client_id' => $this->client->id, 'agent_user_id' => $this->agent->id, 'status' => CallStatus::Active]);

    Livewire::test(Identify::class, ['client' => $this->client->id, 'call' => $call->id])
        ->callAction('wrapUpNote', data: ['note' => 'Számlareklamáció, visszahívást kér.'])
        ->assertSee('Számlareklamáció')
        ->callAction('releaseCall')
        ->assertRedirect();

    $call->refresh();
    expect($call->wrap_up_note)->toBe('Számlareklamáció, visszahívást kér.')
        ->and($call->agent_user_id)->toBeNull()
        ->and(AuditLog::query()->where('event', 'call.released')->exists())->toBeTrue();
});

it('cancels the open session of the previous client when the call is reassigned', function (): void {
    $wrong = Client::factory()->synced()->create(['name' => 'Rossz Réka']);
    $call = Call::factory()->create(['client_id' => $wrong->id, 'agent_user_id' => $this->agent->id]);
    $open = IdSession::factory()->create(['client_id' => $wrong->id, 'agent_user_id' => $this->agent->id, 'call_id' => $call->id]);

    Livewire::test(Identify::class, ['client' => $this->client->id, 'call' => $call->id])->assertOk();

    expect($call->fresh()->client_id)->toBe($this->client->id)
        ->and($open->fresh()->status)->toBe(IdSessionStatus::Cancelled)
        ->and($open->fresh()->outcome_reason)->toBe('call_reassigned')
        ->and(AuditLog::query()->where('event', 'call.client_reassigned')->exists())->toBeTrue();
});

it('refuses to move a call of a level the agent does not handle', function (): void {
    app(Settings::class)->set(SettingKey::PackagesStandard, ['basic']);
    $this->agent->forceFill(['handles_tiers' => ['premium']])->save();
    $call = Call::factory()->create(['tier' => 'standard', 'client_id' => null]);

    $this->get(Identify::getUrl(['client' => $this->client->id, 'call' => $call->id]))->assertForbidden();
    expect($call->fresh()->client_id)->toBeNull();
});

it('starts identification from the client list on the call the agent has taken', function (): void {
    $call = Call::factory()->create(['agent_user_id' => $this->agent->id, 'status' => CallStatus::Active]);

    Livewire::test(ListClients::class)
        ->assertTableActionHasUrl('identify', Identify::getUrl(['client' => $this->client->id, 'call' => $call->id]), $this->client);
});

it('finds clients by phone number typed the Hungarian way in the client list', function (): void {
    $this->client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin]);
    $other = Client::factory()->synced()->create(['name' => 'Másik Miklós']);

    Livewire::test(ListClients::class)
        ->searchTable('06 30 123 4567')
        ->assertCanSeeTableRecords([$this->client])
        ->assertCanNotSeeTableRecords([$other]);
});

it('matches external ids by prefix and says when the list is cut', function (): void {
    $this->client->externalRecord->forceFill(['external_id' => 123456])->save();

    Livewire::test(SearchClients::class)
        ->set('q', '1234')
        ->assertSee('Kovács Anna')
        ->assertDontSee(__('Only the first :n matches are shown; narrow the search.', ['n' => SearchClients::LIMIT]));

    Client::factory()->synced()->count(SearchClients::LIMIT + 1)->sequence(fn ($seq) => ['name' => 'Teszt Tamás '.$seq->index])->create();

    Livewire::test(SearchClients::class)
        ->set('q', 'Teszt Tamás')
        ->assertSee(__('Only the first :n matches are shown; narrow the search.', ['n' => SearchClients::LIMIT]));
});

it('lets supervisors narrow the calls list by agent and period and shows the wrap-up note', function (): void {
    Call::factory()->create(['agent_user_id' => $this->agent->id, 'wrap_up_note' => 'Visszahívást kér délután.']);

    Livewire::test(ManageCalls::class)
        ->assertTableFilterExists('agent_user_id')
        ->assertTableFilterExists('arrived')
        ->assertSee('Visszahívást kér');
});

it('does not let an agent take over another agent\'s open session', function (): void {
    $other = User::factory()->withRole(Role::Agent)->create();
    $foreign = app(QaSessionEngine::class)->start($this->client, $other);
    $foreignStep = app(QaSessionEngine::class)->next($foreign, $other);

    $page = Livewire::test(Identify::class, ['client' => $this->client->id])
        ->assertDontSee('Stored answer')
        ->assertDontSee('válasz ');

    expect(fn () => $page->set('sessionId', $foreign->id))->toThrow(CannotUpdateLockedPropertyException::class);

    $page->call('judge', 'accepted')
        ->assertDontSee('válasz ');

    expect($foreignStep->fresh()->isPending())->toBeTrue()
        ->and($foreign->fresh()->status)->toBe(IdSessionStatus::Open)
        ->and($foreign->fresh()->accepted_count)->toBe(0);
});

it('ignores a call the agent does not handle or does not hold', function (): void {
    app(Settings::class)->set(SettingKey::PackagesStandard, ['basic']);
    $this->agent->forceFill(['handles_tiers' => ['premium']])->save();
    $other = User::factory()->withRole(Role::Agent)->create();

    $foreignTier = Call::factory()->create(['tier' => 'standard', 'client_id' => $this->client->id, 'agent_user_id' => $this->agent->id, 'wrap_up_note' => 'eredeti']);
    $heldByOther = Call::factory()->create(['client_id' => $this->client->id, 'agent_user_id' => $other->id, 'wrap_up_note' => 'eredeti']);

    foreach ([$foreignTier, $heldByOther] as $call) {
        $page = Livewire::test(Identify::class, ['client' => $this->client->id])
            ->set('call', $call->id)
            ->assertActionHidden('wrapUpNote')
            ->assertActionHidden('releaseCall')
            ->call('startQa');

        expect($call->fresh()->wrap_up_note)->toBe('eredeti');

        $session = IdSession::query()->where('agent_user_id', $this->agent->id)->latest('id')->first();
        expect($session)->not->toBeNull()->and($session->call_id)->toBeNull();

        $page->call('cancelQa');
    }
});

it('refuses to move a call another agent is handling', function (): void {
    $other = User::factory()->withRole(Role::Agent)->create();
    $call = Call::factory()->create(['client_id' => null, 'agent_user_id' => $other->id]);

    $this->get(Identify::getUrl(['client' => $this->client->id, 'call' => $call->id]))->assertForbidden();
    expect($call->fresh()->client_id)->toBeNull();
});

it('uses the same caller number fallback throughout identification', function (string $page, ?string $raw, ?string $normalized, string $expected): void {
    $call = Call::factory()->create(['caller_number_raw' => $raw, 'caller_number_e164' => $normalized, 'agent_user_id' => $this->agent->id, 'client_id' => $this->client->id]);
    $parameters = ['call' => $call->id];
    if ($page === Identify::class) {
        $parameters['client'] = $this->client->id;
    }

    $view = Livewire::test($page, $parameters)->assertSee(__($expected));
    if ($raw !== null) {
        $view->assertDontSee(__('withheld'));
    }
})->with([Identify::class, SearchClients::class, VerifyCode::class])->with([
    'raw only' => ['3630123456789', null, '3630123456789'],
    'normalized first' => ['36301234567', '+36301234567', '+36301234567'],
    'withheld' => [null, null, 'withheld'],
]);
