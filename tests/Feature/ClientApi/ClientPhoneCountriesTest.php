<?php

use App\Enums\OneTimeCodePurpose;
use App\Models\Client;
use App\Models\OneTimeCode;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\FakeSmsSender;
use App\Sms\SmsSender;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;

/**
 * D3 (owner's decision, 2026-10-25): one list of accepted countries for
 * phone numbers. The portal refuses only a number of a country not on it; a
 * landline (or any other number no text message may go to) of a listed
 * country is taken, because it still recognises the caller, and the portal
 * flags it as unusable for text messages, so it cannot be confirmed by SMS.
 */
beforeEach(function (): void {
    $this->client = Client::factory()->synced()->create();
    Sanctum::actingAs($this->client, guard: 'client');
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->sms = new FakeSmsSender;
    app()->instance(SmsSender::class, $this->sms);
});

it('refuses on the portal a number of a country not on the accepted list, with a machine-readable reason', function (string $number): void {
    $this->postJson('/api/v1/client/phone-numbers', ['number' => $number])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'phone_region_not_accepted')
        ->assertJsonValidationErrors(['number']);

    expect($this->client->phoneNumbers()->count())->toBe(0);
})->with([
    'Austrian mobile' => '+436641234567',
    'international premium rate (+979)' => '+979123456789',
    'satellite (+881)' => '+881612345678',
]);

it('takes a number of a country once that country is on the accepted list', function (): void {
    app(Settings::class)->set(SettingKey::PhoneAllowedRegions, ['AT', 'HU']);

    $phone = collect($this->postJson('/api/v1/client/phone-numbers', ['number' => '+436641234567'])->assertOk()->json('data.phone_numbers'))->sole();

    expect($phone['number'])->toBe('+436641234567')
        ->and($phone['sms_capable'])->toBeTrue();
});

it('takes a landline or other non-mobile number of an accepted country and flags it as unusable for text messages', function (string $typed, string $e164): void {
    $phones = collect($this->postJson('/api/v1/client/phone-numbers', ['number' => $typed])->assertOk()->json('data.phone_numbers'));

    expect($phones->firstWhere('number', $e164)['sms_capable'])->toBeFalse()
        ->and($this->client->phoneNumbers()->where('number_e164', $e164)->exists())->toBeTrue();
})->with([
    'Hungarian landline' => ['06 1 234 5678', '+3612345678'],
    'Hungarian premium rate' => ['06 90 123 456', '+3690123456'],
]);

it('flags a mobile number of an accepted country as usable for text messages and names the accepted countries', function (): void {
    $data = $this->postJson('/api/v1/client/phone-numbers', ['number' => '06 30 123 4567'])->assertOk()->json('data');

    expect(collect($data['phone_numbers'])->sole()['sms_capable'])->toBeTrue()
        ->and($data['phone_countries'])->toBe(['Hungary']);
});

it('refuses with the reason to send a confirmation code to an accepted landline', function (): void {
    app(Settings::class)->set(SettingKey::PortalPhoneVerificationEnabled, true);
    $id = collect($this->postJson('/api/v1/client/phone-numbers', ['number' => '06 1 234 5678'])->assertOk()->json('data.phone_numbers'))->sole()['id'];

    $this->postJson('/api/v1/client/phone-numbers/'.$id.'/verify/request')
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'sms_not_mobile')
        ->assertJsonValidationErrors(['number']);

    expect($this->sms->sent)->toBe([])
        ->and(OneTimeCode::query()->where('client_id', $this->client->id)->where('purpose', OneTimeCodePurpose::PhoneVerify)->exists())->toBeFalse();
});

it('does not bring back a removed number whose country is no longer accepted', function (): void {
    app(Settings::class)->set(SettingKey::PhoneAllowedRegions, ['AT', 'HU']);
    $id = collect($this->postJson('/api/v1/client/phone-numbers', ['number' => '+436641234567'])->assertOk()->json('data.phone_numbers'))->sole()['id'];
    $this->deleteJson('/api/v1/client/phone-numbers/'.$id)->assertSuccessful();
    app(Settings::class)->set(SettingKey::PhoneAllowedRegions, ['HU']);

    $this->postJson('/api/v1/client/phone-numbers', ['number' => '+436641234567'])
        ->assertUnprocessable()->assertJsonPath('reason', 'phone_region_not_accepted');

    expect($this->client->phoneNumbers()->count())->toBe(0)
        ->and($this->client->phoneNumbers()->onlyTrashed()->count())->toBe(1);
});

it('sends no confirmation code to a stored mobile whose country was taken off the list', function (): void {
    app(Settings::class)->set(SettingKey::PortalPhoneVerificationEnabled, true);
    app(Settings::class)->set(SettingKey::PhoneAllowedRegions, ['AT', 'HU']);
    $id = collect($this->postJson('/api/v1/client/phone-numbers', ['number' => '+436641234567'])->assertOk()->json('data.phone_numbers'))->sole()['id'];
    app(Settings::class)->set(SettingKey::PhoneAllowedRegions, ['HU']);

    $this->postJson('/api/v1/client/phone-numbers/'.$id.'/verify/request')
        ->assertUnprocessable()->assertJsonPath('reason', 'sms_region_not_allowed');

    expect($this->sms->sent)->toBe([])
        ->and(OneTimeCode::query()->where('purpose', OneTimeCodePurpose::PhoneVerify)->count())->toBe(0);
});
