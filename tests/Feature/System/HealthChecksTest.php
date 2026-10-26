<?php

use App\Auth\Role;
use App\Enums\ApiKeyScope;
use App\Enums\SyncRunStatus;
use App\Filament\Admin\Pages\SystemStatus;
use App\Jobs\QueueHeartbeat;
use App\Jobs\SendOutboundMessage;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\OutboundMessage;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\System\Check;
use App\System\CheckGroup;
use App\System\CheckStatus;
use App\System\HealthChecks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
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
    ApiKey::factory()->scope(ApiKeyScope::MobileBackend)->signed()->create();
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

it('turns the sign-in tile yellow when a client tenant domain is on no EMD domain list, and its hint says where to look', function (): void {
    config()->set('hdid.oidc.client.entra', ['tenant' => null, 'tenants' => '22222222-2222-4222-8222-222222222222:partner.hu', 'client_id' => 'client-app', 'client_secret' => 's']);
    app(Settings::class)->set(SettingKey::ClientSsoEntraEnabled, true);
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['other.hu']);

    $check = app(HealthChecks::class)->oidc();
    expect($check->status)->toBe(CheckStatus::Warn)
        ->and($check->detail)->toContain('partner.hu (on no EMD domain list)')
        ->and(HealthChecks::hint('oidc'))->toContain('CLIENT_ENTRA_TENANTS')->toContain('Client e-mail domains');

    app(Settings::class)->set(SettingKey::SyncClientDomains, ['other.hu', 'partner.hu']);
    expect(app(HealthChecks::class)->oidc()->status)->toBe(CheckStatus::Ok);
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

it('hides the source link in the panel footer when the deployment switches it off', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    config()->set('hdid.source_url', 'https://github.com/adamfhun/openhdid/tree/v1.0.0');
    config()->set('hdid.show_source_url', false);

    $this->get(SystemStatus::getUrl())->assertOk()
        ->assertDontSee('hdid-source-link')
        ->assertDontSee('https://github.com/adamfhun/openhdid/tree/v1.0.0');
});

it('renders the status page for an admin only', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->actingAs(User::factory()->withRole(Role::Admin)->create())->get(SystemStatus::getUrl())->assertOk()->assertSee(__('Queue worker'));
    $this->flushSession();
    $this->actingAs(User::factory()->withRole(Role::Agent)->create())->get(SystemStatus::getUrl())->assertForbidden();
});

it('reports the PHP time limit of the checking process and fails below 120 seconds', function (): void {
    $checks = app(HealthChecks::class);

    try {
        set_time_limit(0);
        expect($checks->phpTimeLimit()->status)->toBe(CheckStatus::Ok);
        set_time_limit(200);
        expect($checks->phpTimeLimit()->status)->toBe(CheckStatus::Warn)->and($checks->phpTimeLimit()->detail)->toContain('200 s');
        set_time_limit(300);
        expect($checks->phpTimeLimit()->status)->toBe(CheckStatus::Ok);
        set_time_limit(60);
        expect($checks->phpTimeLimit()->status)->toBe(CheckStatus::Fail)->and(HealthChecks::hint('php'))->toContain('max_execution_time');
    } finally {
        set_time_limit(0);
    }
});

it('splits the tiles into an operation block and a modes block, and reports deliberate modes as information', function (): void {
    $grouped = app(HealthChecks::class)->grouped();

    expect(array_keys($grouped))->toBe(['operation', 'modes'])
        ->and(collect($grouped['modes'])->pluck('key')->all())->toBe(['premium_only', 'login_link_preview', 'client_login_emd', 'rate_limit_exemptions', 'adfs_debug', 'package_overrides', 'shared_numbers'])
        ->and(collect($grouped['operation'])->pluck('key')->all())->toContain('database', 'queue', 'mfa', 'oidc', 'capacity')
        ->and(collect($grouped['operation'])->pluck('key')->all())->not->toContain('premium_only');

    Client::factory()->create(['package_override' => 'Premium', 'package_override_until' => now()->addDay()]);
    $tile = app(HealthChecks::class)->packageOverrides();

    expect($tile->status)->toBe(CheckStatus::Info)
        ->and($tile->group)->toBe(CheckGroup::Modes)
        ->and(app(HealthChecks::class)->database()->group)->toBe(CheckGroup::Operation);
});

it('does not fail the strict command or the health endpoint on information tiles', function (): void {
    $this->mock(HealthChecks::class, function ($mock): void {
        $mock->shouldReceive('all')->andReturn([
            Check::ok('database', 'Database', 'ok'),
            Check::info('package_overrides', 'Package overrides', '1 client'),
        ]);
    });

    $this->artisan('hdid:health --strict')->assertExitCode(0);
    $this->getJson('/health')->assertOk()->assertJsonFragment(['key' => 'package_overrides', 'status' => 'info']);

    $this->mock(HealthChecks::class, function ($mock): void {
        $mock->shouldReceive('all')->andReturn([Check::warn('login_link_preview', 'Login link preview', 'on')]);
    });

    $this->artisan('hdid:health --strict')->assertExitCode(1);
    $this->getJson('/health')->assertOk();
});

it('prints label, group and hint in the JSON output of hdid:health', function (): void {
    $this->mock(HealthChecks::class, function ($mock): void {
        $mock->shouldReceive('all')->andReturn([
            Check::ok('database', 'Database', 'ok')->withHint('Check the DB_* values.'),
            Check::fail('scheduler', 'Scheduler', 'no heartbeat')->withHint(HealthChecks::hint('scheduler')),
            Check::info('package_overrides', 'Package overrides', '1 client'),
        ]);
    });

    $code = Artisan::call('hdid:health', ['--json' => true]);
    $rows = json_decode(trim(Artisan::output()), true);

    // install.sh check reads these fields; key, status and detail keep their order and meaning.
    expect($code)->toBe(2)
        ->and(array_keys($rows[0]))->toBe(['key', 'status', 'detail', 'label', 'group', 'hint'])
        ->and($rows[0])->toBe(['key' => 'database', 'status' => 'ok', 'detail' => 'ok', 'label' => 'Database', 'group' => 'operation', 'hint' => 'Check the DB_* values.'])
        ->and($rows[1]['status'])->toBe('fail')
        ->and($rows[1]['hint'])->toContain('schedule:clear-cache')
        ->and($rows[2]['group'])->toBe('modes')
        ->and($rows[2]['hint'])->toBeNull();
});

it('renders the status page in two blocks with links to the pages that fix a tile', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');
    app(Settings::class)->set(SettingKey::PackagesPremiumOnly, true);

    $this->get('/admin/system/status')
        ->assertOk()
        ->assertSeeInOrder([__('System operation'), __('Modes and settings')])
        ->assertSee(__('Information'))
        ->assertSee(__('Queues'))
        ->assertSee(__('Settings'));

    $page = new SystemStatus;
    expect($page->linkFor(app(HealthChecks::class)->database()))->toBeNull('a green tile needs no link')
        ->and($page->linkFor(app(HealthChecks::class)->premiumOnly())['label'])->toBe(__('Settings'))
        ->and($page->linkFor(app(HealthChecks::class)->queue())['label'])->toBe(__('Queues'));
});
