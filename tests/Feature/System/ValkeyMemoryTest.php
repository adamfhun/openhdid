<?php

use App\System\CheckStatus;
use App\System\HealthChecks;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * A file of the container release: under deploy/openhdid/repo in the main
 * tree, at the root of the published OpenHDID tree. Null when neither has it.
 */
function openhdidReleaseFile(string $path): ?string
{
    foreach ([base_path('deploy/openhdid/repo/'.$path), base_path($path)] as $candidate) {
        if (is_file($candidate)) {
            return (string) file_get_contents($candidate);
        }
    }

    return null;
}

/**
 * The indented block under a two-space key of a top-level YAML section
 * ("services" or "configs"), without parsing YAML (no YAML parser ships
 * with the release).
 */
function composeBlock(string $compose, string $section, string $name): string
{
    preg_match('/^'.$section.':\n(.*?)(?=^\S|\z)/ms', $compose, $sectionMatch);
    preg_match('/^  '.preg_quote($name, '/').':\n((?:(?:    .*)?\n)+)/m', $sectionMatch[1] ?? '', $blockMatch);

    return $blockMatch[1] ?? '';
}

/**
 * A Valkey/Redis server answering INFO (and CONFIG GET) as given; a
 * Throwable makes that command fail.
 *
 * @param  array<string, mixed>|Throwable  $info
 * @param  array<string, mixed>|Throwable|null  $config
 */
function fakeValkeyServer(array|Throwable $info, array|Throwable|null $config = null, bool $reachable = true): void
{
    $connection = Mockery::mock(Connection::class);
    $info instanceof Throwable
        ? $connection->shouldReceive('command')->with('info')->andThrow($info)
        : $connection->shouldReceive('command')->with('info')->andReturn($info);

    if ($config instanceof Throwable) {
        $connection->shouldReceive('command')->with('config', ['GET', 'maxmemory*'])->andThrow($config);
    } elseif ($config !== null) {
        $connection->shouldReceive('command')->with('config', ['GET', 'maxmemory*'])->andReturn($config);
    }

    $reachable
        ? $connection->shouldReceive('command')->with('ping')->andReturn(true)
        : $connection->shouldReceive('command')->with('ping')->andThrow(new RuntimeException('Connection refused'));

    Redis::shouldReceive('connection')->andReturn($connection);
}

beforeEach(function (): void {
    // The queue on Valkey makes the tile look at the server; a prefix of its own keeps the prefix note out.
    config()->set('queue.default', 'redis');
    config()->set('database.redis.options.prefix', 'openhdid-prod-');
});

describe('container files', function (): void {
    beforeEach(function (): void {
        if (openhdidReleaseFile('compose.yaml') === null) {
            $this->markTestSkipped('The container release files are not part of this tree.');
        }
    });

    it('gives the bundled Valkey a memory limit it answers by refusing writes, never by dropping keys', function (): void {
        $compose = (string) openhdidReleaseFile('compose.yaml');
        $config = composeBlock($compose, 'configs', 'valkey');
        $service = composeBlock($compose, 'services', 'valkey');

        expect($config)->toMatch('/^\s+maxmemory \$\{OPENHDID_VALKEY_MAXMEMORY:-\d+[mg]b\}$/m')
            ->and($config)->toMatch('/^\s+maxmemory-policy noeviction$/m')
            ->and($config)->toContain('requirepass')
            ->and($service)->toMatch('/^    mem_limit: \$\{OPENHDID_VALKEY_MEM_LIMIT:-\d+[mg]\}$/m');
    });

    it('sizes the Valkey of the 5k profile and demands noeviction from an external or managed one', function (): void {
        $profile = (string) openhdidReleaseFile('profiles/5k/compose.yaml');

        expect(composeBlock($profile, 'configs', 'valkey'))->toMatch('/^\s+maxmemory \$\{OPENHDID_VALKEY_MAXMEMORY:-\d+[mg]b\}$/m')
            ->and(composeBlock($profile, 'configs', 'valkey'))->toMatch('/^\s+maxmemory-policy noeviction$/m')
            ->and(composeBlock($profile, 'services', 'valkey'))->toMatch('/^    mem_limit: \$\{OPENHDID_VALKEY_MEM_LIMIT:-\d+[mg]\}$/m');

        // The base and the profile keep the rest of the Valkey configuration identical.
        expect(composeBlock($profile, 'configs', 'valkey'))->toContain('appendonly yes', 'save ""', 'requirepass "${REDIS_PASSWORD:-}"');

        foreach (['profiles/10k/compose.yaml', 'kubernetes/base/kustomization.yaml', 'kubernetes/overlays/10k/kustomization.yaml', 'kubernetes/overlays/20k/kustomization.yaml'] as $file) {
            expect(str_contains((string) openhdidReleaseFile($file), 'maxmemory-policy noeviction'))->toBeTrue("{$file} demands maxmemory-policy noeviction");
        }
    });

    it('documents the Valkey limits and the key prefix in the release .env.example', function (): void {
        $env = (string) openhdidReleaseFile('.env.example');

        expect($env)->toMatch('/^# ?OPENHDID_VALKEY_MAXMEMORY=/m')
            ->and($env)->toMatch('/^# ?OPENHDID_VALKEY_MEM_LIMIT=/m')
            ->and($env)->toMatch('/^# ?REDIS_PREFIX=/m')
            ->and($env)->toMatch('/^# ?CACHE_PREFIX=/m');
    });
});

it('says the tile has nothing to check when neither the cache, the sessions nor the queue use Valkey', function (): void {
    config()->set(['queue.default' => 'database', 'session.driver' => 'database', 'cache.default' => 'array']);

    $check = app(HealthChecks::class)->valkey();

    expect($check->status)->toBe(CheckStatus::Ok)
        ->and($check->key)->toBe('valkey');
});

it('turns red when the server drops keys under memory pressure', function (string $policy): void {
    fakeValkeyServer(['used_memory' => 10_000_000, 'maxmemory' => 1_073_741_824, 'maxmemory_policy' => $policy, 'evicted_keys' => 0]);

    $check = app(HealthChecks::class)->valkey();

    expect($check->status)->toBe(CheckStatus::Fail)
        ->and($check->detail)->toContain($policy);
})->with(['volatile-lru', 'allkeys-lru', 'allkeys-lfu', 'volatile-ttl']);

it('is green with noeviction, a limit and room to spare', function (): void {
    fakeValkeyServer(['used_memory' => 50 * 1_048_576, 'maxmemory' => 512 * 1_048_576, 'maxmemory_policy' => 'noeviction', 'evicted_keys' => 0]);

    $check = app(HealthChecks::class)->valkey();

    expect($check->status)->toBe(CheckStatus::Ok)
        ->and($check->detail)->toContain('noeviction', '50,0 MB', '512,0 MB', '10 %');
});

it('warns above 80 percent of the limit, without a limit and after evictions', function (array $info, string $reason): void {
    fakeValkeyServer($info + ['maxmemory_policy' => 'noeviction']);

    $check = app(HealthChecks::class)->valkey();

    expect($check->status)->toBe(CheckStatus::Warn)
        ->and($check->detail)->toContain($reason);
})->with([
    'nearly full' => [['used_memory' => 450 * 1_048_576, 'maxmemory' => 512 * 1_048_576, 'evicted_keys' => 0], '88 %'],
    'no limit' => [['used_memory' => 450 * 1_048_576, 'maxmemory' => 0, 'evicted_keys' => 0], 'maxmemory'],
    'evicted' => [['used_memory' => 10 * 1_048_576, 'maxmemory' => 512 * 1_048_576, 'evicted_keys' => 42], '42'],
]);

it('reads the sections of a nested INFO answer as well', function (): void {
    fakeValkeyServer([
        'Memory' => ['used_memory' => 10 * 1_048_576, 'maxmemory' => 512 * 1_048_576, 'maxmemory_policy' => 'allkeys-lru'],
        'Stats' => ['evicted_keys' => 0],
    ]);

    expect(app(HealthChecks::class)->valkey()->status)->toBe(CheckStatus::Fail);
});

it('falls back to CONFIG GET when INFO does not name the policy', function (): void {
    fakeValkeyServer(['used_memory' => 10 * 1_048_576, 'evicted_keys' => 0], ['maxmemory' => '536870912', 'maxmemory-policy' => 'volatile-lru']);

    $check = app(HealthChecks::class)->valkey();

    expect($check->status)->toBe(CheckStatus::Fail)
        ->and($check->detail)->toContain('volatile-lru');
});

it('says so as information when the provider allows neither INFO nor CONFIG GET', function (): void {
    fakeValkeyServer(new RuntimeException('NOPERM this user has no permissions to run the info command'), new RuntimeException('NOPERM config'));

    $check = app(HealthChecks::class)->valkey();

    expect($check->status)->toBe(CheckStatus::Info)
        ->and($check->detail)->toContain('INFO', 'CONFIG GET');
});

it('fails when the server cannot be reached at all', function (): void {
    fakeValkeyServer(new RuntimeException('Connection refused'), new RuntimeException('Connection refused'), reachable: false);

    $check = app(HealthChecks::class)->valkey();

    expect($check->status)->toBe(CheckStatus::Fail)
        ->and($check->detail)->toContain('Connection refused');
});

it('fails without an exception when the client cannot even connect', function (): void {
    Redis::shouldReceive('connection')->andThrow(new RuntimeException('php_network_getaddresses: getaddrinfo for valkey failed'));

    $check = app(HealthChecks::class)->valkey();

    expect($check->status)->toBe(CheckStatus::Fail)
        ->and($check->detail)->toContain('getaddrinfo for valkey failed');
});

it('tells the operator what to do with the real setting and variable names', function (): void {
    fakeValkeyServer(['used_memory' => 1_048_576, 'maxmemory' => 512 * 1_048_576, 'maxmemory_policy' => 'noeviction', 'evicted_keys' => 0]);
    $tile = collect(app(HealthChecks::class)->all())->firstWhere('key', 'valkey');

    expect($tile)->not->toBeNull()
        ->and($tile->hint)->toContain('maxmemory-policy noeviction', 'OPENHDID_VALKEY_MAXMEMORY', 'OPENHDID_VALKEY_MEM_LIMIT', 'REDIS_PREFIX', 'docker compose up -d');
});

it('only notes the default key prefix on the installation\'s own bundled Valkey, and warns on an external one', function (string $host, CheckStatus $status): void {
    config()->set('database.redis.options.prefix', 'openhdid-database-');
    config()->set('app.name', 'OpenHDID');
    config()->set('database.redis.default.host', $host);
    fakeValkeyServer(['maxmemory_policy' => 'noeviction', 'maxmemory' => 536870912, 'used_memory' => 1048576]);

    $tile = app(HealthChecks::class)->valkey();

    expect($tile->status)->toBe($status)
        ->and($tile->detail)->toContain('REDIS_PREFIX is not set');
})->with([
    'the compose project\'s own valkey service' => ['valkey', CheckStatus::Info],
    'an external server' => ['redis.example.internal', CheckStatus::Warn],
]);
