<?php

use App\Enums\OneTimeCodePurpose;
use App\Enums\PhoneNumberSource;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientPhoneNumber;
use App\Models\OneTimeCode;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\FakeSmsSender;
use App\Sms\SmsSender;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->client = Client::factory()->synced()->create();
    Sanctum::actingAs($this->client, guard: 'client');
    $this->sms = new FakeSmsSender;
    app()->instance(SmsSender::class, $this->sms);
});

it('re-adds a number that was removed earlier without tripping the unique index', function (): void {
    $id = collect($this->postJson('/api/v1/client/phone-numbers', ['number' => '06 20 222 2222'])->assertOk()->json('data.phone_numbers'))->firstWhere('number', '+36202222222')['id'];
    $this->deleteJson('/api/v1/client/phone-numbers/'.$id)->assertOk();
    expect(ClientPhoneNumber::query()->withTrashed()->whereKey($id)->first()->trashed())->toBeTrue();

    $again = collect($this->postJson('/api/v1/client/phone-numbers', ['number' => '+36 20 222 2222', 'label' => 'új'])->assertOk()->json('data.phone_numbers'))->firstWhere('number', '+36202222222');

    expect($again['id'])->toBe($id)
        ->and($again['label'])->toBe('új')
        ->and($again['verified'])->toBeFalse()
        ->and(ClientPhoneNumber::query()->where('client_id', $this->client->id)->count())->toBe(1);
});

it('caps the number of phone numbers a client may keep', function (): void {
    app(Settings::class)->set(SettingKey::PortalMaxPhoneNumbersPerClient, 2);
    $this->client->phoneNumbers()->create(['number_e164' => '+36301111111', 'source' => PhoneNumberSource::Sync, 'verified_at' => now()]);

    $this->postJson('/api/v1/client/phone-numbers', ['number' => '+36202222222'])->assertOk();
    $this->postJson('/api/v1/client/phone-numbers', ['number' => '+36203333333'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['number']);

    // Adding an already listed number is idempotent and does not count against the cap.
    $this->postJson('/api/v1/client/phone-numbers', ['number' => '+36202222222'])->assertOk();
    expect($this->getJson('/api/v1/client/me')->json('data.max_phone_numbers'))->toBe(2);
});

it('verifies a self-added number with a code sent by sms', function (): void {
    app(Settings::class)->set(SettingKey::PortalPhoneVerificationEnabled, true);
    $phone = collect($this->postJson('/api/v1/client/phone-numbers', ['number' => '+36202222222'])->json('data.phone_numbers'))->firstWhere('number', '+36202222222');
    expect($phone['verified'])->toBeFalse();

    $this->postJson('/api/v1/client/phone-numbers/'.$phone['id'].'/verify/request')->assertOk()->assertJsonPath('expires_in_minutes', 10);

    $sms = $this->sms->lastTo('+36202222222');
    expect($sms)->not->toBeNull();
    preg_match('/(\d{6})/', (string) $sms, $m);
    $code = $m[1];

    $this->postJson('/api/v1/client/phone-numbers/'.$phone['id'].'/verify', ['code' => '000000'])
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);

    $verified = collect($this->postJson('/api/v1/client/phone-numbers/'.$phone['id'].'/verify', ['code' => $code])->assertOk()->json('data.phone_numbers'))
        ->firstWhere('id', $phone['id']);

    expect($verified['verified'])->toBeTrue()
        ->and(OneTimeCode::query()->where('purpose', OneTimeCodePurpose::PhoneVerify)->usable()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'client_phone.verified')->count())->toBe(1);

    // Already verified: requesting a new code is refused.
    $this->postJson('/api/v1/client/phone-numbers/'.$phone['id'].'/verify/request')->assertStatus(409);
});

it('binds the verification code to the number it was sent to', function (): void {
    app(Settings::class)->set(SettingKey::PortalPhoneVerificationEnabled, true);
    $first = collect($this->postJson('/api/v1/client/phone-numbers', ['number' => '+36202222222'])->json('data.phone_numbers'))->firstWhere('number', '+36202222222');
    $second = collect($this->postJson('/api/v1/client/phone-numbers', ['number' => '+36203333333'])->json('data.phone_numbers'))->firstWhere('number', '+36203333333');

    $this->postJson('/api/v1/client/phone-numbers/'.$first['id'].'/verify/request')->assertOk();
    preg_match('/(\d{6})/', (string) $this->sms->lastTo('+36202222222'), $m);

    $this->postJson('/api/v1/client/phone-numbers/'.$second['id'].'/verify', ['code' => $m[1]])->assertUnprocessable();
    $this->postJson('/api/v1/client/phone-numbers/'.$first['id'].'/verify', ['code' => $m[1]])->assertOk();
});

it('keeps verification off unless the setting enables it', function (): void {
    $phone = collect($this->postJson('/api/v1/client/phone-numbers', ['number' => '+36202222222'])->json('data.phone_numbers'))->firstWhere('number', '+36202222222');

    $this->postJson('/api/v1/client/phone-numbers/'.$phone['id'].'/verify/request')->assertForbidden();
    $this->postJson('/api/v1/client/phone-numbers/'.$phone['id'].'/verify', ['code' => '123456'])->assertForbidden();
    expect($this->getJson('/api/v1/client/me')->json('data.phone_verification'))->toBeFalse();

    $other = Client::factory()->create();
    $foreign = $other->phoneNumbers()->create(['number_e164' => '+36303333333', 'source' => PhoneNumberSource::ClientSelf]);
    $this->postJson('/api/v1/client/phone-numbers/'.$foreign->id.'/verify/request')->assertNotFound();
});
