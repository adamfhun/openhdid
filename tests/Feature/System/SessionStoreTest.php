<?php

use App\System\CheckStatus;
use App\System\HealthChecks;
use App\System\SessionStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * A config file evaluated as a fresh boot would, with exactly the given
 * REDIS_* and SESSION_* variables; the local .env values of those names are
 * hidden meanwhile and put back afterwards.
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function configFileWithRedisEnv(string $file, array $env): array
{
    $names = ['REDIS_URL', 'REDIS_HOST', 'REDIS_USERNAME', 'REDIS_PASSWORD', 'REDIS_PORT', 'REDIS_DB',
        'REDIS_SESSION_HOST', 'REDIS_SESSION_USERNAME', 'REDIS_SESSION_PASSWORD', 'REDIS_SESSION_PORT', 'REDIS_SESSION_DB',
        'SESSION_DRIVER', 'SESSION_CONNECTION'];
    $saved = [];

    foreach ($names as $name) {
        $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
    }

    try {
        foreach ($env as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }

        return (fn (): array => require config_path($file))();
    } finally {
        foreach ($saved as $name => [$envValue, $serverValue, $processValue]) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
            if ($envValue !== null) {
                $_ENV[$name] = $envValue;
            }
            if ($serverValue !== null) {
                $_SERVER[$name] = $serverValue;
            }
            if ($processValue !== false) {
                putenv("{$name}={$processValue}");
            }
        }
    }
}

/**
 * Valkey servers answering INFO per connection name; a Throwable makes it
 * fail. A later call for the same name changes its answer.
 *
 * @param  array<string, array<string, mixed>|Throwable>  $servers
 */
function fakeRedisServers(array $servers): void
{
    $app = spl_object_id(app());
    $GLOBALS['hdidFakeRedis'] ??= [];
    if (($GLOBALS['hdidFakeRedis']['app'] ?? null) !== $app) {
        $GLOBALS['hdidFakeRedis'] = ['app' => $app, 'answers' => [], 'mocked' => []];
    }

    foreach ($servers as $name => $info) {
        $GLOBALS['hdidFakeRedis']['answers'][$name] = $info;

        if (in_array($name, $GLOBALS['hdidFakeRedis']['mocked'], true)) {
            continue;
        }

        $answer = function () use ($name): array {
            $info = $GLOBALS['hdidFakeRedis']['answers'][$name];

            return $info instanceof Throwable ? throw $info : $info;
        };
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('command')->with('info')->andReturnUsing($answer);
        $connection->shouldReceive('command')->with('config', ['GET', 'maxmemory*'])->andReturn([]);
        $connection->shouldReceive('command')->with('ping')->andReturn(true);

        Redis::shouldReceive('connection')->with($name)->andReturn($connection);
        $GLOBALS['hdidFakeRedis']['mocked'][] = $name;
    }
}

/**
 * Sessions on Valkey, on a server of their own unless told otherwise.
 */
function sessionsOnRedis(string $sessionHost = 'valkey-sessions'): void
{
    config()->set([
        'session.driver' => 'redis',
        'session.connection' => 'session',
        'database.redis.default.host' => 'valkey',
        'database.redis.default.port' => '6379',
        'database.redis.session.host' => $sessionHost,
        'database.redis.session.port' => '6379',
        'database.redis.session.url' => null,
        'database.redis.default.url' => null,
    ]);
}

it('keeps the sessions on the main server while the REDIS_SESSION_* keys are empty', function (): void {
    $redis = configFileWithRedisEnv('database.php', [
        'REDIS_HOST' => 'valkey', 'REDIS_PORT' => '6380', 'REDIS_PASSWORD' => 'main-secret', 'REDIS_DB' => '2',
        // A blank line copied from .env.example must not hide the main values.
        'REDIS_SESSION_HOST' => '', 'REDIS_SESSION_PASSWORD' => '',
    ])['redis'];

    expect($redis['session'])->toMatchArray(['host' => 'valkey', 'port' => '6380', 'password' => 'main-secret', 'database' => '2'])
        ->and($redis['default']['host'])->toBe('valkey');
});

it('takes a server of their own for the sessions from REDIS_SESSION_*', function (): void {
    $redis = configFileWithRedisEnv('database.php', [
        'REDIS_URL' => 'tcp://valkey:6379', 'REDIS_HOST' => 'valkey', 'REDIS_PASSWORD' => 'main-secret', 'REDIS_DB' => '0',
        'REDIS_SESSION_HOST' => 'valkey-sessions', 'REDIS_SESSION_PORT' => '6381', 'REDIS_SESSION_PASSWORD' => 'session-secret', 'REDIS_SESSION_DB' => '0',
    ])['redis'];

    // The main URL would otherwise win over the session host.
    expect($redis['session'])->toMatchArray(['url' => null, 'host' => 'valkey-sessions', 'port' => '6381', 'password' => 'session-secret', 'database' => '0']);
});

it('puts the sessions of the redis driver on the session connection, other drivers keep theirs', function (): void {
    expect(configFileWithRedisEnv('session.php', ['SESSION_DRIVER' => 'redis'])['connection'])->toBe('session')
        ->and(configFileWithRedisEnv('session.php', ['SESSION_DRIVER' => 'database'])['connection'])->toBeNull()
        ->and(configFileWithRedisEnv('session.php', ['SESSION_DRIVER' => 'redis', 'SESSION_CONNECTION' => 'default'])['connection'])->toBe('default');
});

it('stores the sessions of the redis driver through the session connection', function (): void {
    config()->set(['session.driver' => 'redis', 'session.connection' => 'session']);
    app()->forgetInstance('session');
    app()->forgetInstance('session.store');

    $store = app('session')->driver()->getHandler()->getCache()->getStore();
    $connection = (fn (): ?string => $this->connection)->call($store);

    // The session handler works on its own copy: the application's redis cache stays on the main server.
    $cacheConnection = (fn (): ?string => $this->connection)->call(Cache::store('redis')->getStore());

    expect($connection)->toBe('session')
        ->and(SessionStore::connection())->toBe('session')
        ->and($cacheConnection)->toBe(config('cache.stores.redis.connection'))
        ->and($cacheConnection)->not->toBe('session');
});

it('tells sessions on the main server from sessions on a server of their own', function (): void {
    sessionsOnRedis('valkey');
    expect(SessionStore::sharesMainStore())->toBeTrue();

    sessionsOnRedis('VALKEY');
    config()->set('database.redis.session.port', '6380');
    expect(SessionStore::sharesMainStore())->toBeFalse();

    sessionsOnRedis('valkey-sessions');
    expect(SessionStore::sharesMainStore())->toBeFalse();

    config()->set('session.connection', null);
    expect(SessionStore::sharesMainStore())->toBeTrue();
});

it('says nothing to do for sessions that are not on Valkey', function (): void {
    config()->set('session.driver', 'database');

    $check = app(HealthChecks::class)->sessionStore();

    expect($check->status)->toBe(CheckStatus::Ok)
        ->and($check->key)->toBe('session_store')
        ->and($check->detail)->toContain('SESSION_DRIVER=redis');
});

it('warns while the sessions share the main Valkey, naming the variable to set', function (): void {
    sessionsOnRedis('valkey');

    $tile = collect(app(HealthChecks::class)->all())->firstWhere('key', 'session_store');

    expect($tile->status)->toBe(CheckStatus::Warn)
        ->and($tile->detail)->toContain('REDIS_SESSION_HOST')
        ->and($tile->hint)->toContain('REDIS_SESSION_HOST', 'REDIS_SESSION_PORT', 'REDIS_SESSION_PASSWORD', 'REDIS_SESSION_DB', 'OPENHDID_VALKEY_SESSIONS_MAXMEMORY', 'OPENHDID_VALKEY_SESSIONS_MEM_LIMIT', 'allkeys-lru', 'docker compose up -d', 'valkey-sessions');
});

it('is green on a session store of its own that evicts the least recently used sessions, and shows the evictions', function (): void {
    sessionsOnRedis();
    fakeRedisServers(['session' => ['maxmemory_policy' => 'allkeys-lru', 'maxmemory' => 384 * 1_048_576, 'used_memory' => 380 * 1_048_576, 'evicted_keys' => 1200]]);

    $check = app(HealthChecks::class)->sessionStore();

    // Full is normal for an LRU store; evictions seen for the first time are not a rise.
    expect($check->status)->toBe(CheckStatus::Ok)
        ->and($check->detail)->toContain('allkeys-lru', '1200 session(s) evicted', 'valkey-sessions:6379', '99 %');
});

it('warns about a session store that refuses new sessions or drops active ones when full', function (string $policy): void {
    sessionsOnRedis();
    fakeRedisServers(['session' => ['maxmemory_policy' => $policy, 'maxmemory' => 384 * 1_048_576, 'used_memory' => 1_048_576, 'evicted_keys' => 0]]);

    $check = app(HealthChecks::class)->sessionStore();

    expect($check->status)->toBe(CheckStatus::Warn)
        ->and($check->detail)->toContain($policy);
})->with(['noeviction', 'volatile-lru', 'volatile-ttl', 'allkeys-random']);

it('warns when sessions were evicted within the last hour, and turns green again after it', function (): void {
    sessionsOnRedis();
    $answer = fn (int $evicted) => fakeRedisServers(['session' => ['maxmemory_policy' => 'allkeys-lru', 'maxmemory' => 384 * 1_048_576, 'used_memory' => 1_048_576, 'evicted_keys' => $evicted]]);
    $checks = app(HealthChecks::class);

    $answer(10);
    expect($checks->sessionStore()->status)->toBe(CheckStatus::Ok);

    $this->travel(5)->minutes();
    $answer(4000);
    $check = $checks->sessionStore();
    expect($check->status)->toBe(CheckStatus::Warn)
        ->and($check->detail)->toContain('last 60 minutes', 'OPENHDID_VALKEY_SESSIONS_MAXMEMORY');

    $this->travel(HealthChecks::SESSION_EVICTION_WINDOW_MINUTES + 1)->minutes();
    $answer(4000);
    expect($checks->sessionStore()->status)->toBe(CheckStatus::Ok);

    // A restarted server counts from zero again without a false alarm.
    $answer(3);
    expect($checks->sessionStore()->status)->toBe(CheckStatus::Ok);
    Cache::forget('hdid.health.session_evictions');
});

it('fails when the session store cannot be reached, since nobody can sign in then', function (): void {
    sessionsOnRedis();
    fakeRedisServers(['session' => new RuntimeException('Connection refused')]);

    $check = app(HealthChecks::class)->sessionStore();

    expect($check->status)->toBe(CheckStatus::Fail)
        ->and($check->detail)->toContain('Connection refused', 'nobody can sign in');
});

it('leaves the sessions on a server of their own out of the main Valkey tile', function (): void {
    sessionsOnRedis();
    config()->set(['queue.default' => 'redis', 'cache.default' => 'array', 'database.redis.options.prefix' => 'openhdid-prod-']);
    // The main server refuses writes when full, the session server evicts: only the session tile looks at the latter.
    fakeRedisServers([
        'default' => ['maxmemory_policy' => 'noeviction', 'maxmemory' => 256 * 1_048_576, 'used_memory' => 1_048_576, 'evicted_keys' => 0],
        'session' => ['maxmemory_policy' => 'allkeys-lru', 'maxmemory' => 384 * 1_048_576, 'used_memory' => 1_048_576, 'evicted_keys' => 0],
    ]);

    $valkey = app(HealthChecks::class)->valkey();

    expect($valkey->status)->toBe(CheckStatus::Ok)
        ->and($valkey->detail)->toContain('queue')
        ->and($valkey->detail)->not->toContain('sessions');

    // Sessions sharing the main server are counted there, as before.
    sessionsOnRedis('valkey');
    config()->set('session.connection', 'default');
    expect(app(HealthChecks::class)->valkey()->detail)->toContain('sessions');
});
