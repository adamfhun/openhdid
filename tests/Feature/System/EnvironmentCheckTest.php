<?php

use App\Auth\Role;
use App\Filament\Admin\Pages\SystemStatus;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\System\Check;
use App\System\CheckStatus;
use App\System\EnvironmentSafety;
use App\System\HealthChecks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * The Environment tile says how the installation runs: production with a
 * value that switches a safeguard off is red (the container start check
 * refuses the same, so this matters on a classic installation), debug mode
 * outside production is yellow, any other non-production setup is
 * information naming what it relaxes.
 */

beforeEach(function (): void {
    config()->set([
        'app.debug' => false,
        'app.url' => 'https://hdid.example.org',
        'trustedproxy.proxies' => '127.0.0.1',
        'session.secure' => true,
    ]);
});

afterEach(function (): void {
    app()->detectEnvironment(fn () => 'testing');
});

function environmentTile(string $environment): Check
{
    app()->detectEnvironment(fn () => $environment);

    return app(HealthChecks::class)->environment();
}

it('shows a safe production setup as green', function (): void {
    expect(environmentTile('production')->status)->toBe(CheckStatus::Ok);
});

it('turns the environment tile red in production for a value that switches a safeguard off, naming the key', function (array $config, string $key): void {
    config()->set($config);

    $tile = environmentTile('production');

    expect($tile->status)->toBe(CheckStatus::Fail)
        ->and($tile->detail)->toContain($key);
})->with([
    'debug mode' => [['app.debug' => true], 'APP_DEBUG'],
    'every proxy' => [['trustedproxy.proxies' => '*'], 'TRUSTED_PROXIES'],
    'every proxy, twice starred' => [['trustedproxy.proxies' => '**'], 'TRUSTED_PROXIES'],
    'every IPv4 address' => [['trustedproxy.proxies' => '0.0.0.0/0'], 'TRUSTED_PROXIES'],
    'every IPv6 address' => [['trustedproxy.proxies' => '::/0'], 'TRUSTED_PROXIES'],
    'one of a list' => [['trustedproxy.proxies' => '10.0.0.1, *'], 'TRUSTED_PROXIES'],
    'one of an array' => [['trustedproxy.proxies' => ['10.0.0.1', '*']], 'TRUSTED_PROXIES'],
    'insecure cookie' => [['session.secure' => false], 'SESSION_SECURE_COOKIE'],
    'plain http' => [['app.url' => 'http://hdid.example.org'], 'APP_URL'],
]);

it('turns the environment tile red for an environment name the framework does not treat as production', function (): void {
    $tile = environmentTile('prod');

    expect($tile->status)->toBe(CheckStatus::Fail)
        ->and($tile->detail)->toContain('APP_ENV=prod');
});

it('shows a non-production environment as information, naming what it relaxes', function (): void {
    $tile = environmentTile('staging');

    expect($tile->status)->toBe(CheckStatus::Info)
        ->and($tile->detail)->toContain('APP_ENV=staging', 'demo', 'SMS');
});

it('warns in debug mode outside production', function (): void {
    config()->set('app.debug', true);
    $tile = environmentTile('staging');

    expect($tile->status)->toBe(CheckStatus::Warn)
        ->and($tile->detail)->toContain('APP_DEBUG=true', 'login link');
});

it('warns when APP_ENV=local runs under a public address', function (): void {
    expect(environmentTile('local')->status)->toBe(CheckStatus::Warn);

    config()->set('app.url', 'http://localhost:8000');
    expect(environmentTile('local')->status)->toBe(CheckStatus::Info);
});

it('keeps the deliberate differences from the container check: plain http outside production, and an empty Redis password', function (): void {
    config()->set('app.url', 'http://hdid.example.org');
    expect(environmentTile('staging')->status)->toBe(CheckStatus::Info);

    config()->set(['app.url' => 'https://hdid.example.org', 'cache.default' => 'redis', 'database.redis.default.password' => null]);
    $tile = environmentTile('production');
    expect($tile->status)->toBe(CheckStatus::Warn)
        ->and($tile->detail)->toContain('REDIS_PASSWORD');
});

it('uses the same environment names and trust-all entries as the container start check', function (): void {
    $runtime = base_path('deploy/openhdid/repo/docker/runtime.php');
    if (! is_file($runtime)) {
        $this->markTestSkipped('The container runtime is not in this tree.');
    }
    $source = (string) file_get_contents($runtime);

    preg_match('/\$knownEnvironments = (\[[^\]]*\]);/', $source, $known);
    preg_match("/env_list\('TRUSTED_PROXIES'\), (\[[^\]]*\])/", $source, $trustAll);

    expect(eval('return '.$known[1].';'))->toBe(EnvironmentSafety::KNOWN_ENVIRONMENTS)
        ->and(eval('return '.$trustAll[1].';'))->toBe(EnvironmentSafety::TRUST_ALL);
});

it('names the real keys and both installation types in the environment hint', function (): void {
    expect(HealthChecks::hint('environment'))->toContain('APP_ENV', 'APP_DEBUG', 'TRUSTED_PROXIES', 'SESSION_SECURE_COOKIE', 'APP_URL', 'REDIS_PASSWORD', 'config:cache', 'docker compose');
});

it('shows the environment in the modes block', function (): void {
    expect(collect(app(HealthChecks::class)->grouped()['modes'])->pluck('key')->all())->toContain('environment');
});

it('warns on the login link preview tile while debug mode shows every sent link, without sending the admin to the settings', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create());
    Filament::setCurrentPanel('admin');
    $page = Livewire::test(SystemStatus::class)->instance();

    config()->set('app.debug', true);
    $tile = app(HealthChecks::class)->loginLinkPreview();
    expect($tile->status)->toBe(CheckStatus::Warn)
        ->and($tile->detail)->toContain('APP_DEBUG')
        ->and($page->linkFor($tile))->toBeNull();

    app(Settings::class)->set(SettingKey::ClientLoginMagicLinkPreviewInPanel, true);
    $tile = app(HealthChecks::class)->loginLinkPreview();
    expect($tile->detail)->toContain('APP_DEBUG')
        ->and($page->linkFor($tile))->not->toBeNull();
});
