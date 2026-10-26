<?php

use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Enums\PhoneNumberSource;
use App\Mail\MagicLinkMail;
use App\Models\AuditLog;
use App\Models\Client;
use App\Settings\SettingKey;
use App\Settings\SettingRules;
use App\Settings\Settings;
use App\Settings\SettingSection;
use App\Sms\FakeSmsSender;
use App\Sms\SmsSender;
use App\System\CheckStatus;
use App\System\HealthChecks;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/*
 * The system-wide limits of the anonymously requested login messages
 * (owner's decision, 2026-10-26): login links by e-mail per hour, generous
 * enough for any foreseeable number of users; SMS login codes strictly, per
 * hour and per day for the whole system and per day for one number. Only a
 * message that goes out counts; above a limit the answer stays neutral.
 */

beforeEach(function (): void {
    $this->withHeader('Referer', config('app.url'));
    $this->withoutMiddleware(ThrottleRequests::class);
    Mail::fake();
    $this->sms = new FakeSmsSender;
    app()->instance(SmsSender::class, $this->sms);
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
});

function capClient(string $email, ?string $phone = null): Client
{
    $client = Client::factory()->synced()->create(['email' => $email]);

    if ($phone !== null) {
        $client->phoneNumbers()->create(['number_e164' => $phone, 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);
    }

    return $client;
}

function requestLink(string $email): array
{
    return test()->postJson('/api/v1/client/auth/magic-link', ['email' => $email])->assertOk()->json();
}

function requestCode(string $email): array
{
    return test()->postJson('/api/v1/client/auth/otp/request', ['email' => $email])->assertOk()->json();
}

it('sends at most the hourly limit of login links, with the neutral answer above it, the SMS codes on a counter of their own', function (): void {
    $settings = app(Settings::class);
    $settings->set(SettingKey::ClientLoginMagicLinkMaxPerHour, 2);
    $settings->set(SettingKey::ClientLoginOtpSmsMaxPerHour, 1);
    $clients = collect(['a@x.hu', 'b@x.hu', 'c@x.hu', 'd@x.hu'])->map(fn (string $email): Client => capClient($email));
    capClient('sms1@x.hu', '+36301234561');
    capClient('sms2@x.hu', '+36301234562');

    requestLink('a@x.hu');
    requestLink('b@x.hu');
    $capped = requestLink('c@x.hu');
    requestLink('d@x.hu');
    requestCode('sms1@x.hu');
    $cappedCode = requestCode('sms2@x.hu');

    Mail::assertSent(MagicLinkMail::class, 2);
    expect($this->sms->sent)->toHaveCount(1, 'the links used up their own limit, not the codes\'')
        ->and($capped)->toBe(requestLink('nobody@x.hu'), 'the answer above the limit is the neutral one')
        ->and($cappedCode)->toBe(requestCode('nobody@x.hu'));

    // One audit entry a minute per channel, not one per refused request.
    $audits = AuditLog::query()->where('event', 'login.request_capped')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits->firstWhere('context.channel', 'email')->context)->toMatchArray(['channel' => 'email', 'method' => ClientPasswordlessLogin::METHOD_MAGIC_LINK, 'window' => 'hour', 'limit' => 2])
        ->and($audits->firstWhere('context.channel', 'sms')->context)->toMatchArray(['channel' => 'sms', 'method' => ClientPasswordlessLogin::METHOD_OTP_SMS, 'window' => 'hour', 'limit' => 1]);

    $tile = app(HealthChecks::class)->outboundMessages();
    expect($tile->status)->toBe(CheckStatus::Warn)
        ->and($tile->detail)->toContain(__('login links by e-mail: hourly limit (:limit) reached', ['limit' => 2]))
        ->and($tile->detail)->toContain(__('SMS login codes: hourly limit (:limit) reached', ['limit' => 1]))
        ->and($tile->detail)->toContain(__(':n not sent', ['n' => 2]));

    // A link the helpdesk sends from the client page is not an anonymous request.
    app(ClientPasswordlessLogin::class)->issueMagicLink($clients[2], sentBy: 'agent@corp.hu');
    Mail::assertSent(MagicLinkMail::class, 3);

    $this->travel(61)->minutes();
    requestLink('d@x.hu');
    Mail::assertSent(MagicLinkMail::class, fn (MagicLinkMail $mail): bool => $mail->hasTo('d@x.hu'));
    expect(app(HealthChecks::class)->outboundMessages()->status)->toBe(CheckStatus::Ok);
});

it('counts only the login requests that send something, and an e-mail limit of 0 means no limit', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginMagicLinkMaxPerHour, 1);
    capClient('a@x.hu');
    capClient('b@x.hu');

    foreach (range(1, 4) as $attempt) {
        requestLink("nobody{$attempt}@x.hu");
    }
    requestLink('a@x.hu');
    requestLink('b@x.hu');
    Mail::assertSent(MagicLinkMail::class, 1);

    app(Settings::class)->set(SettingKey::ClientLoginMagicLinkMaxPerHour, 0);
    requestLink('b@x.hu');
    Mail::assertSent(MagicLinkMail::class, 2);
});

it('limits the SMS login codes per day as well, a refusal by one window not using up the other', function (): void {
    $settings = app(Settings::class);
    $settings->set(SettingKey::ClientLoginOtpSmsMaxPerHour, 2);
    $settings->set(SettingKey::ClientLoginOtpSmsMaxPerDay, 3);
    foreach (range(1, 6) as $i) {
        capClient("c{$i}@x.hu", '+3630123456'.$i);
    }

    requestCode('c1@x.hu');
    requestCode('c2@x.hu');
    requestCode('c3@x.hu'); // the hour is full; the day is not touched
    expect($this->sms->sent)->toHaveCount(2);

    $this->travel(61)->minutes();
    requestCode('c4@x.hu');
    requestCode('c5@x.hu'); // the day is full
    expect($this->sms->sent)->toHaveCount(3)
        ->and(AuditLog::query()->where('event', 'login.request_capped')->where('context->window', 'day')->sole()->context)
        ->toMatchArray(['channel' => 'sms', 'limit' => 3]);

    $this->travel(2)->hours();
    requestCode('c6@x.hu');
    expect($this->sms->sent)->toHaveCount(3, 'a new hour does not reopen a full day')
        ->and(app(HealthChecks::class)->outboundMessages()->detail)->toContain(__('SMS login codes: daily limit (:limit) reached', ['limit' => 3]));

    $this->travel(1)->days();
    requestCode('c6@x.hu');
    expect($this->sms->sent)->toHaveCount(4);
});

it('sends one number at most the daily number of login codes, silently refusing the rest', function (): void {
    $settings = app(Settings::class);
    $settings->set(SettingKey::ClientLoginOtpSmsMaxPerNumberPerHour, 5);
    $settings->set(SettingKey::ClientLoginOtpSmsMaxPerNumberPerDay, 2);
    capClient('a@x.hu', '+36301234567');

    requestCode('a@x.hu');
    $this->travel(61)->seconds();
    requestCode('a@x.hu');
    $this->travel(2)->hours();
    $refused = requestCode('a@x.hu');

    expect($this->sms->sent)->toHaveCount(2)
        ->and($refused)->toBe(requestCode('nobody@x.hu'))
        ->and(AuditLog::query()->where('event', 'message.refused')->sole()->context)
        ->toMatchArray(['channel' => 'sms', 'recipient' => '+36301234567', 'reason' => 'sms_limit_per_day', 'limit' => 2]);

    $this->travel(1)->days();
    requestCode('a@x.hu');
    expect($this->sms->sent)->toHaveCount(3);
});

it('tells the portal while a system-wide limit turns requests away, the same for every address', function (): void {
    $settings = app(Settings::class);
    $settings->set(SettingKey::ClientLoginMagicLinkMaxPerHour, 1);
    $settings->set(SettingKey::ClientLoginOtpSmsMaxPerHour, 1);
    capClient('a@x.hu', '+36301234561');
    capClient('b@x.hu', '+36301234562');
    $caps = fn (): array => $this->getJson('/api/v1/branding')->assertOk()->json('login_caps');

    expect($caps())->toBe(['magic_link' => false, 'otp_sms' => false]);

    requestLink('a@x.hu');
    requestCode('a@x.hu');
    expect($caps())->toBe(['magic_link' => false, 'otp_sms' => false], 'a full limit shows once it turned someone away');

    requestLink('nobody@x.hu');
    requestCode('nobody@x.hu');
    expect($caps())->toBe(['magic_link' => false, 'otp_sms' => false], 'an address that would get nothing turns nobody away');

    requestCode('b@x.hu');
    expect($caps())->toBe(['magic_link' => false, 'otp_sms' => true]);
    requestLink('b@x.hu');
    expect($caps())->toBe(['magic_link' => true, 'otp_sms' => true]);

    $this->travel(61)->minutes();
    expect($caps())->toBe(['magic_link' => false, 'otp_sms' => false]);
});

it('offers the limits in the portal sign-in block, the e-mail one generous, the SMS ones strict', function (): void {
    $valid = fn (SettingKey $key, mixed $value): bool => Validator::make(['value' => $value], ['value' => SettingRules::for($key)])->passes();

    expect(SettingKey::ClientLoginMagicLinkMaxPerHour->default())->toBe(10000)
        ->and(SettingKey::ClientLoginOtpSmsMaxPerHour->default())->toBe(60)
        ->and(SettingKey::ClientLoginOtpSmsMaxPerDay->default())->toBe(300)
        ->and(SettingKey::ClientLoginOtpSmsMaxPerNumberPerDay->default())->toBe(5)
        ->and(SettingKey::ClientLoginMagicLinkMaxPerHour->dependsOn())->toBe(SettingKey::ClientLoginMagicLinkEnabled)
        ->and(SettingKey::ClientLoginOtpSmsMaxPerHour->dependsOn())->toBe(SettingKey::ClientLoginOtpSmsEnabled)
        ->and(SettingKey::ClientLoginOtpSmsMaxPerDay->dependsOn())->toBe(SettingKey::ClientLoginOtpSmsEnabled)
        ->and(SettingKey::ClientLoginOtpSmsMaxPerNumberPerDay->dependsOn())->toBe(SettingKey::ClientLoginOtpSmsEnabled);

    foreach ([SettingKey::ClientLoginMagicLinkMaxPerHour, SettingKey::ClientLoginOtpSmsMaxPerHour, SettingKey::ClientLoginOtpSmsMaxPerDay, SettingKey::ClientLoginOtpSmsMaxPerNumberPerDay] as $key) {
        expect($key->section())->toBe(SettingSection::PortalSignIn)
            ->and($valid($key, $key->default()))->toBeTrue($key->value)
            ->and($valid($key, -1))->toBeFalse($key->value)
            ->and($key->details())->not->toBeNull();
    }

    expect($valid(SettingKey::ClientLoginMagicLinkMaxPerHour, 0))->toBeTrue('0: no limit')
        ->and($valid(SettingKey::ClientLoginMagicLinkMaxPerHour, 1000000))->toBeTrue()
        ->and($valid(SettingKey::ClientLoginMagicLinkMaxPerHour, 1000001))->toBeFalse()
        ->and($valid(SettingKey::ClientLoginOtpSmsMaxPerHour, 0))->toBeFalse('the SMS limits cannot be switched off')
        ->and($valid(SettingKey::ClientLoginOtpSmsMaxPerDay, 0))->toBeFalse()
        ->and($valid(SettingKey::ClientLoginOtpSmsMaxPerNumberPerDay, 0))->toBeFalse()
        ->and($valid(SettingKey::ClientLoginOtpSmsMaxPerNumberPerDay, 101))->toBeFalse()
        ->and(SettingKey::ClientLoginMagicLinkMaxPerHour->details())->toContain('Exchange Online');
});
