<?php

use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Auth\Passwordless\OneTimeCodes;
use App\Enums\OneTimeCodePurpose;
use App\Enums\PhoneNumberSource;
use App\Enums\PhoneVerificationSource;
use App\Mail\MagicLinkMail;
use App\Messaging\MessageKey;
use App\Messaging\Messenger;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\MessageTemplate;
use App\Models\OneTimeCode;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\FakeSmsSender;
use App\Sms\SmsSender;
use App\System\Installation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

it('counts a guess at a login code before checking it, so overlapping guesses cannot go past the attempt limit', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    app(Settings::class)->set(SettingKey::ClientLoginOtpMaxAttempts, 2);
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);
    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu']);
    preg_match('/(\d{6})/', $this->sms->lastTo('+36301234567'), $m);

    // Other requests use up the last attempts after this one has read the code.
    OneTimeCode::retrieved(function (OneTimeCode $code): void {
        DB::table('one_time_codes')->where('id', $code->id)->update(['attempts' => 2]);
    });

    try {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'c@x.hu', 'code' => $m[1]])->assertForbidden();
    } finally {
        OneTimeCode::flushEventListeners();
    }

    $this->assertGuest('client');
    expect(OneTimeCode::query()->first()->consumed_at)->toBeNull();
});

it('checks one login code of a client at a time, under the client lock', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);
    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu']);

    $lockWasFree = null;
    OneTimeCode::retrieved(function () use ($client, &$lockWasFree): void {
        $probe = Cache::lock(Installation::lockName('hdid:login-otp:'.$client->id), 1);
        $lockWasFree ??= $probe->get();
        if ($lockWasFree) {
            $probe->release();
        }
    });

    try {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'c@x.hu', 'code' => '000000'])->assertForbidden();
    } finally {
        OneTimeCode::flushEventListeners();
    }

    expect($lockWasFree)->toBeFalse('the code is read and judged while the client lock is held');
});

it('treats an address that only the database collation equates with an account as unknown', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    $client = Client::factory()->synced()->create(['email' => 'victim@x.hu']);
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);

    // MariaDB's accent- and case-insensitive collation finds the account for
    // a look-alike address; SQLite compares bytes, so the row is altered here.
    $storedAs = fn (Model $found) => $found->setRawAttributes(['email' => 'víctim@x.hu'] + $found->getAttributes(), sync: true);
    Client::retrieved($storedAs);
    ExternalRecord::retrieved($storedAs);

    try {
        $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'victim@x.hu'])->assertOk();
        $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'victim@x.hu'])->assertOk();
    } finally {
        Client::flushEventListeners();
        ExternalRecord::flushEventListeners();
    }

    Mail::assertNothingSent();
    expect($this->sms->lastTo('+36301234567'))->toBeNull()
        ->and(AuditLog::query()->where('event', 'login.rejected')->where('context->reason', 'no_account')->count())->toBe(2);
});

it('spends the same hashing work on an unknown address as on a real one, so the answer time tells nothing', function (string $path): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    Hash::spy();

    $this->postJson('/api/v1/client/auth/'.$path, ['email' => 'nobody@x.hu'])->assertOk();

    Hash::shouldHaveReceived('make')->once();
})->with([
    'login link' => ['magic-link'],
    'sms code' => ['otp/request'],
]);

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
    $request = fn () => $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk()->assertJsonPath('message', __('If the account exists and has a number that can receive sign-in codes, a code has been sent to it. Only the newest code works: a new request replaces the earlier one.'));

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

/**
 * A client who may use the SMS sign-in, with a directory number.
 */
function smsSignInClient(): Client
{
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);

    return $client;
}

it('spends the same hashing work on every refused SMS code check, so the answer time does not tell an eligible account from any other', function (string $case, int $makes, int $checks): void {
    $client = smsSignInClient();

    if ($case !== 'unknown address' && $case !== 'no live code') {
        app(OneTimeCodes::class)->issueNumeric($client, OneTimeCodePurpose::LoginOtp, 6, 5, 3, '+36301234567');
    }

    if ($case === 'locked account') {
        $client->forceFill(['locked_until' => now()->addHour()])->save();
    }

    Hash::spy();
    $email = $case === 'unknown address' ? 'nobody@x.hu' : 'c@x.hu';
    $this->postJson('/api/v1/client/auth/otp/verify', ['email' => $email, 'code' => '000000'])->assertForbidden();

    $makes === 0 ? Hash::shouldNotHaveReceived('make') : Hash::shouldHaveReceived('make')->times($makes);
    $checks === 0 ? Hash::shouldNotHaveReceived('check') : Hash::shouldHaveReceived('check')->times($checks);
})->with([
    ['unknown address', 2, 0],
    ['no live code', 2, 0],
    ['locked account', 2, 0],
    ['wrong guess', 1, 1],
]);

it('does not count a guess with the client\'s own code that a newer request replaced towards the lockout', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    $client = smsSignInClient();
    $this->withHeader('Referer', config('app.url'));

    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();
    preg_match('/(\d{6})/', (string) $this->sms->lastTo('+36301234567'), $first);
    $this->travel(61)->seconds();
    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();

    $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'c@x.hu', 'code' => $first[1]])
        ->assertForbidden()->assertJsonPath('reason', 'invalid_credentials');

    expect($client->fresh()->failed_login_attempts)->toBe(0)
        ->and(AuditLog::query()->where('event', 'login.rejected')->where('context->detail', 'replaced_code')->count())->toBe(1);
});

it('still counts a wrong guess that matches no recent code of the client', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    $client = smsSignInClient();
    $this->withHeader('Referer', config('app.url'));

    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();
    preg_match('/(\d{6})/', (string) $this->sms->lastTo('+36301234567'), $code);
    $wrong = str_pad((string) (((int) $code[1] + 1) % 1000000), 6, '0', STR_PAD_LEFT);

    $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'c@x.hu', 'code' => $wrong])->assertForbidden();

    expect($client->fresh()->failed_login_attempts)->toBe(1);
});

it('counts a guess with a replaced code once that code has expired', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsTtlMinutes, 5);
    $client = smsSignInClient();
    $this->withHeader('Referer', config('app.url'));

    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();
    preg_match('/(\d{6})/', (string) $this->sms->lastTo('+36301234567'), $first);
    $this->travel(3)->minutes();
    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();
    // The first code ran out; the second still lives.
    $this->travel(3)->minutes();

    $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'c@x.hu', 'code' => $first[1]])->assertForbidden();

    expect($client->fresh()->failed_login_attempts)->toBe(1);
});

it('tells the client after a request that only the newest link or code works', function (): void {
    smsSignInClient();

    $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'c@x.hu'])->assertOk()
        ->assertJsonPath('message', __('If the account exists, a login link has been sent. Only the newest link works: a new request replaces the earlier one.'));
    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk()
        ->assertJsonPath('message', __('If the account exists and has a number that can receive sign-in codes, a code has been sent to it. Only the newest code works: a new request replaces the earlier one.'));
});

it('names the number source and the staff member who vouched for it in the audit entry of an SMS sign-in', function (): void {
    $staff = User::factory()->create();
    $client = smsSignInClient();
    $client->phoneNumbers()->sole()->forceFill(['verified_at' => now(), 'verified_via' => PhoneVerificationSource::Staff, 'verified_by_user_id' => $staff->id])->save();
    $this->withHeader('Referer', config('app.url'));

    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();
    preg_match('/(\d{6})/', (string) $this->sms->lastTo('+36301234567'), $code);
    $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'c@x.hu', 'code' => $code[1]])->assertOk();

    $succeeded = AuditLog::query()->where('event', 'login.succeeded')->sole();
    expect($succeeded->context)->toMatchArray([
        'method' => 'otp_sms',
        'number_source' => 'admin',
        'number_verified_via' => 'staff',
        'number_vouched_by' => $staff->id,
    ]);
});

it('sends no SMS sign-in code to a number the client added and nobody confirmed, even when it is the primary one', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::ClientSelf, 'is_primary' => true]);

    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();

    expect($this->sms->sent)->toBe([])
        ->and(AuditLog::query()->where('event', 'login.otp_refused')->sole()->context)->toMatchArray(['reason' => 'no_sign_in_number']);
});

it('sends the SMS sign-in code to a vouched number when the primary one is a self-added, unconfirmed number', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    // Somebody signed in as the client added their own number and made it primary.
    $client->phoneNumbers()->create(['number_e164' => '+36309999999', 'source' => PhoneNumberSource::ClientSelf, 'is_primary' => true]);
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Sync, 'is_primary' => false]);

    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();

    expect(array_column($this->sms->sent, 'to'))->toBe(['+36301234567']);
});

it('sends the SMS sign-in code to directory, helpdesk-added and confirmed numbers', function (PhoneNumberSource $source, ?PhoneVerificationSource $via): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => $source, 'is_primary' => true, 'verified_at' => $via === null ? null : now(), 'verified_via' => $via]);

    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();

    expect(array_column($this->sms->sent, 'to'))->toBe(['+36301234567']);
})->with([
    'from the directory' => [PhoneNumberSource::Sync, null],
    'added by the helpdesk' => [PhoneNumberSource::Admin, null],
    'confirmed by SMS' => [PhoneNumberSource::ClientSelf, PhoneVerificationSource::Sms],
    'confirmed by the helpdesk' => [PhoneNumberSource::ClientSelf, PhoneVerificationSource::Staff],
    'confirmed by an identified call' => [PhoneNumberSource::ClientSelf, PhoneVerificationSource::IdentifiedCall],
]);

it('checks and sends a client\'s login codes under the client lock', function (): void {
    $client = smsSignInClient();
    $lockWasFree = null;
    OutboundMessage::creating(function () use (&$lockWasFree, $client): void {
        $lock = Cache::lock(Installation::lockName('sms-budget:client:'.$client->id), 1);
        $lockWasFree = $lock->get();

        if ($lockWasFree) {
            $lock->release();
        }
    });

    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();

    expect($lockWasFree)->toBeFalse();
});
