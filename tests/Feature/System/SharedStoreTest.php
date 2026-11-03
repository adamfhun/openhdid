<?php

use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Auth\Passwordless\LoginMessageCaps;
use App\Auth\Role;
use App\Enums\IdSessionStatus;
use App\Identification\QaRetryLimit;
use App\Jobs\QueueHeartbeat;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\IdList;
use App\Sync\IdListException;
use App\System\CheckStatus;
use App\System\HealthChecks;
use App\System\Installation;
use App\System\InstallationRateLimiter;
use App\System\SessionStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;

/**
 * Two installations on one Valkey with the same key prefix: the test cache
 * store plays the shared store, the APP_KEY tells the installations apart.
 */
function sharedStoreProductionKey(): string
{
    return 'base64:'.base64_encode(str_repeat('p', 32));
}

function sharedStoreStagingKey(): string
{
    return 'base64:'.base64_encode(str_repeat('s', 32));
}

function actAsInstallation(string $appKey): void
{
    config()->set('app.key', $appKey);
}

function runSchedulerHeartbeat(): void
{
    collect(app(Schedule::class)->events())->sole(fn ($event): bool => $event->description === 'hdid:heartbeat')->run(app());
}

beforeEach(function (): void {
    // A database queue keeps the dispatched heartbeat jobs waiting, as on a real worker queue.
    config()->set('queue.default', 'database');
    actAsInstallation(sharedStoreProductionKey());
});

it('keeps the scheduler tile green for the scheduler of this installation', function (): void {
    runSchedulerHeartbeat();
    runSchedulerHeartbeat();

    expect(app(HealthChecks::class)->scheduler()->status)->toBe(CheckStatus::Ok);
});

it('turns the scheduler tile red when the last heartbeat came from the scheduler of another installation', function (): void {
    // Only the staging scheduler runs; the production one is stopped.
    actAsInstallation(sharedStoreStagingKey());
    runSchedulerHeartbeat();

    actAsInstallation(sharedStoreProductionKey());
    $check = app(HealthChecks::class)->scheduler();

    expect($check->status)->toBe(CheckStatus::Fail)
        ->and(strtolower($check->detail))->toContain('another installation');
});

it('turns the scheduler and queue tiles red while two installations write the same heartbeats', function (): void {
    (new QueueHeartbeat)->handle();

    // Both schedulers run, each one minute after the other wrote.
    actAsInstallation(sharedStoreProductionKey());
    runSchedulerHeartbeat();
    actAsInstallation(sharedStoreStagingKey());
    runSchedulerHeartbeat();
    actAsInstallation(sharedStoreProductionKey());
    runSchedulerHeartbeat();

    // The last heartbeat is this installation's own, yet the tiles stay red.
    $checks = app(HealthChecks::class);
    expect($checks->scheduler()->status)->toBe(CheckStatus::Fail)
        ->and(strtolower($checks->scheduler()->detail))->toContain('another installation')
        ->and($checks->queue()->status)->toBe(CheckStatus::Fail)
        ->and(strtolower($checks->queue()->detail))->toContain('another installation');
});

it('turns the queue tile red when a worker of another installation runs the heartbeat jobs of this one', function (): void {
    // The production worker is stopped; the staging worker takes the production jobs from the shared queue.
    $first = new QueueHeartbeat;
    $second = new QueueHeartbeat;
    runSchedulerHeartbeat();

    actAsInstallation(sharedStoreStagingKey());
    $first->handle();
    $second->handle();

    actAsInstallation(sharedStoreProductionKey());
    $check = app(HealthChecks::class)->queue();

    expect($check->status)->toBe(CheckStatus::Fail, 'the worker heartbeat is fresh, but it is not this installation\'s worker')
        ->and(strtolower($check->detail))->toContain('another installation');
});

it('does not raise the alarm for one heartbeat left from before an APP_KEY change', function (): void {
    actAsInstallation(sharedStoreStagingKey());
    runSchedulerHeartbeat();
    $leftOver = new QueueHeartbeat;

    // The key was replaced: the next minute's heartbeat and the last waiting job carry the old one.
    actAsInstallation(sharedStoreProductionKey());
    runSchedulerHeartbeat();
    $leftOver->handle();

    $checks = app(HealthChecks::class);
    expect($checks->scheduler()->status)->toBe(CheckStatus::Ok)
        ->and($checks->queue()->status)->toBe(CheckStatus::Ok);
});

it('still reads the heartbeats older releases and the message job write', function (): void {
    Cache::put(HealthChecks::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String());
    Cache::put(QueueHeartbeat::key('default'), now()->toIso8601String());

    $checks = app(HealthChecks::class);
    expect($checks->scheduler()->status)->toBe(CheckStatus::Ok)
        ->and($checks->queue()->status)->toBe(CheckStatus::Ok);
});

it('keeps the heartbeat jobs of two installations from blocking each other while they wait', function (): void {
    QueueHeartbeat::dispatch('default');
    actAsInstallation(sharedStoreStagingKey());
    QueueHeartbeat::dispatch('default');
    QueueHeartbeat::dispatch('default');

    expect(DB::table('jobs')->where('queue', 'default')->count())->toBe(2, 'one waiting heartbeat per installation');
});

it('warns while the keys on Valkey carry the prefix every installation of the same APP_NAME gets', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('command')->with('info')->andReturn(['used_memory' => 1_048_576, 'maxmemory' => 512 * 1_048_576, 'maxmemory_policy' => 'noeviction', 'evicted_keys' => 0]);
    Redis::shouldReceive('connection')->andReturn($connection);
    config()->set(['queue.default' => 'redis', 'app.name' => 'OpenHDID', 'database.redis.options.prefix' => 'openhdid-database-']);

    $check = app(HealthChecks::class)->valkey();
    expect($check->status)->toBe(CheckStatus::Warn)
        ->and($check->detail)->toContain('REDIS_PREFIX', 'openhdid-database-');

    config()->set('database.redis.options.prefix', 'openhdid-prod-');
    expect(app(HealthChecks::class)->valkey()->status)->toBe(CheckStatus::Ok);
});

it('gives the servers of one installation one id whatever way their APP_URL writes the default port or capitals', function (): void {
    config()->set('app.url', 'https://hdid.example.org');
    $id = Installation::id();

    foreach (['https://HDID.example.org/', 'https://hdid.example.org:443', 'https://hdid.example.org:443/'] as $url) {
        config()->set('app.url', $url);
        expect(Installation::id())->toBe($id, $url);
    }

    config()->set('app.url', 'https://hdid.example.org:8443');
    expect(Installation::id())->not->toBe($id);
});

it('counts the rate limits of each installation under its own id, once however often the framework cleans the key', function (): void {
    expect(app(CacheRateLimiter::class))->toBeInstanceOf(InstallationRateLimiter::class)
        ->and(RateLimiter::limiter('machine'))->not->toBeNull('the named limiters survive the binding');

    RateLimiter::hit('probe', 60);
    RateLimiter::hit('probe', 60);
    expect(RateLimiter::attempts('probe'))->toBe(2)
        ->and(RateLimiter::remaining('probe', 5))->toBe(3)
        ->and(RateLimiter::tooManyAttempts('probe', 2))->toBeTrue()
        ->and(RateLimiter::availableIn('probe'))->toBeGreaterThan(0)
        ->and(Cache::get(Installation::key('probe')))->toBe(2);

    actAsInstallation(sharedStoreStagingKey());
    expect(RateLimiter::attempts('probe'))->toBe(0)
        ->and(RateLimiter::tooManyAttempts('probe', 2))->toBeFalse();

    actAsInstallation(sharedStoreProductionKey());
    RateLimiter::clear('probe');
    expect(RateLimiter::attempts('probe'))->toBe(0);
});

it('keeps the per-address route limits of two installations apart, and the servers of one installation together', function (): void {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/client/auth/magic-link', ['email' => "nobody{$attempt}@x.hu"])->assertOk();
    }
    $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'nobody6@x.hu'])->assertStatus(429);

    // The staging installation on the same store, asked from the same address, counts on its own.
    actAsInstallation(sharedStoreStagingKey());
    $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'nobody7@x.hu'])->assertOk();

    // A second server of the production installation, its APP_URL written with a trailing slash: the same limit.
    actAsInstallation(sharedStoreProductionKey());
    config()->set('app.url', rtrim((string) config('app.url'), '/').'/');
    $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'nobody8@x.hu'])->assertStatus(429);
});

it('keeps the system-wide SMS ceiling of one installation from counting the codes of another on the same store', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsMaxPerHour, 1);
    $caps = app(LoginMessageCaps::class);

    expect($caps->admit(LoginMessageCaps::CHANNEL_SMS, ClientPasswordlessLogin::METHOD_OTP_SMS))->toBeTrue()
        ->and($caps->admit(LoginMessageCaps::CHANNEL_SMS, ClientPasswordlessLogin::METHOD_OTP_SMS))->toBeFalse('the ceiling still holds within one installation');

    actAsInstallation(sharedStoreStagingKey());
    expect($caps->admit(LoginMessageCaps::CHANNEL_SMS, ClientPasswordlessLogin::METHOD_OTP_SMS))->toBeTrue();
});

it('keeps the login cap notice of one installation off the login page and the messages tile of another on the same store', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsMaxPerHour, 1);
    $caps = app(LoginMessageCaps::class);
    $caps->admit(LoginMessageCaps::CHANNEL_SMS, ClientPasswordlessLogin::METHOD_OTP_SMS);
    $caps->admit(LoginMessageCaps::CHANNEL_SMS, ClientPasswordlessLogin::METHOD_OTP_SMS);

    expect(LoginMessageCaps::reached(LoginMessageCaps::CHANNEL_SMS))->not->toBeNull()
        ->and(app(HealthChecks::class)->outboundMessages()->status)->toBe(CheckStatus::Warn)
        ->and($this->getJson('/api/v1/branding')->json('login_caps.otp_sms'))->toBeTrue();

    actAsInstallation(sharedStoreStagingKey());
    expect(LoginMessageCaps::reached(LoginMessageCaps::CHANNEL_SMS))->toBeNull()
        ->and(app(HealthChecks::class)->outboundMessages()->status)->toBe(CheckStatus::Ok)
        ->and($this->getJson('/api/v1/branding')->json('login_caps.otp_sms'))->toBeFalse();

    // Its own refusal is audited even within the minute the other one's was.
    $caps->admit(LoginMessageCaps::CHANNEL_SMS, ClientPasswordlessLogin::METHOD_OTP_SMS);
    $caps->admit(LoginMessageCaps::CHANNEL_SMS, ClientPasswordlessLogin::METHOD_OTP_SMS);
    expect(AuditLog::query()->where('event', 'login.request_capped')->count())->toBe(2);
});

it('keeps the unlock mark of the question-and-answer limit, a shown login link and the ID list status to the installation that wrote them', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $client = Client::factory()->synced()->create();
    $agent = User::factory()->withRole(Role::Agent)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    app(Settings::class)->set(SettingKey::QaMaxUnsuccessfulSessions, 1);
    IdSession::factory()->create(['client_id' => $client->id, 'agent_user_id' => $agent->id, 'status' => IdSessionStatus::Failed, 'decided_at' => now()->subMinute()]);
    $limit = app(QaRetryLimit::class);
    expect($limit->lockedUntil($client))->not->toBeNull();

    // Production lifts the lock and shows a login link in its panel; its ID list query fails.
    $this->travel(1)->seconds();
    expect($limit->unlock($client, $admin))->toBeTrue();
    $link = app(ClientPasswordlessLogin::class)->issueMagicLink($client, 'staff@x.hu', revealed: true);
    expect(fn () => app(IdList::class)->refresh('token'))->toThrow(IdListException::class);
    expect(app(IdList::class)->status())->toHaveKey('error');

    // A copy with the same APP_KEY at another address is another installation.
    config()->set('app.url', 'https://staging.example.org');
    expect($limit->lockedUntil($client))->not->toBeNull('the unlock of the production installation does not apply here')
        ->and(app(IdList::class)->status())->toBe([]);

    signInWithLink($link)->assertOk();
    expect(AuditLog::query()->where('event', 'login.succeeded')->latest('id')->first()->context)->not->toHaveKey('link_revealed_to');
});

it('names on the status page what two installations on one store keep apart and what they still share', function (): void {
    Installation::sighted('0123456789ab', 'scheduler');
    Installation::sighted('0123456789ab', 'scheduler');

    $problem = (string) app(HealthChecks::class)->sharedStoreProblem();
    expect($problem)->not->toContain('rate limits mix')
        ->and($problem)->toContain('Kept apart by the installation id: the locks and the scheduler\'s marks', 'the rate limits and the sign-in ceilings with their notices', 'the second-factor codes')
        ->and($problem)->toContain('Still shared: the queued jobs, the heartbeats, the short-lived counts of the dashboard and the status page')
        ->and($problem)->toContain('the sessions with their remember-me cookies (neither installation accepts the other\'s)')
        ->and($problem)->not->toContain('the sessions where their store is shared too');

    foreach (['queue', 'scheduler'] as $tile) {
        expect(HealthChecks::hint($tile))->toContain('rate limits', 'second-factor codes', 'queued jobs', 'heartbeats');
    }
});

it('writes the once-a-minute audit row of refused API keys on each installation that refused them', function (): void {
    foreach ([sharedStoreProductionKey(), sharedStoreStagingKey()] as $installation) {
        actAsInstallation($installation);
        $this->withHeaders(['X-Api-Key' => 'not-a-key'])->postJson('/api/v1/callcenter/ivr/verify-code', ['code' => '11111111', 'call_id' => 'c-1'])->assertUnauthorized();
    }

    expect(AuditLog::query()->where('event', 'api_key.rejected')->count())->toBe(2);
});

it('turns the session store tile red when another installation beats in the session store, and only then', function (): void {
    // Each installation has a main store of its own; only the sessions share one (a Valkey in production).
    config()->set(['cache.stores.shared-sessions' => ['driver' => 'array'], 'session.driver' => 'memcached', 'session.store' => 'shared-sessions']);
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    $tile = fn () => app(HealthChecks::class)->sessionStore();

    actAsInstallation(sharedStoreStagingKey());
    SessionStore::beat();
    actAsInstallation(sharedStoreProductionKey());
    runSchedulerHeartbeat();
    expect($tile()->status)->not->toBe(CheckStatus::Fail, 'one sighting may be the heartbeat left from before an APP_URL change');

    actAsInstallation(sharedStoreStagingKey());
    SessionStore::beat();
    actAsInstallation(sharedStoreProductionKey());

    $check = $tile();
    expect($check->status)->toBe(CheckStatus::Fail)
        ->and($check->detail)->toContain('Another installation', 'same session store', 'neither accepts the other\'s sign-ins')
        ->and(HealthChecks::hint('session_store'))->toContain('another installation', 'REDIS_SESSION_DB')
        ->and(app(HealthChecks::class)->scheduler()->status)->toBe(CheckStatus::Ok, 'the main store is not shared')
        ->and(Cache::get(SessionStore::HEARTBEAT_KEY))->toBeNull('the heartbeat goes into the session store, not the main one');

    // Both installations see it: the sighting is kept in the shared session store.
    actAsInstallation(sharedStoreStagingKey());
    expect($tile()->status)->toBe(CheckStatus::Fail);
});

it('writes no session store heartbeat where the sessions do not live in a cache store', function (): void {
    config()->set('session.driver', 'database');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    runSchedulerHeartbeat();

    expect(SessionStore::cache())->toBeNull()
        ->and(app(HealthChecks::class)->sessionStore()->status)->toBe(CheckStatus::Ok);
});
