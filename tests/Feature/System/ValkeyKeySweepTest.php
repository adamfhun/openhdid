<?php

use App\Auth\Oidc\MobileTokenExchange;
use App\Http\Middleware\AuthenticateApiKey;
use App\System\SignOutEveryone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/*
 * The sign-out of everyone against a real Valkey with the phpredis client the
 * image ships: the connection prefix, the SCAN cursor of phpredis 6.1 and
 * later, UNLINK, and that nothing but this installation's session, cache and
 * lock keys goes. It needs a Valkey nobody else uses (its databases 0, 1 and
 * 2 are emptied first) and the redis extension, so it runs only when
 * HDID_TEST_VALKEY_HOST names one, for example in the OpenHDID image:
 *
 *   docker network create hdid-valkey-test
 *   docker run -d --rm --name hdid-valkey-test --network hdid-valkey-test valkey/valkey:<pinned tag and digest>
 *   docker run --rm --network hdid-valkey-test -e APP_ENV=testing -e HDID_TEST_VALKEY_HOST=hdid-valkey-test \
 *     -v "$PWD":/src -w /src --entrypoint php ghcr.io/adamfhun/openhdid:<version> \
 *     vendor/bin/pest tests/Feature/System/ValkeyKeySweepTest.php
 *   docker rm -f hdid-valkey-test; docker network rm hdid-valkey-test
 *
 * (APP_ENV=testing: the image says production, and phpunit.xml does not
 * override a variable that is already set.)
 */

/**
 * A plain phpredis client on one database, without the application's key
 * prefix: what the server really holds.
 */
function rawValkey(int $database): Redis
{
    $client = new Redis;
    $client->connect((string) getenv('HDID_TEST_VALKEY_HOST'), (int) (getenv('HDID_TEST_VALKEY_PORT') ?: 6379));
    $client->select($database);

    return $client;
}

/**
 * Every key of a database, sorted.
 *
 * @return list<string>
 */
function rawValkeyKeys(int $database): array
{
    $keys = rawValkey($database)->keys('*');
    sort($keys);

    return $keys;
}

beforeEach(function (): void {
    if (! extension_loaded('redis') || getenv('HDID_TEST_VALKEY_HOST') === false) {
        $this->markTestSkipped('Needs the redis extension and a Valkey of its own in HDID_TEST_VALKEY_HOST.');
    }

    foreach ([0, 1, 2] as $database) {
        rawValkey($database)->flushDB();
    }

    $server = ['host' => getenv('HDID_TEST_VALKEY_HOST'), 'port' => getenv('HDID_TEST_VALKEY_PORT') ?: '6379', 'password' => null];
    config()->set('database.redis.client', 'phpredis');
    config()->set('database.redis.options.prefix', 'openhdid-prod-');
    config()->set('database.redis.default', $server + ['database' => '0']);
    config()->set('database.redis.cache', $server + ['database' => '1']);
    config()->set('cache.prefix', 'openhdid-cache-');
    config()->set('cache.default', 'redis');
    config()->set('queue.default', 'redis');
    config()->set('session.driver', 'redis');
    config()->set('session.connection', 'session');
    app('session')->setDefaultDriver('redis');
});

it('deletes only this installation\'s keys from a real Valkey and keeps the queue, the replay guards and other prefixes', function (int $sessionDatabase): void {
    $server = ['host' => getenv('HDID_TEST_VALKEY_HOST'), 'port' => getenv('HDID_TEST_VALKEY_PORT') ?: '6379', 'password' => null];
    // Database 0 holds the sessions beside the queue (no session Valkey), database 2 stands for a session Valkey of their own.
    config()->set('database.redis.session', $server + ['database' => (string) $sessionDatabase]);

    Queue::connection('redis')->pushRaw('{"probe":true}', 'backup-probe');
    Cache::add(MobileTokenExchange::REPLAY_KEY_PREFIX.'backup-probe', true, 3600);
    Cache::add(AuthenticateApiKey::SIGNATURE_REPLAY_PREFIX.'backup-probe', 1, 3600);
    Cache::put('backup-probe-cache', 'cached', 3600);
    expect(Cache::lock('backup-probe-lock', 3600)->get())->toBeTrue();
    // More sessions than one SCAN step or one UNLINK batch takes.
    $handler = app('session')->driver()->getHandler();
    foreach (range(1, 2500) as $n) {
        $handler->write('session'.str_pad((string) $n, 36, '0', STR_PAD_LEFT), 'probe');
    }
    foreach ([0, 1, $sessionDatabase] as $database) {
        rawValkey($database)->set('other-install:probe', '1');
        rawValkey($database)->set('openhdid-prod-other-cache-probe', '1');
    }
    // The command statistics are the server's: from here on they show what the sign-out ran.
    rawValkey(0)->config('RESETSTAT');

    $result = app(SignOutEveryone::class)->run('manual');

    expect(Queue::connection('redis')->size('backup-probe'))->toBe(1)
        ->and(Cache::has(MobileTokenExchange::REPLAY_KEY_PREFIX.'backup-probe'))->toBeTrue()
        ->and(Cache::has(AuthenticateApiKey::SIGNATURE_REPLAY_PREFIX.'backup-probe'))->toBeTrue()
        ->and(Cache::has('backup-probe-cache'))->toBeFalse()
        ->and(Cache::lock('backup-probe-lock', 10)->get())->toBeTrue()
        ->and($handler->read('session'.str_pad('1', 36, '0', STR_PAD_LEFT)))->toBe('');

    $expected = [
        0 => ['openhdid-prod-other-cache-probe', 'openhdid-prod-queues:backup-probe', 'openhdid-prod-queues:backup-probe:notify', 'other-install:probe'],
        1 => ['openhdid-prod-openhdid-cache-'.AuthenticateApiKey::SIGNATURE_REPLAY_PREFIX.'backup-probe', 'openhdid-prod-openhdid-cache-'.MobileTokenExchange::REPLAY_KEY_PREFIX.'backup-probe', 'openhdid-prod-other-cache-probe', 'other-install:probe'],
        2 => $sessionDatabase === 2 ? ['openhdid-prod-other-cache-probe', 'other-install:probe'] : [],
    ];
    foreach ($expected as $database => $keys) {
        sort($keys);
        $left = array_values(array_filter(rawValkeyKeys($database), fn (string $key): bool => ! str_starts_with($key, 'openhdid-prod-openhdid-cache-backup-probe-lock')));
        expect($left)->toBe($keys, "database {$database}");
    }
    $stats = rawValkey(0)->info('commandstats');
    expect($stats)->toHaveKey('cmdstat_unlink')
        ->and($stats)->not->toHaveKey('cmdstat_flushdb')
        ->and($stats)->not->toHaveKey('cmdstat_flushall')
        // Every session, the cache entry and the lock, counted for the audit entry.
        ->and(($result['session_keys'] ?? 0) + ($result['cache_keys'] ?? 0) + ($result['lock_keys'] ?? 0))->toBe(2500 + 2);

    Queue::connection('redis')->clear('backup-probe');
})->with(['sessions beside the queue' => 0, 'a session store of their own' => 2]);
