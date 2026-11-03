<?php

use App\Auth\Passwordless\OneTimeCodes;
use App\Enums\OneTimeCodePurpose;
use App\Enums\PhoneNumberSource;
use App\Messaging\MessageKey;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientPhoneNumber;
use App\Models\OneTimeCode;
use App\Models\OutboundMessage;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\FakeSmsSender;
use App\Sms\SmsSender;
use App\System\Installation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

it('confirms a number only with a code sent to that number, even when its own code runs out during the request', function (): void {
    app(Settings::class)->set(SettingKey::PortalPhoneVerificationEnabled, true);
    $first = $this->client->phoneNumbers()->create(['number_e164' => '+36202222222', 'source' => PhoneNumberSource::ClientSelf]);
    $second = $this->client->phoneNumbers()->create(['number_e164' => '+36203333333', 'source' => PhoneNumberSource::ClientSelf]);
    $codes = app(OneTimeCodes::class);

    // Two live codes, as two overlapping requests could leave them: the
    // first number's code is known, the second's is the newer one.
    ['code' => $firstCode, 'record' => $firstRecord] = $codes->issueNumeric($this->client, OneTimeCodePurpose::PhoneVerify, 6, 10, 5, '+36202222222');
    ['record' => $secondRecord] = $codes->issueNumeric($this->client, OneTimeCodePurpose::PhoneVerify, 6, 10, 5, '+36203333333');
    $firstRecord->forceFill(['consumed_at' => null])->save();

    // The second number's code is used up right after it was looked at.
    $done = false;
    DB::listen(function ($query) use (&$done, $secondRecord): void {
        if (! $done && str_contains($query->sql, 'one_time_codes') && str_starts_with(strtolower($query->sql), 'select')) {
            $done = true;
            DB::table('one_time_codes')->where('id', $secondRecord->id)->update(['attempts' => 5]);
        }
    });

    $this->postJson('/api/v1/client/phone-numbers/'.$second->id.'/verify', ['code' => $firstCode])
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);

    expect($second->fresh()->verified_at)->toBeNull()
        ->and($first->fresh()->verified_at)->toBeNull();
});

it('keeps one live verification code when two are requested at the same moment', function (): void {
    app(Settings::class)->set(SettingKey::PortalPhoneVerificationEnabled, true);
    $phone = $this->client->phoneNumbers()->create(['number_e164' => '+36202222222', 'source' => PhoneNumberSource::ClientSelf]);
    $lockWasFree = null;
    OneTimeCode::creating(function () use (&$lockWasFree): void {
        $lock = Cache::lock(Installation::lockName('hdid:otc:'.$this->client->id.':'.OneTimeCodePurpose::PhoneVerify->value), 1);
        $lockWasFree = $lock->get();

        if ($lockWasFree) {
            $lock->release();
        }
    });

    $this->postJson('/api/v1/client/phone-numbers/'.$phone->id.'/verify/request')->assertOk();

    expect($lockWasFree)->toBeFalse('the replace-and-insert pair runs under the client\'s issue lock');
});

it('adds a number under the client\'s lock, so simultaneous additions cannot pass the limit', function (string $number, bool $removedBefore): void {
    app(Settings::class)->set(SettingKey::PortalMaxPhoneNumbersPerClient, 3);

    if ($removedBefore) {
        $this->client->phoneNumbers()->create(['number_e164' => $number, 'source' => PhoneNumberSource::Admin])->delete();
    }

    $lockWasFree = null;
    ClientPhoneNumber::saving(function () use (&$lockWasFree): void {
        if ($lockWasFree !== null) {
            return;
        }

        $lock = Cache::lock(Installation::lockName('hdid:phones:'.$this->client->id), 1);
        $lockWasFree = $lock->get();

        if ($lockWasFree) {
            $lock->release();
        }
    });

    $this->postJson('/api/v1/client/phone-numbers', ['number' => $number])->assertOk();

    expect($lockWasFree)->toBeFalse()
        ->and($this->client->phoneNumbers()->where('number_e164', $number)->sole()->source)->toBe(PhoneNumberSource::ClientSelf);
})->with([
    'a new number' => ['+36202222222', false],
    'a number removed earlier' => ['+36203333333', true],
]);

it('tells the portal which numbers may receive the SMS sign-in code', function (): void {
    $this->client->phoneNumbers()->create(['number_e164' => '+36309999999', 'source' => PhoneNumberSource::ClientSelf, 'is_primary' => true]);
    $this->client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Sync]);
    $this->client->phoneNumbers()->create(['number_e164' => '+3612345678', 'source' => PhoneNumberSource::Sync]);

    $phones = collect($this->getJson('/api/v1/client/me')->assertOk()->json('data.phone_numbers'))->keyBy('number');

    expect($phones['+36309999999'])->toMatchArray(['sign_in_capable' => false, 'sign_in_target' => false, 'sms_target' => true])
        ->and($phones['+36301234567'])->toMatchArray(['sign_in_capable' => true, 'sign_in_target' => true])
        ->and($phones['+3612345678'])->toMatchArray(['sign_in_capable' => false, 'sign_in_target' => false]);
});

it('treats a number the client removed and added again as not confirmed for the SMS sign-in', function (): void {
    $this->client->phoneNumbers()->create(['number_e164' => '+36202222222', 'source' => PhoneNumberSource::Admin, 'verified_at' => now()])->delete();

    $phone = collect($this->postJson('/api/v1/client/phone-numbers', ['number' => '+36202222222'])->assertOk()->json('data.phone_numbers'))->firstWhere('number', '+36202222222');

    expect($phone)->toMatchArray(['source' => 'self', 'verified' => false, 'sign_in_capable' => false]);
});

it('e-mails the client when a number it added becomes usable for the SMS sign-in', function (): void {
    app(Settings::class)->set(SettingKey::PortalPhoneVerificationEnabled, true);
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    $phone = $this->client->phoneNumbers()->create(['number_e164' => '+36202222222', 'source' => PhoneNumberSource::ClientSelf]);

    $this->postJson('/api/v1/client/phone-numbers/'.$phone->id.'/verify/request')->assertOk();
    preg_match('/(\d{6})/', (string) $this->sms->lastTo('+36202222222'), $m);
    $this->postJson('/api/v1/client/phone-numbers/'.$phone->id.'/verify', ['code' => $m[1]])->assertOk();

    $notice = OutboundMessage::query()->where('template_key', MessageKey::SignInNumberAdded)->sole();
    expect($notice->recipient)->toBe($this->client->email)
        ->and($notice->body)->toContain('+36202222222');
});
