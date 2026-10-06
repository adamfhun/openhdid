<?php

use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Auth\Passwordless\OneTimeCodes;
use App\Enums\OneTimeCodePurpose;
use App\Enums\PhoneNumberSource;
use App\Mail\MagicLinkMail;
use App\Messaging\MessageKey;
use App\Messaging\Messenger;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\MessageTemplate;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\FakeSmsSender;
use App\Sms\SmsSender;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

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

    // Opening the link (a mail scanner, or the client) signs nobody in.
    $this->get($url)->assertOk();
    $this->assertGuest('client');

    signInWithLink($url)->assertOk()->assertJsonPath('data.id', $client->id);
    $this->assertAuthenticatedAs($client, 'client');

    auth('client')->logout();
    signInWithLink($url)->assertForbidden()->assertJsonPath('reason', 'invalid_credentials');
    $this->assertGuest('client');
    expect(AuditLog::query()->where('event', 'login.rejected')->where('context->method', 'magic_link')->count())->toBe(1);
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

it('sends each client to the landing page of its own entry, valid for 12 hours', function (?string $package, string $path): void {
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu', 'implicit_package' => $package ?? 'Premium', 'explicit_package' => null]);

    $url = app(ClientPasswordlessLogin::class)->issueMagicLink($client);

    expect($url)->toStartWith(url($path).'#token=');
    Mail::assertSent(MagicLinkMail::class, fn (MagicLinkMail $mail) => str_contains($mail->render(), 'valid for 12 hours'));
    signInWithLink($url)->assertOk()->assertJsonPath('data.id', $client->id);
})->with([
    'premium client' => ['Premium', '/premium/login/link'],
    'standard client' => ['Basic', '/login/link'],
]);

it('leads a link sent before the landing page to it without using the link up', function (): void {
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    ['token' => $token] = app(OneTimeCodes::class)->issueToken($client, OneTimeCodePurpose::MagicLink, 60);
    $legacy = URL::temporarySignedRoute('client.magic-link', now()->addHour(), ['token' => $token]);

    $this->get($legacy)->assertRedirect(url('/premium/login/link').'#token='.$token);
    $this->get($legacy)->assertRedirect(url('/premium/login/link').'#token='.$token);
    $this->assertGuest('client');

    signInWithLink(url('/premium/login/link').'#token='.$token)->assertOk();
    $this->get($legacy)->assertRedirect('/login?error=invalid_credentials');
});

it('moves stored settings and login link e-mails to the 12-hour validity', function (): void {
    DB::table('settings')->updateOrInsert(['key' => 'client_login.magic_link.ttl_minutes'], ['value' => '4320']);
    DB::table('settings')->updateOrInsert(['key' => 'client_login.otp_sms.ttl_minutes'], ['value' => '4320']);
    MessageTemplate::query()->create(['key' => MessageKey::MagicLink, 'locale' => 'hu', 'subject' => 'x', 'body' => '<p>A link {{ days }} napig érvényes.</p>']);
    MessageTemplate::query()->create(['key' => MessageKey::MagicLink, 'locale' => 'en', 'subject' => 'x', 'body' => '<p>Valid for {{ days }} days.</p>']);

    (require database_path('migrations/2026_10_03_100001_shorten_magic_link_validity.php'))->up();
    app(Settings::class)->forget();

    expect(app(Settings::class)->int(SettingKey::ClientLoginMagicLinkTtlMinutes))->toBe(720)
        ->and(app(Settings::class)->int(SettingKey::ClientLoginOtpSmsTtlMinutes))->toBe(4320)
        ->and(MessageTemplate::query()->orderBy('locale')->pluck('body')->all())->toBe(['<p>Valid for {{ hours }} hours.</p>', '<p>A link {{ hours }} óráig érvényes.</p>']);
});

it('sends one login code a minute and three an hour to a number, refusing the rest silently with an audit entry', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);
    $request = fn () => $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk()->assertJsonPath('message', __('If the account exists and has a phone number, a code has been sent.'));

    $request();
    $request(); // a second one within the minute
    expect($this->sms->sent)->toHaveCount(1);

    $this->travel(61)->seconds();
    $request();
    $this->travel(61)->seconds();
    $request();
    expect($this->sms->sent)->toHaveCount(3);

    $this->travel(61)->seconds();
    $request(); // the fourth within the hour
    expect($this->sms->sent)->toHaveCount(3);

    $refused = AuditLog::query()->where('event', 'message.refused')->where('subject_id', $client->id)->get();
    expect($refused->map(fn (AuditLog $log) => $log->context['reason'])->all())->toBe(['sms_limit_per_minute', 'sms_limit_per_hour'])
        ->and($refused->first()->context)->toMatchArray(['channel' => 'sms', 'template_key' => 'login_otp_sms', 'recipient' => '+36301234567', 'limit' => 1])
        ->and($refused->last()->context['limit'])->toBe(3);

    $this->travel(58)->minutes(); // the first code is now more than an hour old
    $request();
    expect($this->sms->sent)->toHaveCount(4);
});

it('follows the configured per-number limits and other text messages are not limited', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsMaxPerNumberPerMinute, 2);
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsMaxPerNumberPerHour, 2);
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);

    foreach (range(1, 3) as $attempt) {
        $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();
    }
    expect($this->sms->sent)->toHaveCount(2);

    // A PIN text message to the same number is not part of the login-code budget.
    app(Messenger::class)->sendTemplate(MessageKey::PinSms, $client, ['pin' => '123456'], '+36301234567');
    expect($this->sms->sent)->toHaveCount(3);
});

it('tells the login page how to pace the SMS code and when the e-mailed link is a fallback', function (): void {
    $this->getJson('/api/v1/branding')->assertOk()
        ->assertJsonPath('otp_sms.retry_after_seconds', 60)
        ->assertJsonPath('otp_sms.max_per_hour', 3)
        ->assertJsonPath('otp_sms.email_fallback', true);

    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsMaxPerNumberPerMinute, 2);
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEmailFallbackEnabled, false);
    $this->getJson('/api/v1/branding')->assertOk()
        ->assertJsonPath('otp_sms.retry_after_seconds', 30)
        ->assertJsonPath('otp_sms.email_fallback', false);

    // The fallback needs the link login itself.
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEmailFallbackEnabled, true);
    app(Settings::class)->set(SettingKey::ClientLoginMagicLinkEnabled, false);
    $this->getJson('/api/v1/branding')->assertOk()->assertJsonPath('otp_sms.email_fallback', false);
});

it('applies the 2026-10-03 migrations more than once without harm', function (): void {
    DB::table('settings')->updateOrInsert(['key' => 'client_login.magic_link.ttl_minutes'], ['value' => '4320']);
    User::factory()->create(['remember_token' => 'old']);

    foreach (range(1, 2) as $round) {
        (require database_path('migrations/2026_10_03_100001_shorten_magic_link_validity.php'))->up();
        (require database_path('migrations/2026_10_03_100002_forget_remember_tokens.php'))->up();
    }
    app(Settings::class)->forget();

    expect(app(Settings::class)->int(SettingKey::ClientLoginMagicLinkTtlMinutes))->toBe(720)
        ->and(User::query()->whereNotNull('remember_token')->count())->toBe(0);
});
