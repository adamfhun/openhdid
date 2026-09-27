<?php

use App\Auth\Role;
use App\CallCenter\CallCenterService;
use App\Clients\ClientPhones;
use App\Enums\CallStatus;
use App\Enums\IdChannel;
use App\Enums\IdSessionStatus;
use App\Enums\PhoneNumberSource;
use App\Enums\PhoneVerificationSource;
use App\Filament\Admin\Pages\SharedPhoneNumbers;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Clients\RelationManagers\PhoneNumbersRelationManager;
use App\Identification\PinService;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\System\HealthChecks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

function sharedNumber(): string
{
    return '+36301111111';
}

it('refuses a pin for a client without a registered phone number, on every path', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $client = Client::factory()->create();
    $pins = app(PinService::class);

    expect($pins->canSetPin($client))->toBeFalse()
        ->and(fn () => $pins->setPin($client, '123456'))->toThrow(ValidationException::class)
        ->and($client->fresh()->hasPin())->toBeFalse();

    app(Settings::class)->set(SettingKey::PinClientChangesEnabled, true);
    Sanctum::actingAs($client, ['client:portal'], 'client');
    $this->putJson('/api/v1/client/pin', ['pin' => '123456', 'pin_confirmation' => '123456'])
        ->assertUnprocessable()->assertJsonValidationErrorFor('pin');
    expect($this->getJson('/api/v1/client/me')->json('data.identification.pin_requires_phone'))->toBeTrue();

    // Sanctum's actingAs switched the default guard to the client; the panel runs on the web guard.
    app('auth')->shouldUse('web');
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Livewire::test(ViewClient::class, ['record' => $client->id])->assertActionDisabled('setPin');

    $client->phoneNumbers()->create(['number_e164' => sharedNumber(), 'source' => PhoneNumberSource::Admin, 'verified_at' => now()]);
    Livewire::test(ViewClient::class, ['record' => $client->id])->assertActionEnabled('setPin');
    $pins->setPin($client->fresh(), '123456');
    expect($client->fresh()->hasPin())->toBeTrue();
});

it('removes the pin together with the last registered number, whoever removes it', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $client = Client::factory()->withPin('123456')->create();
    $self = $client->phoneNumbers()->create(['number_e164' => '+36302222222', 'source' => PhoneNumberSource::ClientSelf]);
    $admin = $client->phoneNumbers()->create(['number_e164' => '+36303333333', 'source' => PhoneNumberSource::Admin, 'verified_at' => now()]);

    Sanctum::actingAs($client, ['client:portal'], 'client');
    $this->deleteJson('/api/v1/client/phone-numbers/'.$self->id)->assertOk()->assertJsonPath('pin_cleared', false);
    expect($client->fresh()->hasPin())->toBeTrue('one number is still registered');

    app('auth')->shouldUse('web');
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->callAction(TestAction::make('delete')->table($admin));

    expect($client->fresh()->hasPin())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'client.pin_cleared')->where('subject_id', $client->id)->value('context'))->toMatchArray(['reason' => ClientPhones::PIN_CLEARED_REASON]);
});

it('tells the client on the portal when the last number took the pin with it', function (): void {
    $client = Client::factory()->withPin('123456')->create();
    $only = $client->phoneNumbers()->create(['number_e164' => '+36302222222', 'source' => PhoneNumberSource::ClientSelf]);

    Sanctum::actingAs($client, ['client:portal'], 'client');
    $this->deleteJson('/api/v1/client/phone-numbers/'.$only->id)->assertOk()->assertJsonPath('pin_cleared', true);

    expect($client->fresh()->hasPin())->toBeFalse();
});

it('lets the helpdesk confirm a number and records who did it', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $client = Client::factory()->create();
    $phone = $client->phoneNumbers()->create(['number_e164' => '+36302222222', 'source' => PhoneNumberSource::ClientSelf]);

    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->assertActionHidden(TestAction::make('confirm')->table($phone));

    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin);
    Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->callAction(TestAction::make('confirm')->table($phone))
        ->assertActionHidden(TestAction::make('confirm')->table($phone->fresh()));

    expect($phone->fresh()->isVerified())->toBeTrue()
        ->and($phone->fresh()->verified_via)->toBe(PhoneVerificationSource::Staff)
        ->and($phone->fresh()->verified_by_user_id)->toBe($admin->id)
        ->and($phone->fresh()->verificationLabel())->toBe(__('confirmed by the helpdesk'))
        ->and(AuditLog::query()->where('event', 'client_phone.verified')->where('subject_id', $phone->id)->value('context'))->toMatchArray(['via' => 'staff']);
});

it('verifies the number an identified call came from only when the switch is on', function (): void {
    $client = Client::factory()->withPin('123456')->create();
    $phone = $client->phoneNumbers()->create(['number_e164' => '+36302222222', 'source' => PhoneNumberSource::ClientSelf]);
    $calls = app(CallCenterService::class);

    $calls->upsertCall('c-off', '+36302222222', CallStatus::Ringing);
    expect(app(PinService::class)->verify($client, '123456', IdChannel::Ivr, call: Call::query()->where('external_call_id', 'c-off')->first())->status)->toBe(IdSessionStatus::Passed)
        ->and($phone->fresh()->isVerified())->toBeFalse('the switch is off');

    app(Settings::class)->set(SettingKey::VerifyPhoneOnIdentifiedCall, true);
    $calls->upsertCall('c-on', '+36302222222', CallStatus::Ringing);
    $call = Call::query()->where('external_call_id', 'c-on')->first();
    expect(app(PinService::class)->verify($client, '000000', IdChannel::Ivr, call: $call)->status)->toBe(IdSessionStatus::Failed)
        ->and($phone->fresh()->isVerified())->toBeFalse('a failed check proves nothing');

    $session = app(PinService::class)->verify($client, '123456', IdChannel::Ivr, call: $call);

    expect($session->status)->toBe(IdSessionStatus::Passed)
        ->and($phone->fresh()->verified_via)->toBe(PhoneVerificationSource::IdentifiedCall)
        ->and(AuditLog::query()->where('event', 'client_phone.verified')->where('subject_id', $phone->id)->value('context'))->toMatchArray(['via' => 'identified_call', 'id_session_id' => $session->id]);
});

it('asks the operator to acknowledge a number another client already carries', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $holder = Client::factory()->create(['name' => 'Első Elek']);
    $holder->phoneNumbers()->create(['number_e164' => sharedNumber(), 'source' => PhoneNumberSource::Sync, 'verified_at' => now()]);
    $client = Client::factory()->create();

    Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->callAction(TestAction::make('create')->table(), ['number_e164' => '06 30 111 1111', 'label' => 'iroda'])
        ->assertHasFormErrors(['acknowledge_shared']);
    expect($client->phoneNumbers()->count())->toBe(0);

    Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->callAction(TestAction::make('create')->table(), ['number_e164' => '06 30 111 1111', 'label' => 'iroda', 'acknowledge_shared' => true])
        ->assertHasNoFormErrors();

    $phone = $client->phoneNumbers()->firstOrFail();
    expect($phone->number_e164)->toBe(sharedNumber())
        ->and($phone->verified_via)->toBe(PhoneVerificationSource::Admin)
        ->and(app(ClientPhones::class)->isShared($phone))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'client_phone.shared_acknowledged')->where('subject_id', $phone->id)->value('context'))->toMatchArray(['shared_with' => [$holder->id]]);

    Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->callAction(TestAction::make('create')->table(), ['number_e164' => '06 30 555 5555'])
        ->assertHasNoFormErrors();
    expect($client->phoneNumbers()->count())->toBe(2, 'an unshared number needs no acknowledgement');
});

it('lists shared numbers for client managers only, with the count on the menu and the status page', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $first = Client::factory()->create(['name' => 'Első Elek']);
    $second = Client::factory()->create(['name' => 'Második Miklós']);
    $closed = Client::factory()->closed()->create(['name' => 'Lezárt Lajos']);
    $alone = Client::factory()->create(['name' => 'Egyedül Edit']);
    $first->phoneNumbers()->create(['number_e164' => sharedNumber(), 'source' => PhoneNumberSource::Sync, 'verified_at' => now()]);
    $second->phoneNumbers()->create(['number_e164' => sharedNumber(), 'source' => PhoneNumberSource::ClientSelf]);
    $closed->phoneNumbers()->create(['number_e164' => '+36302222222', 'source' => PhoneNumberSource::Sync, 'verified_at' => now()]);
    $alone->phoneNumbers()->create(['number_e164' => '+36302222222', 'source' => PhoneNumberSource::Admin, 'verified_at' => now()]);

    expect(app(ClientPhones::class)->sharedNumberCount())->toBe(1, 'a closed client does not share')
        ->and(app(ClientPhones::class)->sharedNumbersOf($first))->toBe([sharedNumber()])
        ->and(app(ClientPhones::class)->sharedNumbersOf($alone))->toBe([])
        ->and(app(HealthChecks::class)->sharedPhoneNumbers()->status->value)->toBe('warn');

    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    expect(SharedPhoneNumbers::canAccess())->toBeFalse();
    $this->get(SharedPhoneNumbers::getUrl())->assertForbidden();

    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    expect(SharedPhoneNumbers::getNavigationBadge())->toBe('1');
    Livewire::test(SharedPhoneNumbers::class)
        ->assertCanSeeTableRecords($first->phoneNumbers)
        ->assertCanSeeTableRecords($second->phoneNumbers)
        ->assertCanNotSeeTableRecords($alone->phoneNumbers)
        ->assertTableActionHidden('delete', $first->phoneNumbers->first())
        ->callTableAction('delete', $second->phoneNumbers->first());

    expect($second->fresh()->phoneNumbers)->toHaveCount(0)
        ->and(app(ClientPhones::class)->sharedNumberCount())->toBe(0)
        ->and(app(HealthChecks::class)->sharedPhoneNumbers()->status->value)->toBe('ok');

    Livewire::test(ViewClient::class, ['record' => $first->id])->assertDontSee(__('Shared phone numbers'));
});

it('shows a shared number to the client only when the operator switched the notice on', function (): void {
    $other = Client::factory()->create();
    $other->phoneNumbers()->create(['number_e164' => sharedNumber(), 'source' => PhoneNumberSource::Sync, 'verified_at' => now()]);
    $client = Client::factory()->create();
    $client->phoneNumbers()->create(['number_e164' => sharedNumber(), 'source' => PhoneNumberSource::ClientSelf]);
    Sanctum::actingAs($client, ['client:portal'], 'client');

    expect($this->getJson('/api/v1/client/me')->json('data.phone_numbers.0.shared'))->toBeFalse();

    app(Settings::class)->set(SettingKey::PortalSharedNumberNotice, true);
    expect($this->getJson('/api/v1/client/me')->json('data.phone_numbers.0.shared'))->toBeTrue()
        ->and($this->getJson('/api/v1/client/me')->json('data.phone_verification_on_call'))->toBeFalse();
});

it('refuses to promise a verification sms while the gateway is down', function (): void {
    app(Settings::class)->set(SettingKey::PortalPhoneVerificationEnabled, true);
    config()->set('hdid.sms.driver', 'ozeki');
    config()->set('hdid.ozeki.url', 'http://ozeki.test/api');
    config()->set('hdid.ozeki.username', 'user');
    Http::fake(['ozeki.test/*' => Http::response('down', 500)]);
    $client = Client::factory()->create();
    $phone = $client->phoneNumbers()->create(['number_e164' => '+36302222222', 'source' => PhoneNumberSource::ClientSelf]);
    Sanctum::actingAs($client, ['client:portal'], 'client');

    $this->postJson('/api/v1/client/phone-numbers/'.$phone->id.'/verify/request')->assertStatus(503);
    $this->assertDatabaseMissing('outbound_messages', ['client_id' => $client->id]);
});
