<?php

use App\Auth\Passwordless\OneTimeCodes;
use App\Enums\OneTimeCodePurpose;
use App\Enums\PhoneNumberSource;
use App\Mail\MagicLinkMail;
use App\Models\Client;
use App\Models\OneTimeCode;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\FakeSmsSender;
use App\Sms\SmsSender;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    // The client site is same-origin, so Sanctum treats its requests as stateful.
    $this->withHeader('Referer', config('app.url'));
    Mail::fake();
    $this->sms = new FakeSmsSender;
    app()->instance(SmsSender::class, $this->sms);
});

it('sends a magic link only to eligible clients and never reveals existence', function (): void {
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    Client::factory()->create(['email' => 'unsynced@x.hu']);

    $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'c@x.hu'])->assertOk();
    $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'unsynced@x.hu'])->assertOk();
    $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'nobody@x.hu'])->assertOk();

    Mail::assertSent(MagicLinkMail::class, 1);
    Mail::assertSent(MagicLinkMail::class, fn (MagicLinkMail $mail) => $mail->client->is($client) && $mail->hasTo('c@x.hu'));
});

it('signs the client in from the link exactly once', function (): void {
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'c@x.hu']);
    $url = null;
    Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $mail) use (&$url) {
        $url = $mail->url;

        return true;
    });

    $this->get($url)->assertRedirect('/');
    $this->assertAuthenticatedAs($client, 'client');

    auth('client')->logout();
    $this->get($url)->assertRedirect('/login?error=invalid_credentials');
    $this->assertGuest('client');
});

it('lets only one of two overlapping redemptions of the same secret win', function (): void {
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $codes = app(OneTimeCodes::class);

    // The record is still usable when this request loads it, but a parallel
    // request redeems it in the window before this one writes.
    OneTimeCode::retrieved(function (OneTimeCode $record): void {
        OneTimeCode::query()->whereKey($record->id)->whereNull('consumed_at')->update(['consumed_at' => now(), 'lookup' => null]);
    });

    $token = $codes->issueToken($client, OneTimeCodePurpose::MagicLink, 15)['token'];
    expect($codes->consume(OneTimeCodePurpose::MagicLink, $token))->toBeNull()
        ->and($codes->consumeToken(OneTimeCodePurpose::MagicLink, $token))->toBeNull();

    $code = $codes->issueNumeric($client, OneTimeCodePurpose::LoginOtp, 6, 5, 3)['code'];
    expect($codes->verify($client, OneTimeCodePurpose::LoginOtp, $code))->toBeFalse()
        ->and(OneTimeCode::query()->whereNull('consumed_at')->count())->toBe(0);
});

it('refuses a magic link when the method is disabled', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginMagicLinkEnabled, false);
    Client::factory()->synced()->create(['email' => 'c@x.hu']);

    $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'c@x.hu'])->assertForbidden();
    Mail::assertNothingSent();
});

it('sends and verifies an sms code, then starts a session', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);

    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();

    $message = $this->sms->lastTo('+36301234567');
    expect($message)->not->toBeNull();
    preg_match('/(\d{6})/', $message, $m);

    $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'c@x.hu', 'code' => '000000'])->assertForbidden();
    $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'c@x.hu', 'code' => $m[1]])->assertOk()->assertJsonPath('data.email', 'c@x.hu');
    $this->assertAuthenticatedAs($client, 'client');

    $this->getJson('/api/v1/client/me')->assertOk();
});

it('locks a code after too many wrong attempts', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    app(Settings::class)->set(SettingKey::ClientLoginOtpMaxAttempts, 2);
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);

    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu']);
    preg_match('/(\d{6})/', $this->sms->lastTo('+36301234567'), $m);

    $this->withoutMiddleware(ThrottleRequests::class);
    $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'c@x.hu', 'code' => '111111'])->assertForbidden();
    $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'c@x.hu', 'code' => '222222'])->assertForbidden();
    $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'c@x.hu', 'code' => $m[1]])->assertForbidden();

    expect(OneTimeCode::query()->first()->isUsable())->toBeFalse();
});

it('does not send sms to a client without a phone number', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    Client::factory()->synced()->create(['email' => 'c@x.hu']);

    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();

    expect($this->sms->sent)->toBe([]);
});
