<?php

use App\Auth\Role;
use App\Enums\OneTimeCodePurpose;
use App\Enums\OutboundMessageStatus;
use App\Enums\PhoneNumberSource;
use App\Filament\Admin\Pages\ManageSettings;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Clients\RelationManagers\PhoneNumbersRelationManager;
use App\Jobs\SendOutboundMessage;
use App\Messaging\Channel;
use App\Messaging\MessageKey;
use App\Messaging\MessageNotRetryableException;
use App\Messaging\Messenger;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\OneTimeCode;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\SettingRules;
use App\Settings\Settings;
use App\Sms\FakeSmsSender;
use App\Sms\SmsDestinations;
use App\Sms\SmsSender;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/**
 * G11a: a text message went to any valid number, of any country and type
 * (premium rate, non-geographic, landline), so a client could run up the
 * SMS bill to a number of their choosing. The Messenger now sends SMS only
 * to mobile numbers of the accepted countries (one list for phone numbers,
 * Hungary by default; D3, 2026-10-25); a refused message is cancelled with
 * the reason and audited. Staff may record any number, with a warning.
 */
beforeEach(function (): void {
    $this->sms = new FakeSmsSender;
    app()->instance(SmsSender::class, $this->sms);
});

/**
 * Numbers no text message may go to by default, with why.
 *
 * @return array<string, array{0: string, 1: string}>
 */
dataset('numbers without sms', [
    'international premium rate (+979)' => ['+979123456789', 'region_not_allowed'],
    'Hungarian premium rate (06-90)' => ['+3690123456', 'not_mobile'],
    'British premium rate' => ['+449012345678', 'region_not_allowed'],
    'satellite (+881)' => ['+881612345678', 'region_not_allowed'],
    'international network (+882)' => ['+8823421234', 'region_not_allowed'],
    'foreign mobile' => ['+992917123456', 'region_not_allowed'],
    'Hungarian landline' => ['+3612345678', 'not_mobile'],
    'Hungarian toll free' => ['+3680123456', 'not_mobile'],
]);

it('sends no text message to a premium-rate, foreign, non-geographic or landline number', function (string $number, string $reason): void {
    $client = Client::factory()->synced()->create();

    $message = app(Messenger::class)->sendTemplate(MessageKey::PhoneVerifySms, $client, ['code' => '123456', 'minutes' => '10'], $number);

    expect($this->sms->sent)->toBe([])
        ->and($message->fresh()->status)->toBe(OutboundMessageStatus::Cancelled)
        ->and($message->fresh()->error)->toStartWith('Not sent: no text message goes to this number')
        ->and($message->fresh()->body)->toBe('', 'the code is wiped at once, it never goes out');

    $refused = AuditLog::query()->where('event', 'message.refused')->where('subject_id', $client->id)->sole();
    expect($refused->context)->toMatchArray(['channel' => 'sms', 'template_key' => 'phone_verify_sms', 'recipient' => $number, 'reason' => 'sms_destination', 'refusal' => $reason]);
})->with('numbers without sms');

it('still sends to a Hungarian mobile number, and to mobile numbers of the countries accepted', function (): void {
    $client = Client::factory()->synced()->create();
    $messenger = app(Messenger::class);

    $messenger->sendRaw(Channel::Sms, '+36301234567', null, 'test');
    expect($this->sms->sent)->toHaveCount(1);

    $messenger->sendRaw(Channel::Sms, '+436641234567', null, 'test', $client);
    expect($this->sms->sent)->toHaveCount(1, 'Austria is not accepted by default');

    app(Settings::class)->set(SettingKey::PhoneAllowedRegions, ['HU', 'AT']);
    $messenger->sendRaw(Channel::Sms, '+436641234567', null, 'test', $client);
    $messenger->sendRaw(Channel::Sms, '+4312345678', null, 'test', $client);

    expect(array_column($this->sms->sent, 'to'))->toBe(['+36301234567', '+436641234567'], 'an Austrian landline still gets nothing');
});

it('does not send the login code to a premium-rate primary number', function (): void {
    $this->withHeader('Referer', config('app.url'));
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $client->phoneNumbers()->create(['number_e164' => '+3690123456', 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);

    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();

    // No code is issued at all: the answer stays neutral, the audit log says why.
    expect($this->sms->sent)->toBe([])
        ->and(OutboundMessage::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'login.otp_sent')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'login.otp_refused')->sole()->context)->toMatchArray(['reason' => 'no_sms_number', 'sms_refusal' => 'not_mobile']);
});

it('sends the login code to the client\'s mobile when the primary number is a landline', function (): void {
    $this->withHeader('Referer', config('app.url'));
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    // The client added the landline first, so it became the primary one.
    $client->phoneNumbers()->create(['number_e164' => '+3612345678', 'source' => PhoneNumberSource::ClientSelf, 'is_primary' => true]);
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::ClientSelf, 'is_primary' => false]);

    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();

    expect(array_column($this->sms->sent, 'to'))->toBe(['+36301234567'])
        ->and(AuditLog::query()->where('event', 'login.otp_sent')->sole()->context)->toMatchArray(['phone' => '+36301234567'])
        ->and(AuditLog::query()->where('event', 'message.refused')->exists())->toBeFalse();

    // The portal says where the code goes.
    Sanctum::actingAs($client->fresh(), guard: 'client');
    $phones = collect($this->getJson('/api/v1/client/me')->assertOk()->json('data.phone_numbers'))->keyBy('number');
    expect($phones['+36301234567']['sms_target'])->toBeTrue()
        ->and($phones['+3612345678']['sms_target'])->toBeFalse();
});

it('cancels a queued text message whose country was taken off the list before it is sent', function (): void {
    Queue::fake();
    app(Settings::class)->set(SettingKey::PhoneAllowedRegions, ['AT', 'HU']);
    $message = app(Messenger::class)->sendRaw(Channel::Sms, '+436641234567', null, 'Your PIN: 123456', carriesSecret: true);
    expect($message->fresh()->status)->toBe(OutboundMessageStatus::Queued);

    app(Settings::class)->set(SettingKey::PhoneAllowedRegions, ['HU']);
    app()->call([new SendOutboundMessage($message->id), 'handle']);

    $message->refresh();
    expect($message->status)->toBe(OutboundMessageStatus::Cancelled)
        ->and($message->error)->toContain('no text message goes to this number')
        ->and($message->isRedacted())->toBeTrue()
        ->and($this->sms->sent)->toBe([])
        ->and(AuditLog::query()->where('event', 'message.refused')->where('context->message_id', $message->id)->exists())->toBeTrue();
});

it('refuses to retry a failed text message to a number no longer allowed', function (): void {
    $message = OutboundMessage::factory()->sms()->failed()->create(['recipient' => '+3612345678', 'body' => 'hello']);

    expect(fn () => app(Messenger::class)->retry($message))->toThrow(MessageNotRetryableException::class, 'no text message goes to this number');
    expect($message->fresh()->status)->toBe(OutboundMessageStatus::Failed)->and($this->sms->sent)->toBe([]);
});

it('sends no confirmation code to a stored number no text message can reach', function (): void {
    app(Settings::class)->set(SettingKey::PortalPhoneVerificationEnabled, true);
    $client = Client::factory()->synced()->create();
    Sanctum::actingAs($client, guard: 'client');
    // Added before the rule, or before its country was switched off.
    $phone = $client->phoneNumbers()->create(['number_e164' => '+3612345678', 'source' => PhoneNumberSource::ClientSelf, 'is_primary' => true]);

    $this->postJson('/api/v1/client/phone-numbers/'.$phone->id.'/verify/request')
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'sms_not_mobile')
        ->assertJsonValidationErrors(['number']);

    expect($this->sms->sent)->toBe([])
        ->and(OneTimeCode::query()->where('client_id', $client->id)->where('purpose', OneTimeCodePurpose::PhoneVerify)->exists())->toBeFalse();
});

it('warns staff when a number they record cannot receive text messages', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $client = Client::factory()->create();
    $client->phoneNumbers()->create(['number_e164' => '+3612345678', 'source' => PhoneNumberSource::Admin, 'verified_at' => now()]);

    Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->assertSee('Cannot be used for text messages: not a mobile number')
        ->mountAction(TestAction::make('create')->table())
        ->fillForm(['number_e164' => '+3690123456'])
        ->assertMountedActionModalSee('This number cannot be used for text messages (not a mobile number)')
        ->fillForm(['number_e164' => '+36301234567'])
        ->assertMountedActionModalDontSee('cannot be used for text messages')
        ->assertMountedActionModalDontSee('not of an accepted country');
});

it('lets staff record a number of a country not on the accepted list, with a warning', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $client = Client::factory()->create();

    Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->mountAction(TestAction::make('create')->table())
        ->fillForm(['number_e164' => '+43 664 1234567'])
        ->assertMountedActionModalSee('This number is not of an accepted country')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($client->phoneNumbers()->where('number_e164', '+436641234567')->exists())->toBeTrue();

    Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->assertSee('Not an accepted country: no text messages go to it');
});

it('keeps the accepted countries as a validated list of region codes', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create());
    $field = ManageSettings::fieldName(SettingKey::PhoneAllowedRegions);

    expect(SettingKey::PhoneAllowedRegions->default())->toBe(['HU'])
        ->and(app(SmsDestinations::class)->allowedRegions())->toBe(['HU']);

    Livewire::test(ManageSettings::class)->fillForm([$field => []])->call('save')->assertHasFormErrors([$field]);
    Livewire::test(ManageSettings::class)->fillForm([$field => ['HU', 'XX']])->call('save')->assertHasErrors();
    expect(app(Settings::class)->array(SettingKey::PhoneAllowedRegions))->toBe(['HU']);

    Livewire::test(ManageSettings::class)->fillForm([$field => ['HU', 'AT']])->call('save')->assertHasNoFormErrors();

    expect(app(Settings::class)->array(SettingKey::PhoneAllowedRegions))->toBe(['AT', 'HU'], 'stored in one order, so an untouched field never counts as changed')
        ->and(SmsDestinations::normalizeRegions([' at', 'HU', 'hu']))->toBe(['AT', 'HU'])
        ->and(Validator::make(['regions' => ['HU', 'XX']], ['regions' => SettingRules::for(SettingKey::PhoneAllowedRegions)])->errors()->first('regions'))->toBe('Unknown country code: XX.')
        ->and(Validator::make(['regions' => []], ['regions' => SettingRules::for(SettingKey::PhoneAllowedRegions)])->fails())->toBeTrue();
});

it('works out from the numbering plan which countries do not tell every mobile number apart from a landline', function (): void {
    $regions = SmsDestinations::regionsWithoutSeparateMobileNumbers();

    expect($regions)->toContain('US', 'CA', 'MX', 'IN', 'DK')
        ->and(array_values(array_intersect($regions, ['HU', 'AT', 'DE', 'GB', 'SK', 'RO'])))->toBe([])
        ->and($regions)->toBe(SmsDestinations::normalizeRegions($regions), 'each once, in order');
});

it('names under the countries field, as it changes and before saving, the countries where a text message may reach a landline', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create());
    $field = ManageSettings::fieldName(SettingKey::PhoneAllowedRegions);

    Livewire::test(ManageSettings::class)
        ->assertDontSee('so a text message may go to their landline numbers too')
        ->fillForm([$field => ['HU', 'US']])
        ->assertSee('so a text message may go to their landline numbers too: United States.')
        ->fillForm([$field => ['CA', 'HU', 'US']])
        ->assertSee('their landline numbers too: Canada, United States.')
        ->fillForm([$field => ['AT', 'HU']])
        ->assertDontSee('so a text message may go to their landline numbers too');

    expect(app(Settings::class)->array(SettingKey::PhoneAllowedRegions))->toBe(['HU'], 'nothing was saved');
});

it('offers the PIN by SMS only to a number that receives text messages, and never reports a send that did not happen', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    Filament::setCurrentPanel('admin');
    $client = Client::factory()->synced()->create();
    $client->phoneNumbers()->create(['number_e164' => '+3612345678', 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);

    $page = Livewire::test(ViewClient::class, ['record' => $client->id])->mountAction(TestAction::make('setPin')->schemaComponent('pin_hash'));
    expect($page->get('mountedActions.0.data.send_sms'))->toBeFalse('off by default: the landline receives no SMS');
    $toggle = $page->instance()->getSchemaComponent('mountedActionSchema0.send_sms');
    expect($toggle->isDisabled())->toBeTrue()
        ->and($toggle->toHtml())->toContain('No text message goes to +3612345678');
    $page->fillForm(['pin' => '482915', 'send_sms' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified(__('PIN set'));

    expect($client->fresh()->hasPin())->toBeTrue()
        ->and($this->sms->sent)->toBe([])
        ->and(OutboundMessage::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'client.pin_sent_sms')->exists())->toBeFalse();

    // With a mobile beside the landline, the PIN goes to the mobile.
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin, 'is_primary' => false]);
    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->callAction(TestAction::make('setPin')->schemaComponent('pin_hash'), ['pin' => '593016', 'send_sms' => true])
        ->assertHasNoActionErrors()
        ->assertNotified(__('PIN set and sent by SMS to :number', ['number' => '+36301234567']));

    expect(array_column($this->sms->sent, 'to'))->toBe(['+36301234567'])
        ->and(AuditLog::query()->where('event', 'client.pin_sent_sms')->sole()->context)->toMatchArray(['phone' => '+36301234567']);
});
