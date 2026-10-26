<?php

use App\Auth\AccountLogin;
use App\Auth\LoginRejectedException;
use App\Auth\LoginRejection;
use App\Auth\Role;
use App\Clients\ClientLinks;
use App\Clients\ClientPhones;
use App\Clients\ClientTiers;
use App\Clients\PackageListImpact;
use App\Enums\ApiKeyScope;
use App\Enums\ClientTier;
use App\Enums\IdChannel;
use App\Enums\IdSessionStatus;
use App\Enums\OutboundMessageStatus;
use App\Enums\PhoneNumberSource;
use App\Enums\PrincipalType;
use App\Filament\Admin\Pages\Identify;
use App\Filament\Admin\Pages\ManageSettings;
use App\Filament\Admin\Pages\SearchClients;
use App\Filament\Admin\Pages\SharedPhoneNumbers;
use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use App\Filament\Admin\Resources\Calls\Pages\ManageCalls;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Pages\CreateClient;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Clients\RelationManagers\LinkedClientsRelationManager;
use App\Filament\Admin\Resources\Clients\RelationManagers\PhoneNumbersRelationManager;
use App\Filament\Admin\Resources\ExternalRecords\ExternalRecordResource;
use App\Filament\Admin\Resources\ExternalRecords\Pages\ManageExternalRecords;
use App\Filament\Admin\Resources\IdSessions\Pages\ManageIdSessions;
use App\Filament\Admin\Resources\OutboundMessages\Pages\ManageOutboundMessages;
use App\Filament\Admin\Widgets\OngoingCallsWidget;
use App\Identification\ClientSearch;
use App\Identification\MobileOtpService;
use App\Identification\PinService;
use App\Jobs\SendOutboundMessage;
use App\Messaging\Channel;
use App\Messaging\Messenger;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\Client;
use App\Models\ClientLink;
use App\Models\IdSession;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Reporting\ReportSection;
use App\Reporting\UserReport;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\FakeSmsSender;
use App\Sms\SmsSender;
use App\System\CheckStatus;
use App\System\HealthChecks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->premium = Client::factory()->synced()->premium()->create(['name' => 'Prémium Piroska', 'notes' => 'x']);
    $this->standard = Client::factory()->synced()->standard()->create(['name' => 'Normál Nándor', 'notes' => 'x']);
});

function premiumOnly(bool $on = true): void
{
    app(Settings::class)->set(SettingKey::PackagesPremiumOnly, $on);
}

function signInStaff(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->withRole(Role::Admin)->create();
    test()->actingAs($admin);
    Filament::setCurrentPanel('admin');

    return $admin;
}

it('serves only premium clients while premium-only is on, and everybody again when it goes off', function (): void {
    $tiers = app(ClientTiers::class);

    premiumOnly();

    expect($tiers->tierFor($this->premium))->toBe(ClientTier::Premium)
        ->and($tiers->tierFor($this->standard))->toBeNull()
        ->and($tiers->isEntitled($this->standard))->toBeFalse()
        ->and($tiers->isServed($this->standard))->toBeFalse()
        ->and($tiers->activeTiers())->toBe([ClientTier::Premium])
        ->and($tiers->configuredPackages(ClientTier::Standard))->toBe(['basic'])
        ->and($tiers->heldBackCount())->toBe(1);

    premiumOnly(false);

    expect($tiers->tierFor($this->standard))->toBe(ClientTier::Standard)
        ->and($tiers->isServed($this->standard))->toBeTrue()
        ->and($tiers->activeTiers())->toBe([ClientTier::Premium, ClientTier::Standard])
        ->and(Client::query()->whereKey($this->standard->id)->exists())->toBeTrue();
});

it('turns a standard client away at sign-in with the usual reason, and the audit names the mode', function (): void {
    premiumOnly();
    $unentitled = Client::factory()->synced()->unentitled()->create();

    expect(fn () => app(AccountLogin::class)->assertEligible($this->standard, PrincipalType::Client, 'magic_link'))
        ->toThrow(fn (LoginRejectedException $e) => expect($e->reason)->toBe(LoginRejection::NotEntitled));
    expect(fn () => app(AccountLogin::class)->assertEligible($unentitled, PrincipalType::Client, 'magic_link'))
        ->toThrow(LoginRejectedException::class);

    $audits = AuditLog::query()->where('event', 'login.rejected')->get()->keyBy('subject_id');
    expect($audits[$this->standard->id]->context)->toMatchArray(['reason' => 'not_entitled', 'premium_only' => true])
        ->and($audits[$unentitled->id]->context)->not->toHaveKey('premium_only');
});

it('signs a standard client out of the portal at the next request', function (): void {
    Sanctum::actingAs($this->standard, guard: 'client');
    $this->getJson('/api/v1/client/me')->assertOk();

    premiumOnly();

    $this->getJson('/api/v1/client/me')->assertForbidden()->assertJsonPath('reason', 'not_entitled');
});

it('records the call of a standard caller but does not recognise the caller', function (): void {
    config()->set('hdid.callcenter.api_key', 'key-123');
    config()->set('hdid.callcenter.hmac_secret', null);
    $this->standard->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::ClientSelf]);
    $this->premium->phoneNumbers()->create(['number_e164' => '+36309876543', 'source' => PhoneNumberSource::ClientSelf]);
    $this->withHeader('X-Api-Key', 'key-123');

    premiumOnly();

    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-1', 'caller_number' => '+36301234567', 'status' => 'ringing'])
        ->assertCreated()->assertJsonPath('data.client', null);
    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-2', 'caller_number' => '+36309876543', 'status' => 'ringing'])
        ->assertCreated()->assertJsonPath('data.client.id', $this->premium->id);
    $this->getJson('/api/v1/callcenter/lookup?caller_number=%2B36301234567')->assertOk()
        ->assertJsonPath('caller_number', '+36301234567')->assertJsonPath('client', null);

    premiumOnly(false);

    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-3', 'caller_number' => '+36301234567', 'status' => 'ringing'])
        ->assertCreated()->assertJsonPath('data.client.id', $this->standard->id);
    $this->getJson('/api/v1/callcenter/lookup?caller_number=%2B36301234567')->assertOk()->assertJsonPath('client.id', $this->standard->id);
});

it('hides standard clients from every staff view', function (): void {
    signInStaff();
    $this->premium->phoneNumbers()->create(['number_e164' => '+36301111111', 'source' => PhoneNumberSource::ClientSelf]);
    $this->standard->phoneNumbers()->create(['number_e164' => '+36301111111', 'source' => PhoneNumberSource::ClientSelf]);
    $earlierCall = Call::factory()->create(['client_id' => $this->standard->id, 'tier' => ClientTier::Premium]);

    Livewire::test(SharedPhoneNumbers::class)->assertCanSeeTableRecords($this->premium->phoneNumbers);

    premiumOnly();

    Livewire::test(ListClients::class)->assertCanSeeTableRecords([$this->premium])->assertCanNotSeeTableRecords([$this->standard]);
    // The number stays shared (the phone menu cannot tell the two apart); only the hidden holder's row is left out.
    Livewire::test(SharedPhoneNumbers::class)->assertCanSeeTableRecords($this->premium->phoneNumbers)
        ->assertCanNotSeeTableRecords($this->standard->phoneNumbers);
    Livewire::test(ManageExternalRecords::class)
        ->assertCanSeeTableRecords([$this->premium->externalRecord])
        ->assertCanNotSeeTableRecords([$this->standard->externalRecord]);
    Livewire::test(ManageCalls::class)
        ->assertTableColumnStateNotSet('client.name', 'Normál Nándor', $earlierCall)
        ->assertDontSee('Normál Nándor');

    expect(app(ClientSearch::class)->search('Nándor'))->toBeEmpty()
        ->and(app(ClientSearch::class)->search('Piroska'))->toHaveCount(1);
    $this->get(ClientResource::getUrl('view', ['record' => $this->standard]))->assertNotFound();
    $this->get(Identify::getUrl(['client' => $this->standard->id]))->assertNotFound();
});

it('asks before switching premium-only on, naming who is affected, and refuses it without a premium package', function (): void {
    signInStaff();
    $field = ManageSettings::fieldName(SettingKey::PackagesPremiumOnly);

    Livewire::test(ManageSettings::class)
        ->fillForm([$field => true])
        ->mountAction('save')
        ->assertMountedActionModalSee(__('Serve premium clients only?'))
        ->assertMountedActionModalSee('Normál Nándor');
    expect(app(ClientTiers::class)->premiumOnly())->toBeFalse();

    Livewire::test(ManageSettings::class)
        ->fillForm([$field => true])
        ->callAction(TestAction::make('save'))
        ->assertHasNoFormErrors();
    expect(app(ClientTiers::class)->premiumOnly())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'setting.changed')->get()->contains(fn (AuditLog $log): bool => $log->context['key'] === SettingKey::PackagesPremiumOnly->value && $log->context['to'] === true))->toBeTrue();

    premiumOnly(false);
    app(Settings::class)->set(SettingKey::PackagesPremium, []);
    app(Settings::class)->set(SettingKey::PackagesStandard, ['Basic', 'Premium']);

    Livewire::test(ManageSettings::class)->fillForm([$field => true])->callAction(TestAction::make('save'))->assertHasErrors(['data.'.$field]);
    expect(app(ClientTiers::class)->premiumOnly())->toBeFalse();
});

it('keeps the standard contacts editable, since turned-away standard clients still see them', function (): void {
    signInStaff();
    app(Settings::class)->set(SettingKey::SupportStandardPhone, '+36 1 800 2000');
    $phone = ManageSettings::fieldName(SettingKey::SupportStandardPhone);

    // Switching the mode on in the same go keeps an edit of the contacts.
    Livewire::test(ManageSettings::class)
        ->fillForm([$phone => '+36 1 800 3000', ManageSettings::fieldName(SettingKey::PackagesPremiumOnly) => true])
        ->assertFormFieldIsEnabled($phone)
        ->callAction(TestAction::make('save'))
        ->assertHasNoFormErrors();

    expect(app(Settings::class)->string(SettingKey::SupportStandardPhone))->toBe('+36 1 800 3000')
        ->and(app(ClientTiers::class)->premiumOnly())->toBeTrue();
});

it('says that a package moved to the standard list stops its clients being served while only premium clients are served', function (): void {
    signInStaff();
    app(Settings::class)->set(SettingKey::PackagesPremium, ['Premium', 'Gold']);
    Client::factory()->synced()->create(['name' => 'Arany Aranka', 'explicit_package' => 'Gold', 'implicit_package' => null]);
    premiumOnly();

    Livewire::test(ManageSettings::class)
        ->fillForm([
            ManageSettings::fieldName(SettingKey::PackagesPremium) => ['Premium'],
            ManageSettings::fieldName(SettingKey::PackagesStandard) => ['Basic', 'Gold'],
        ])
        ->mountAction('save')
        ->assertMountedActionModalSee(__(':n client(s) move from the premium to the standard tier and, as only premium clients are served, are no longer served (:names).', ['n' => 1, 'names' => 'Arany Aranka']));
});

it('goes to the client list instead of a missing page when a saved client is not served now', function (): void {
    signInStaff();
    premiumOnly();

    Livewire::test(CreateClient::class)
        ->fillForm(['name' => 'Új Normál', 'email' => 'uj-normal@example.test', 'implicit_package' => 'Basic', 'explicit_package' => null])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(ClientResource::getUrl('index'))
        ->assertNotified(__('Saved; the client is not served at present (only premium clients are served), so it does not show in the lists.'));
});

it('shows the mode on every panel page and on the status page', function (): void {
    signInStaff();

    $this->get(ClientResource::getUrl('index'))->assertOk()->assertDontSee(__('The system currently serves premium clients only.'));
    expect(app(HealthChecks::class)->premiumOnly()->status)->toBe(CheckStatus::Ok);

    premiumOnly();

    $this->get(ClientResource::getUrl('index'))->assertOk()->assertSee(__('The system currently serves premium clients only.'));
    $tile = app(HealthChecks::class)->premiumOnly();
    expect($tile->status)->toBe(CheckStatus::Info)
        ->and($tile->detail)->toContain('1')
        ->and(collect(app(HealthChecks::class)->all())->firstWhere('key', 'premium_only')->hint)->not->toBeEmpty();
});

it('treats a call matched to a standard client before the switch as an unknown caller, without touching the call', function (): void {
    config()->set('hdid.callcenter.api_key', 'key-123');
    config()->set('hdid.callcenter.hmac_secret', null);
    $this->withHeader('X-Api-Key', 'key-123');
    $standard = Client::factory()->synced()->standard()->withPin('654321')->create();
    $standard->phoneNumbers()->create(['number_e164' => '+36301234500', 'source' => PhoneNumberSource::ClientSelf]);
    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-9', 'caller_number' => '+36301234500', 'status' => 'ringing'])
        ->assertJsonPath('data.client.id', $standard->id);

    premiumOnly();

    $this->getJson('/api/v1/callcenter/calls/c-9')->assertOk()->assertJsonPath('data.client', null);
    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-9', 'caller_number' => '+36301234500', 'status' => 'active'])
        ->assertOk()->assertJsonPath('data.client', null);
    $this->postJson('/api/v1/callcenter/ivr/verify-pin', ['call_id' => 'c-9', 'pin' => '654321'])
        ->assertOk()->assertJsonPath('result', 'unknown_caller');
    expect(Call::query()->where('external_call_id', 'c-9')->value('client_id'))->toBe($standard->id);

    signInStaff();
    $call = Call::query()->where('external_call_id', 'c-9')->sole();
    expect(OngoingCallsWidget::identifyUrl($call))->toBe(SearchClients::getUrl(['call' => $call->id]));
});

it('keeps a number shared with a hidden client ambiguous instead of giving it to the premium holder', function (): void {
    config()->set('hdid.callcenter.api_key', 'key-123');
    config()->set('hdid.callcenter.hmac_secret', null);
    $this->withHeader('X-Api-Key', 'key-123');
    $this->premium->phoneNumbers()->create(['number_e164' => '+36302222222', 'source' => PhoneNumberSource::ClientSelf]);
    $this->standard->phoneNumbers()->create(['number_e164' => '+36302222222', 'source' => PhoneNumberSource::ClientSelf]);

    premiumOnly();

    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-s', 'caller_number' => '+36302222222', 'status' => 'ringing'])
        ->assertCreated()->assertJsonPath('data.client', null)->assertJsonPath('data.ambiguous', true);

    $phones = app(ClientPhones::class);
    $holders = $phones->otherHolders('+36302222222', $this->premium);
    expect($phones->sharedNumbersOf($this->premium))->toBe(['+36302222222'])
        ->and($holders->pluck('id')->all())->toBe([$this->standard->id])
        ->and($phones->holderNames($holders))->toBe(__(':n client(s) not served at present', ['n' => 1]))
        ->and($phones->holderNames($phones->otherHolders('+36302222222', $this->standard)))->toBe('Prémium Piroska');
});

it('gives an unknown caller on an unmapped queue the premium level while only premium clients are served', function (): void {
    config()->set('hdid.callcenter.api_key', 'key-123');
    config()->set('hdid.callcenter.hmac_secret', null);
    $this->withHeader('X-Api-Key', 'key-123');
    app(Settings::class)->set(SettingKey::CallsDefaultTier, 'standard');
    app(Settings::class)->set(SettingKey::CallsQueueTiers, ['normal-line' => 'standard']);

    premiumOnly();

    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-u', 'caller_number' => '+36309990000', 'status' => 'ringing', 'queue' => 'other'])->assertCreated();
    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-n', 'caller_number' => '+36309990001', 'status' => 'ringing', 'queue' => 'normal-line'])->assertCreated();

    expect(Call::query()->where('external_call_id', 'c-u')->value('tier'))->toBe(ClientTier::Premium)
        ->and(Call::query()->where('external_call_id', 'c-n')->value('tier'))->toBe(ClientTier::Standard);
});

it('keeps the standard level in the reports for the calls taken before', function (): void {
    $agent = signInStaff();
    Call::factory()->create(['agent_user_id' => $agent->id, 'tier' => ClientTier::Standard, 'arrived_at' => now()->subHour()]);
    Call::factory()->create(['agent_user_id' => $agent->id, 'tier' => ClientTier::Premium, 'arrived_at' => now()->subHour()]);

    premiumOnly();

    $calls = collect(app(UserReport::class)->build($agent, today()->subDay(), today(), [ReportSection::Calls])->tables)->firstWhere('title', ReportSection::Calls->label());
    expect($calls->headers)->toContain(__('Level: :name', ['name' => ClientTier::Standard->label()]), __('Level: :name', ['name' => ClientTier::Premium->label()]));
});

it('does not show a hidden client in the outbound messages, nor sends to them again', function (): void {
    signInStaff();
    $message = OutboundMessage::factory()->failed()->create(['client_id' => $this->standard->id, 'recipient' => 'nandor@example.test']);
    Livewire::test(ManageOutboundMessages::class)->assertActionEnabled(TestAction::make('retry')->table($message));

    premiumOnly();

    $message->forceFill(['subject' => 'Kedves Nándor', 'error' => '550 nandor@example.test: no such user'])->save();

    Livewire::test(ManageOutboundMessages::class)
        ->assertDontSee('Normál Nándor')->assertDontSee('nandor@example.test')->assertDontSee('Kedves Nándor')
        ->assertActionDisabled(TestAction::make('retry')->table($message))
        ->searchTable('nandor@example.test')->assertCanNotSeeTableRecords([$message]);
});

it('cannot find or link a hidden client from a sponsor, and still lists links to deleted clients', function (): void {
    signInStaff();
    $sponsor = Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null]);
    $deleted = Client::factory()->synced()->create(['implicit_package' => null, 'explicit_package' => 'Premium']);
    $link = app(ClientLinks::class)->link($sponsor, $deleted);
    $deleted->delete();

    Livewire::test(LinkedClientsRelationManager::class, ['ownerRecord' => $sponsor, 'pageClass' => ViewClient::class])
        ->assertCanSeeTableRecords([$link]);

    premiumOnly();
    $this->standard->update(['implicit_package' => null, 'explicit_package' => null, 'package_override' => null]);
    $this->standard->update(['implicit_package' => 'Basic']);

    expect(LinkedClientsRelationManager::linkableClients($sponsor, 'Nándor'))->toBe([]);
    Livewire::test(LinkedClientsRelationManager::class, ['ownerRecord' => $sponsor, 'pageClass' => ViewClient::class])
        ->callAction(TestAction::make('link')->table(), ['client_id' => $this->standard->id]);
    expect(ClientLink::query()->where('linked_client_id', $this->standard->id)->exists())->toBeFalse();
});

it('does not find a hidden client by name among the calls and identifications', function (): void {
    signInStaff();
    $call = Call::factory()->create(['client_id' => $this->standard->id, 'tier' => ClientTier::Premium]);
    $session = IdSession::factory()->create(['client_id' => $this->standard->id]);

    premiumOnly();

    Livewire::test(ManageCalls::class)->searchTable('Nándor')->assertCanNotSeeTableRecords([$call]);
    Livewire::test(ManageIdSessions::class)->searchTable('Nándor')->assertCanNotSeeTableRecords([$session])
        ->searchTable(null)->filterTable('client', ['client' => 'Nándor'])->assertCanNotSeeTableRecords([$session]);
});

it('gives the mobile backend no identification code for a standard client', function (): void {
    ['plain' => $plain, 'secret' => $secret] = ApiKey::generate('app', ApiKeyScope::MobileBackend);

    premiumOnly();

    signedMachineCall($plain, $secret, 'POST', '/api/v1/mobile/ivr-code', ['email' => $this->standard->email])
        ->assertUnprocessable()->assertJsonValidationErrors('client');
    signedMachineCall($plain, $secret, 'POST', '/api/v1/mobile/ivr-code', ['email' => $this->premium->email])->assertOk();
});

it('names no hidden client on the status page', function (): void {
    Client::factory()->synced()->unentitled()->create(['name' => 'Rejtett Rezső', 'package_override' => 'Basic', 'package_override_until' => now()->addDays(5)]);
    expect(app(HealthChecks::class)->packageOverrides()->detail)->toContain('Rejtett Rezső');

    premiumOnly();

    expect(app(HealthChecks::class)->packageOverrides()->detail)->not->toContain('Rejtett Rezső');
});

it('locks a client page opened before the switch, with its tabs', function (): void {
    signInStaff();
    $edit = Livewire::test(EditClient::class, ['record' => $this->standard->id]);
    $view = Livewire::test(ViewClient::class, ['record' => $this->standard->id]);
    $phones = Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $this->standard, 'pageClass' => ViewClient::class]);

    premiumOnly();

    // The very next request of the page is refused, before anything is filled or saved:
    // since Filament 5.10 the page restores its record through the resource's (served)
    // query, so the hidden client is not found; the policy check stays behind it.
    $edit->set('data.notes', 'titok')->assertNotFound();
    $view->call('$refresh')->assertNotFound();
    $phones->call('$refresh')->assertNotFound();
    expect($this->standard->fresh()->notes)->toBe('x');
});

it('gives no details of a hidden client through the sponsor picker', function (): void {
    premiumOnly();
    $hiddenSponsor = Client::factory()->synced()->create(['name' => 'Rejtett Szponzor', 'implicit_package' => 'Basic', 'explicit_package' => null]);

    expect(ClientResource::sponsorOptionLabel($hiddenSponsor->id))->toBeNull()
        ->and(ClientResource::sponsorOptionLabel($this->premium->id))->toBeNull()
        ->and(ClientResource::sponsorCandidates()->pluck('id')->all())->not->toContain($hiddenSponsor->id);
});

it('counts but does not name hidden clients in the package list confirmation', function (): void {
    premiumOnly();

    $impact = app(PackageListImpact::class)->estimate(['Premium'], [], true);

    expect($impact['losing_access']['count'])->toBe(1)
        ->and(implode(' ', app(PackageListImpact::class)->errors($impact)))->not->toContain('Normál Nándor')
        ->toContain(__(':n client(s) not served at present', ['n' => 1]));
});

it('keeps free text about a hidden client out of the call and identification lists', function (): void {
    signInStaff();
    $call = Call::factory()->create(['client_id' => $this->standard->id, 'tier' => ClientTier::Premium, 'wrap_up_note' => 'Nándor kérte a visszahívást']);
    $session = IdSession::factory()->create(['client_id' => $this->standard->id, 'outcome_reason' => 'manual:Nándor személyesen igazolta']);
    $record = $this->standard->externalRecord->forceFill(['company' => 'Rejtett Cég Kft.']);
    $record->save();

    premiumOnly();

    Livewire::test(ManageCalls::class)->assertDontSee('Nándor kérte');
    Livewire::test(ManageIdSessions::class)->assertDontSee('személyesen igazolta');
    expect(collect(ExternalRecordResource::filterOptions('company'))->keys()->all())->not->toContain('Rejtett Cég Kft.');
});

it('does not report an identification of a hidden client to the call center', function (): void {
    config()->set('hdid.callcenter.api_key', 'key-123');
    config()->set('hdid.callcenter.hmac_secret', null);
    $this->withHeader('X-Api-Key', 'key-123');
    $call = Call::factory()->create(['external_call_id' => 'c-id', 'client_id' => $this->standard->id, 'tier' => ClientTier::Premium]);
    IdSession::factory()->create(['client_id' => $this->standard->id, 'call_id' => $call->id, 'status' => IdSessionStatus::Passed]);
    $this->getJson('/api/v1/callcenter/calls/c-id')->assertJsonPath('data.identified', true);

    premiumOnly();

    $this->getJson('/api/v1/callcenter/calls/c-id')->assertOk()->assertJsonPath('data.client', null)->assertJsonPath('data.identified', false);
});

it('names the mode in the audit when it turns away a code or a PIN', function (): void {
    $client = Client::factory()->synced()->standard()->withPin('123456')->create();
    $code = app(MobileOtpService::class)->issue($client)['code'];

    premiumOnly();

    expect(app(PinService::class)->verify($client->fresh(), '123456', IdChannel::Ivr)->outcome_reason)->toBe('account_closed')
        ->and(app(MobileOtpService::class)->verifyCode($code, IdChannel::Ivr))->toBeNull();

    $refusals = AuditLog::query()->whereIn('event', ['id_session.finished', 'id_session.code_refused'])->get();
    expect($refusals)->toHaveCount(2)
        ->and($refusals->every(fn (AuditLog $log): bool => ($log->context['premium_only'] ?? false) === true))->toBeTrue();
});

it('does not send a message queued before the switch to a client no longer served', function (): void {
    Queue::fake();
    $sms = new FakeSmsSender;
    app()->instance(SmsSender::class, $sms);
    $message = app(Messenger::class)->sendRaw(Channel::Sms, '+36301234567', null, 'Szia Nándor', $this->standard);

    premiumOnly();
    app()->call([new SendOutboundMessage($message->id), 'handle']);

    expect($message->fresh()->status)->toBe(OutboundMessageStatus::Cancelled)
        ->and($sms->sent)->toBe([])
        ->and(AuditLog::query()->where('event', 'message.cancelled')->where('subject_id', $this->standard->id)->first()?->context['premium_only'])->toBeTrue();
});

it('keeps the names in the audit log but links no page of a hidden client', function (): void {
    expect(AuditLogResource::describe($this->standard)['url'])->not->toBeNull();

    premiumOnly();

    expect(AuditLogResource::describe($this->standard))->toBe(['label' => 'Normál Nándor', 'url' => null])
        ->and(AuditLogResource::describe($this->premium)['url'])->not->toBeNull();
});
