<?php

use App\Auth\AccountLogin;
use App\Auth\Passwordless\OneTimeCodes;
use App\Auth\Role;
use App\CallCenter\CallCenterService;
use App\Enums\ApiKeyScope;
use App\Enums\IdMethod;
use App\Enums\IdSessionStatus;
use App\Enums\OneTimeCodePurpose;
use App\Enums\PhoneNumberSource;
use App\Filament\Admin\Pages\Identify;
use App\Identification\ClientAnswers;
use App\Identification\CurrentIdentification;
use App\Identification\MobileOtpService;
use App\Identification\QaRetryLimit;
use App\Identification\QaSessionEngine;
use App\Mail\MagicLinkMail;
use App\Messaging\MessageKey;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\OneTimeCode;
use App\Models\OutboundMessage;
use App\Models\Question;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\FakeSmsSender;
use App\Sms\SmsSender;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\RepeatedHourClock;

/*
 * On 2026-10-25 the clocks in Europe/Budapest go back: 02:00–03:00 passes
 * twice. Stored times are wall-clock text, so a limit compared as text
 * would treat the second pass as the first. Every limit here must measure
 * real elapsed time. The clock below can stand in the first pass; it is
 * moved with explicit UTC instants only.
 */

beforeEach(function (): void {
    Date::use(RepeatedHourClock::class);
    Mail::fake();
    $this->sms = new FakeSmsSender;
    app()->instance(SmsSender::class, $this->sms);
    // The RateLimiter reads the plain Carbon clock, which cannot stand in
    // the first pass; the limits under test here are the stored ones.
    $this->withoutMiddleware(ThrottleRequests::class);
});

afterEach(function (): void {
    Date::useDefault();
});

function repeatedHourAt(string $utc): void
{
    test()->travelTo(Carbon::parse($utc, 'UTC'));
}

/** A UTC instant as the app stores it, so the model fills the epoch twin. */
function repeatedHourInstant(string $utc): Carbon
{
    return Carbon::parse($utc, 'UTC')->setTimezone(config('app.timezone'));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function repeatedHourDecidedSession(Client $client, User $agent, IdSessionStatus $status, string $decidedUtc, array $attributes = []): IdSession
{
    return IdSession::factory()->create([
        'client_id' => $client->id,
        'agent_user_id' => $agent->id,
        'status' => $status,
        'method' => IdMethod::Manual,
        'started_at' => repeatedHourInstant($decidedUtc),
        'decided_at' => repeatedHourInstant($decidedUtc),
        ...$attributes,
    ]);
}

function repeatedHourAgent(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $agent = User::factory()->withRole(Role::Agent)->create();
    test()->actingAs($agent);
    Filament::setCurrentPanel('admin');

    return $agent;
}

function repeatedHourSmsClient(): Client
{
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);

    return $client;
}

it('stands the test clock in the first pass of the repeated hour', function (): void {
    repeatedHourAt('2026-10-25 00:57:00');

    expect(now()->format('H:i P'))->toBe('02:57 +02:00')
        ->and(now()->addMinutes(5)->format('H:i P'))->toBe('02:02 +01:00');
});

it('refuses a login link in the repeated hour once it expired in the first pass of that hour', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginMagicLinkTtlMinutes, 720);
    Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $this->withHeader('Referer', config('app.url'));

    repeatedHourAt('2026-10-24 12:30:00');
    $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'c@x.hu'])->assertOk();
    $url = null;
    Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $mail) use (&$url): bool {
        $url = $mail->url;

        return true;
    });

    // 02:15 CET: 45 minutes after the link really expired (02:30 CEST).
    repeatedHourAt('2026-10-25 01:15:00');
    signInWithLink($url)->assertForbidden()->assertJsonPath('reason', 'invalid_credentials');

    $this->assertGuest('client');
    expect(AuditLog::query()->where('event', 'login.succeeded')->exists())->toBeFalse();
});

it('refuses an SMS login code in the repeated hour once it expired in the first pass', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsTtlMinutes, 5);
    repeatedHourSmsClient();
    $this->withHeader('Referer', config('app.url'));

    // 01:58 CEST; the code expires at 02:03 CEST.
    repeatedHourAt('2026-10-24 23:58:00');
    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();
    preg_match('/(\d{6})/', (string) $this->sms->lastTo('+36301234567'), $m);

    // 02:02 CET, an hour after the expiry.
    repeatedHourAt('2026-10-25 01:02:00');
    $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'c@x.hu', 'code' => $m[1]])
        ->assertForbidden()->assertJsonPath('reason', 'invalid_credentials');

    $this->assertGuest('client');
});

it('refuses a portal code and an IVR code typed into the phone menu in the repeated hour once they expired in the first pass', function (): void {
    app(Settings::class)->set(SettingKey::MobileOtpTtlMinutes, 5);
    $client = Client::factory()->synced()->create();
    $otp = app(MobileOtpService::class);

    repeatedHourAt('2026-10-24 23:58:00');
    $portalCode = $otp->issue($client, 'client')['code'];
    $ivrCode = $otp->issue($client, null, OneTimeCodePurpose::IvrCode)['code'];

    repeatedHourAt('2026-10-25 01:02:00');
    Sanctum::actingAs($client, guard: 'client');
    $this->getJson('/api/v1/client/mobile-code')->assertOk()->assertJsonPath('code', null);

    $calls = app(CallCenterService::class);
    expect($calls->ivrVerifyCode(null, $portalCode)->result)->toBe('unknown_code')
        ->and($calls->ivrVerifyCode(null, $ivrCode)->result)->toBe('unknown_code')
        ->and(IdSession::query()->where('status', IdSessionStatus::Passed)->exists())->toBeFalse();
});

it('refuses a phone number confirmation code in the repeated hour once it expired in the first pass', function (): void {
    app(Settings::class)->set(SettingKey::PortalPhoneVerificationEnabled, true);
    $client = Client::factory()->synced()->create();
    $phone = $client->phoneNumbers()->create(['number_e164' => '+36202222222', 'source' => PhoneNumberSource::ClientSelf]);
    Sanctum::actingAs($client, guard: 'client');

    // 01:58 CEST; the ten-minute code expires at 02:08 CEST.
    repeatedHourAt('2026-10-24 23:58:00');
    $this->postJson('/api/v1/client/phone-numbers/'.$phone->id.'/verify/request')->assertOk();
    preg_match('/(\d{6})/', (string) $this->sms->lastTo('+36202222222'), $m);

    // 02:02 CET, 54 minutes after the expiry.
    repeatedHourAt('2026-10-25 01:02:00');
    $this->postJson('/api/v1/client/phone-numbers/'.$phone->id.'/verify', ['code' => $m[1]])
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);

    expect($phone->fresh()->verified_at)->toBeNull()
        ->and(AuditLog::query()->where('event', 'client_phone.verification_failed')->count())->toBe(1);
});

it('does not consume a secret that expires while its hash is being checked', function (): void {
    $client = Client::factory()->synced()->create();
    $codes = app(OneTimeCodes::class);
    $real = app('hash')->driver();
    Hash::swap(new class($real, fn () => $this->travel(10)->minutes()) implements Hasher
    {
        public function __construct(private Hasher $real, private Closure $whileChecking) {}

        public function info($hashedValue): array
        {
            return $this->real->info($hashedValue);
        }

        public function make($value, array $options = []): string
        {
            return $this->real->make($value, $options);
        }

        public function check($value, $hashedValue, array $options = []): bool
        {
            ($this->whileChecking)();

            return $this->real->check($value, $hashedValue, $options);
        }

        public function needsRehash($hashedValue, array $options = []): bool
        {
            return $this->real->needsRehash($hashedValue, $options);
        }
    });

    $token = $codes->issueToken($client, OneTimeCodePurpose::MagicLink, 5)['token'];
    expect($codes->consume(OneTimeCodePurpose::MagicLink, $token))->toBeNull();

    $this->travelBack();
    $code = $codes->issueNumeric($client, OneTimeCodePurpose::LoginOtp, 6, 5, 3)['code'];
    expect($codes->verify($client, OneTimeCodePurpose::LoginOtp, $code))->toBeFalse()
        ->and(OneTimeCode::query()->whereNotNull('consumed_at')->exists())->toBeFalse();
});

it('counts the login codes sent in the repeated hour towards the hourly budget of the number', function (): void {
    $settings = app(Settings::class);
    $settings->set(SettingKey::ClientLoginOtpSmsMaxPerNumberPerMinute, 1);
    $settings->set(SettingKey::ClientLoginOtpSmsMaxPerNumberPerHour, 3);
    $settings->set(SettingKey::ClientLoginOtpSmsMaxPerNumberPerDay, 10);
    $client = repeatedHourSmsClient();

    // 02:01, 02:02, 02:03 and 02:04 CET.
    foreach (['01:01:00', '01:02:01', '01:03:02', '01:04:03'] as $at) {
        repeatedHourAt('2026-10-25 '.$at);
        $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();
    }

    expect(collect($this->sms->sent)->where('to', '+36301234567'))->toHaveCount(3);
    $refused = AuditLog::query()->where('event', 'message.refused')->where('subject_id', $client->id)->first();
    expect($refused?->context['reason'] ?? null)->toBe('sms_limit_per_hour')
        ->and($refused?->context['limit'] ?? null)->toBe(3);
});

it('keeps the codes sent in the repeated hour in the daily budget of the number until a day has passed', function (): void {
    $settings = app(Settings::class);
    $settings->set(SettingKey::ClientLoginOtpSmsMaxPerNumberPerMinute, 1);
    $settings->set(SettingKey::ClientLoginOtpSmsMaxPerNumberPerHour, 60);
    $settings->set(SettingKey::ClientLoginOtpSmsMaxPerNumberPerDay, 5);
    $client = repeatedHourSmsClient();

    foreach (['01:01:00', '01:02:01', '01:03:02', '01:04:03', '01:05:04'] as $at) {
        repeatedHourAt('2026-10-25 '.$at);
        $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();
    }

    // 01:30 CET the next day, 23.5 hours after the first code.
    repeatedHourAt('2026-10-26 00:30:00');
    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();

    expect(collect($this->sms->sent)->where('to', '+36301234567'))->toHaveCount(5);
    $refused = AuditLog::query()->where('event', 'message.refused')->where('subject_id', $client->id)->first();
    expect($refused?->context['reason'] ?? null)->toBe('sms_limit_per_day');
});

it('records the true instant of a message created with an explicit time', function (): void {
    $message = OutboundMessage::factory()->create([
        'template_key' => MessageKey::LoginOtpSms,
        'created_at' => Carbon::parse('2026-10-25 00:30:00', 'UTC')->setTimezone('Europe/Budapest'),
    ]);

    expect($message->getAttributes()['created_unix'])->toBe(Carbon::parse('2026-10-25 00:30:00', 'UTC')->getTimestamp())
        ->and($message->fresh()->getRawOriginal('created_at'))->toBe('2026-10-25 02:30:00');
});

it('ends a bearer token issued in the first pass of the repeated hour after exactly the configured lifetime', function (): void {
    config(['sanctum.expiration' => 43200]);
    $client = Client::factory()->synced()->create();

    repeatedHourAt('2026-10-25 00:30:00');
    $token = app(AccountLogin::class)->issueToken($client, 'test', 'phone');

    // 30 days and 15 minutes later.
    repeatedHourAt('2026-11-24 00:45:00');
    $this->withToken($token)->getJson('/api/v1/client/me')->assertUnauthorized();
});

it('still ends tokens early when the configured token lifetime is shortened', function (): void {
    config(['sanctum.expiration' => 43200]);
    $client = Client::factory()->synced()->create();
    $token = app(AccountLogin::class)->issueToken($client, 'test', 'phone');

    config(['sanctum.expiration' => 60]);
    $this->app['auth']->forgetGuards();
    $this->travel(2)->hours();

    $this->withToken($token)->getJson('/api/v1/client/me')->assertUnauthorized();
});

it('does not lengthen a bearer token already issued when the configured lifetime is raised', function (): void {
    config(['sanctum.expiration' => 60]);
    $client = Client::factory()->synced()->create();
    $token = app(AccountLogin::class)->issueToken($client, 'test', 'phone');

    config(['sanctum.expiration' => 43200]);
    $this->app['auth']->forgetGuards();
    $this->travel(2)->hours();

    $this->withToken($token)->getJson('/api/v1/client/me')->assertUnauthorized();
});

it('stops treating a pass made in the first pass of the repeated hour as current thirty minutes after the decision', function (): void {
    $agent = repeatedHourAgent();
    $client = Client::factory()->synced()->create();
    repeatedHourDecidedSession($client, $agent, IdSessionStatus::Passed, '2026-10-25 00:40:00');

    // 03:05 CET, 85 minutes after the decision (02:40 CEST).
    repeatedHourAt('2026-10-25 02:05:00');
    $current = app(CurrentIdentification::class);

    expect($current->forAgent($agent))->toBeNull()
        ->and($current->forClient($agent, $client, null))->toBeNull();
    Livewire::test(Identify::class, ['client' => $client->id])->assertSet('clientId', $client->id)
        ->tap(fn ($livewire) => expect($livewire->instance()->getCurrentPassed())->toBeNull());
});

it('takes the later of two passes in the repeated hour as the agent\'s current identification', function (): void {
    $agent = repeatedHourAgent();
    $first = repeatedHourDecidedSession(Client::factory()->synced()->create(), $agent, IdSessionStatus::Passed, '2026-10-25 00:50:00');
    $later = repeatedHourDecidedSession(Client::factory()->synced()->create(), $agent, IdSessionStatus::Passed, '2026-10-25 01:10:00');

    repeatedHourAt('2026-10-25 01:15:00');

    expect(app(CurrentIdentification::class)->forAgent($agent)?->id)->toBe($later->id)
        ->and($first->fresh()->decided_at->lt($later->fresh()->decided_at))->toBeTrue();
});

it('takes the later of two outcomes in the repeated hour as the latest outcome on the identification page', function (): void {
    $agent = repeatedHourAgent();
    $client = Client::factory()->synced()->create();
    repeatedHourDecidedSession($client, $agent, IdSessionStatus::Failed, '2026-10-25 00:50:00');
    $passed = repeatedHourDecidedSession($client, $agent, IdSessionStatus::Passed, '2026-10-25 01:10:00');

    repeatedHourAt('2026-10-25 01:15:00');

    expect(app(CurrentIdentification::class)->latestOutcome($agent, $client, null)?->id)->toBe($passed->id);
    Livewire::test(Identify::class, ['client' => $client->id])
        ->tap(fn ($livewire) => expect($livewire->instance()->getLatestOutcome()?->id)->toBe($passed->id));
});

it('keeps the unsuccessful question-and-answer sessions of the repeated hour in the retry limit for the whole period', function (): void {
    app(Settings::class)->set(SettingKey::QaMaxUnsuccessfulSessions, 3);
    app(Settings::class)->set(SettingKey::QaUnsuccessfulWindowHours, 24);
    $agent = User::factory()->create();
    $client = Client::factory()->synced()->create();
    foreach (['01:00:00', '01:05:00', '01:10:00'] as $at) {
        repeatedHourDecidedSession($client, $agent, IdSessionStatus::Failed, '2026-10-25 '.$at, ['method' => IdMethod::QuestionAnswer]);
    }

    // 01:15 CET the next day: the window starts at 02:15 CEST, before the three.
    repeatedHourAt('2026-10-26 00:15:00');
    $limit = app(QaRetryLimit::class);

    expect($limit->unsuccessfulCount($client))->toBe(3)
        ->and($limit->recentUnsuccessfulAttempts($client))->toBe(3)
        ->and($limit->lockedUntil($client)?->getTimestamp())->toBe(Carbon::parse('2026-10-26 01:00:00', 'UTC')->getTimestamp());
});

it('does not count sessions decided in the second of the unlock', function (): void {
    app(Settings::class)->set(SettingKey::QaMaxUnsuccessfulSessions, 3);
    $this->travelTo(Carbon::parse('2026-11-03 10:00:00', 'UTC'));
    $agent = User::factory()->create();
    $client = Client::factory()->synced()->create();
    foreach (range(1, 3) as $i) {
        IdSession::factory()->create(['client_id' => $client->id, 'agent_user_id' => $agent->id, 'status' => IdSessionStatus::Failed, 'decided_at' => now()]);
    }
    $limit = app(QaRetryLimit::class);
    expect($limit->lockedUntil($client))->not->toBeNull();

    $limit->unlock($client, $agent);

    expect($limit->lockedUntil($client))->toBeNull()
        ->and($limit->unsuccessfulCount($client))->toBe(0);
});

it('accepts a login link issued the afternoon before until its real expiry and refuses it afterwards', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginMagicLinkTtlMinutes, 720);
    $first = Client::factory()->synced()->create(['email' => 'a@x.hu']);
    Client::factory()->synced()->create(['email' => 'b@x.hu']);
    $this->withHeader('Referer', config('app.url'));

    // 15:30 CEST; both links expire at 02:30 CET.
    repeatedHourAt('2026-10-24 13:30:00');
    $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'a@x.hu'])->assertOk();
    $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'b@x.hu'])->assertOk();
    $urls = [];
    Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $mail) use (&$urls): bool {
        $urls[$mail->client->email] = $mail->url;

        return true;
    });

    // 02:45 CEST, 45 minutes before the real expiry.
    repeatedHourAt('2026-10-25 00:45:00');
    signInWithLink($urls['a@x.hu'])->assertOk()->assertJsonPath('data.id', $first->id);
    auth('client')->logout();

    // 02:31 CET, a minute after it.
    repeatedHourAt('2026-10-25 01:31:00');
    signInWithLink($urls['b@x.hu'])->assertForbidden()->assertJsonPath('reason', 'invalid_credentials');
    expect(OneTimeCode::query()->where('purpose', OneTimeCodePurpose::MagicLink)->whereNull('consumed_at')->count())->toBe(1);
});

it('accepts an SMS login code issued in the last minutes of the first pass of the repeated hour', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsTtlMinutes, 5);
    $client = repeatedHourSmsClient();
    $this->withHeader('Referer', config('app.url'));

    // 02:57 CEST; the code expires at 02:02 CET.
    repeatedHourAt('2026-10-25 00:57:00');
    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();
    preg_match('/(\d{6})/', (string) $this->sms->lastTo('+36301234567'), $m);

    repeatedHourAt('2026-10-25 00:59:00');
    $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'c@x.hu', 'code' => $m[1]])
        ->assertOk()->assertJsonPath('data.id', $client->id);
});

it('keeps a question-and-answer session started in the first pass open for its whole lifetime', function (): void {
    app(Settings::class)->set(SettingKey::QaSessionTtlMinutes, 30);
    $questions = Question::factory()->count(6)->create();
    $client = Client::factory()->synced()->create();
    foreach ($questions as $i => $question) {
        app(ClientAnswers::class)->save($client, $question, 'answer '.$i);
    }
    $agent = User::factory()->create();
    $engine = app(QaSessionEngine::class);

    // 02:40 CEST; the session ends at 02:10 CET.
    repeatedHourAt('2026-10-25 00:40:00');
    $session = $engine->start($client, $agent);

    repeatedHourAt('2026-10-25 00:41:00');
    expect($engine->expireStale())->toBe(0)
        ->and($session->fresh()->isOpen())->toBeTrue()
        ->and($session->fresh()->isExpired())->toBeFalse();
    $engine->next($session->fresh(), $agent);

    repeatedHourAt('2026-10-25 01:11:00');
    expect($engine->expireStale())->toBe(1)
        ->and($session->fresh()->status)->toBe(IdSessionStatus::Expired)
        ->and($session->fresh()->outcome_reason)->toBe('timeout');
});

it('tells the portal and the mobile backend the real expiry of a code', function (): void {
    app(Settings::class)->set(SettingKey::MobileOtpTtlMinutes, 5);
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    ['plain' => $plain, 'secret' => $secret] = ApiKey::generate('app', ApiKeyScope::MobileBackend);

    // 01:58 CEST; the code expires at 02:03 CEST.
    repeatedHourAt('2026-10-24 23:58:00');
    Sanctum::actingAs($client, guard: 'client');
    $this->postJson('/api/v1/client/mobile-code')->assertOk()->assertJsonPath('expires_at', '2026-10-25T02:03:00+02:00');

    repeatedHourAt('2026-10-24 23:59:00');
    $this->getJson('/api/v1/client/mobile-code')->assertOk()->assertJsonPath('expires_at', '2026-10-25T02:03:00+02:00');
    signedMachineCall($plain, $secret, 'POST', '/api/v1/mobile/ivr-code', ['email' => 'c@x.hu'])
        ->assertOk()->assertJsonPath('expires_at', '2026-10-25T02:04:00+02:00');
});
