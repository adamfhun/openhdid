<?php

use App\Auth\Role;
use App\Enums\ApiKeyScope;
use App\Enums\SyncRunStatus;
use App\Filament\Admin\Pages\SystemStatus;
use App\Jobs\QueueHeartbeat;
use App\Jobs\SendOutboundMessage;
use App\Models\ApiKey;
use App\Models\OutboundMessage;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\System\CheckStatus;
use App\System\HealthChecks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('reports the database, cache and storage as healthy', function (): void {
    $checks = app(HealthChecks::class);

    expect(collect($checks->all())->every(fn ($check) => filled($check->hint)))->toBeTrue();

    expect($checks->database()->status)->toBe(CheckStatus::Ok)
        ->and($checks->cacheStore()->status)->toBe(CheckStatus::Ok)
        ->and($checks->storage()->status)->not->toBe(CheckStatus::Fail);
});

it('judges the queue and scheduler by their heartbeats', function (): void {
    config()->set('queue.default', 'database');
    $checks = app(HealthChecks::class);

    expect($checks->queue()->status)->toBe(CheckStatus::Fail)
        ->and($checks->scheduler()->status)->toBe(CheckStatus::Fail);

    (new QueueHeartbeat)->handle();
    Cache::put(HealthChecks::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String());
    expect($checks->queue()->status)->toBe(CheckStatus::Ok)
        ->and($checks->scheduler()->status)->toBe(CheckStatus::Ok);

    Cache::put(SendOutboundMessage::HEARTBEAT_KEY, now()->subMinutes(10)->toIso8601String());
    expect($checks->queue()->status)->toBe(CheckStatus::Fail);

    config()->set('queue.default', 'sync');
    expect($checks->queue()->status)->toBe(CheckStatus::Warn);
});

it('checks the transports without sending anything', function (): void {
    $checks = app(HealthChecks::class);

    config()->set('mail.default', 'log');
    expect($checks->mail()->status)->toBe(CheckStatus::Warn);
    config()->set('mail.default', 'ews');
    config()->set('hdid.ews', ['url' => 'https://ews', 'auth' => 'basic', 'username' => 'u', 'password' => 'p', 'oauth' => [], 'verify_tls' => true]);
    expect($checks->mail()->status)->toBe(CheckStatus::Ok);
    config()->set('hdid.ews.password', null);
    expect($checks->mail()->status)->toBe(CheckStatus::Fail);

    config()->set('hdid.sms.driver', 'log');
    expect($checks->sms()->status)->toBe(CheckStatus::Warn);
    config()->set('hdid.sms.driver', 'ozeki');
    config()->set('hdid.ozeki', ['url' => 'http://ozeki/api', 'username' => 'u', 'password' => 'p']);
    Http::fake(['http://ozeki/*' => Http::response('ok', 200)]);
    expect($checks->sms()->status)->toBe(CheckStatus::Ok);
});

it('reports api keys, login providers, sync and the outbox', function (): void {
    $checks = app(HealthChecks::class);
    config()->set('hdid.callcenter.api_key', null);

    expect($checks->apiKeys()->status)->toBe(CheckStatus::Warn);
    ApiKey::factory()->scope(ApiKeyScope::CallCenter)->create();
    ApiKey::factory()->scope(ApiKeyScope::MobileBackend)->create();
    expect($checks->apiKeys()->status)->toBe(CheckStatus::Ok);

    expect($checks->oidc()->status)->toBe(CheckStatus::Ok);
    app(Settings::class)->set(SettingKey::ClientSsoEntraEnabled, true);
    expect($checks->oidc()->status)->toBe(CheckStatus::Fail, 'enabled but not configured');

    expect($checks->syncApi()->status)->toBe(CheckStatus::Warn, 'no run yet');

    expect($checks->outboundMessages()->status)->toBe(CheckStatus::Ok);
    OutboundMessage::factory()->failed()->create();
    expect($checks->outboundMessages()->status)->toBe(CheckStatus::Warn);
});

it('warns when a panel-started sync waits on a queue no worker listens to', function (): void {
    config()->set('hdid.sync.queue', 'sync');
    SyncRun::factory()->create(['status' => SyncRunStatus::Completed, 'dry_run' => false, 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);
    $checks = app(HealthChecks::class);
    expect($checks->syncApi()->status)->toBe(CheckStatus::Ok);

    SyncRun::factory()->create(['status' => SyncRunStatus::Queued, 'dry_run' => true, 'started_at' => now()->subMinutes(3), 'finished_at' => null]);
    expect($checks->syncApi()->status)->toBe(CheckStatus::Ok, 'a fresh queued run is normal');

    SyncRun::factory()->create(['status' => SyncRunStatus::Queued, 'dry_run' => true, 'started_at' => now()->subMinutes(12), 'finished_at' => null]);
    $check = $checks->syncApi();
    expect($check->status)->toBe(CheckStatus::Warn)
        ->and($check->detail)->toContain(__('An EMD sync has been waiting on the ":queue" queue for :minutes minutes; no worker seems to listen on it.', ['queue' => 'sync', 'minutes' => 12]));
});

it('shows the release version on the status page only when the build sets one', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    config()->set('hdid.version', null);
    $this->get(SystemStatus::getUrl())->assertOk()->assertDontSee(__('Version :version', ['version' => '1.4.2']));

    config()->set('hdid.version', '1.4.2');
    $this->get(SystemStatus::getUrl())->assertOk()->assertSee(__('Version :version', ['version' => '1.4.2']));
    $this->get('/health')->assertDontSee('1.4.2');
});

it('shows the source link in the panel footer only when the deployment names it', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    config()->set('hdid.source_url', null);
    $this->get(SystemStatus::getUrl())->assertOk()->assertDontSee('hdid-source-link');

    config()->set('hdid.source_url', 'https://github.com/adamfhun/openhdid/tree/v1.0.0');
    $this->get(SystemStatus::getUrl())->assertOk()
        ->assertSee('href="https://github.com/adamfhun/openhdid/tree/v1.0.0"', false)
        ->assertSee(__('Source code'));
});

it('renders the status page for an admin only', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->actingAs(User::factory()->withRole(Role::Admin)->create())->get(SystemStatus::getUrl())->assertOk()->assertSee(__('Queue worker'));
    $this->flushSession();
    $this->actingAs(User::factory()->withRole(Role::Agent)->create())->get(SystemStatus::getUrl())->assertForbidden();
});
