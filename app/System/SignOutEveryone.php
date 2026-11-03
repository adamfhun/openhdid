<?php

namespace App\System;

use App\Audit\Auditor;
use App\Auth\Oidc\MobileTokenExchange;
use App\Enums\PrincipalType;
use App\Http\Middleware\AuthenticateApiKey;
use App\Models\OneTimeCode;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\CanFlushLocks;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\FileSessionHandler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * Ends every sign-in of every account at once: after a restored backup
 * (OpenHDID backup.sh, the classic restore procedure, a Kubernetes rollback)
 * the dump brings back the session epochs, remember-me tokens, mobile tokens
 * and live one-time secrets of the backup time, and a session kept outside
 * the database would match that epoch again. Like revokeAccess() on one
 * account, but for all, through the application's own connections, so the
 * sessions, the cache and the locks are deleted wherever they live (a
 * database table, files, or the main and the session Valkey).
 *
 * On Valkey/Redis the keys go by prefix (RedisKeySweep), never by FLUSHDB:
 * the stores are taken from the objects the application really uses (the
 * session handler's store, the default cache store and its lock connection),
 * each database is swept once, and the queue, the replay guards (KEEP) and
 * the keys of other prefixes stay. A database cache loses every row but the
 * replay guards, and every lock; a file, memcached or array store is emptied
 * whole. Deleting the locks frees running work too: the callers stop the
 * workers and the scheduler first. Audited as access.revoked_all with the
 * counts.
 */
final class SignOutEveryone
{
    /**
     * Cache keys (relative to the cache prefix) a sign-out keeps: the
     * one-use guards of a mobile ID token and of a signed machine request
     * (a restore must not make them usable again; they expire on their own)
     * and the maintenance mode of the cache driver.
     */
    public const KEEP = [
        MobileTokenExchange::REPLAY_KEY_PREFIX,
        AuthenticateApiKey::SIGNATURE_REPLAY_PREFIX,
        'illuminate:foundation:down',
    ];

    /** Rows per step when a database cache is thinned out. */
    private const DATABASE_CHUNK = 1000;

    /** @var array<string, true> the Valkey/Redis databases swept in this run, per prefix */
    private array $swept = [];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly RedisKeySweep $sweep,
    ) {}

    /**
     * session_keys, cache_keys and lock_keys count what was deleted, or are
     * null where a whole store was emptied without a count (a file,
     * memcached or array store). A Valkey database shared by several roles
     * is swept once, under the first role that reaches it (sessions, then
     * the cache, then the locks).
     *
     * @return array{users: int, clients: int, tokens: int, codes: int, session_store: string, cache_store: string, sessions_separate: bool, session_keys: ?int, cache_keys: ?int, lock_keys: ?int}
     */
    public function run(string $reason): array
    {
        $this->swept = [];
        $accounts = [];
        foreach (PrincipalType::cases() as $type) {
            // A higher epoch ends every browser session that started before, whatever
            // the session store; the remember-me cookie goes with the token.
            $accounts[$type->value] = DB::table(app($type->modelClass())->getTable())
                ->update(['session_epoch' => DB::raw('session_epoch + 1'), 'remember_token' => null]);
        }

        /** @var class-string<PersonalAccessToken> $tokenModel */
        $tokenModel = Sanctum::personalAccessTokenModel();
        $tokens = $tokenModel::query()->delete();

        // Every unused one-time secret (login link, SMS, mobile and IVR code) is used up,
        // whatever its expiry says: the dump brings back the ones consumed or revoked since
        // the backup. The decryptable copy and the lookup go with it, as on every other
        // way a code is used up; so does a copy an earlier release left on a used code.
        $codes = OneTimeCode::query()->usable()->count();
        OneTimeCode::query()->whereNull('consumed_at')->update(['consumed_at' => now(), 'lookup' => null, 'secret_encrypted' => null]);
        OneTimeCode::query()->whereNotNull('secret_encrypted')->update(['secret_encrypted' => null]);

        $sessionKeys = $this->emptySessionStore();
        [$cacheKeys, $lockKeys] = $this->emptyCache();

        $result = [
            'users' => $accounts[PrincipalType::User->value],
            'clients' => $accounts[PrincipalType::Client->value],
            'tokens' => $tokens,
            'codes' => $codes,
            'session_store' => (string) config('session.driver'),
            'cache_store' => (string) config('cache.default'),
            'sessions_separate' => SessionStore::onRedis() && ! SessionStore::sharesMainStore(),
            'session_keys' => $sessionKeys,
            'cache_keys' => $cacheKeys,
            'lock_keys' => $lockKeys,
        ];

        $this->auditor->record('access.revoked_all', null, ['reason' => $reason] + $result);

        return $result;
    }

    /**
     * The sessions wherever the configured driver keeps them: this
     * installation's keys in a Valkey/Redis store, another cache store
     * (memcached) whole, the database table or the session files.
     */
    private function emptySessionStore(): ?int
    {
        $handler = app('session')->driver()->getHandler();

        if ($handler instanceof CacheBasedSessionHandler) {
            $store = $handler->getCache()->getStore();

            if ($store instanceof RedisStore) {
                return $this->sweepOnce($store->connection(), $store->getPrefix());
            }

            $handler->getCache()->flush();

            return null;
        }

        if ($handler instanceof DatabaseSessionHandler) {
            return DB::connection(config('session.connection'))->table((string) config('session.table'))->delete();
        }

        if ($handler instanceof FileSessionHandler) {
            $files = File::files((string) config('session.files'));
            File::delete($files);

            return count($files);
        }

        return null;
    }

    /**
     * The cache and its locks: the lockouts, rate limits and other state of
     * the backup time must not come back. The replay guards stay, so a token
     * or a signed request already used stays used.
     *
     * @return array{0: ?int, 1: ?int} the cache entries and the locks deleted
     */
    private function emptyCache(): array
    {
        $cache = Cache::store();
        $store = $cache->getStore();

        if ($store instanceof RedisStore) {
            $cacheKeys = $this->sweepOnce($store->connection(), $store->getPrefix());
            $lockKeys = $store->hasSeparateLockStore() ? $this->sweepOnce($store->lockConnection(), $store->getPrefix()) : 0;

            return [$cacheKeys, $lockKeys];
        }

        if ($store instanceof DatabaseStore) {
            return $this->thinDatabaseCache($store, (array) config('cache.stores.'.Cache::getDefaultDriver(), []));
        }

        $cache->flush();
        if ($store instanceof CanFlushLocks && $store->hasSeparateLockStore()) {
            $cache->flushLocks();
        }

        return [null, null];
    }

    /**
     * Sweep a Valkey/Redis database for the store prefix, unless this run
     * already swept the same database for the same prefix.
     */
    private function sweepOnce(Connection $connection, string $storePrefix): int
    {
        $target = RedisKeySweep::location($connection).'|'.RedisKeySweep::connectionPrefix($connection).$storePrefix;

        if (isset($this->swept[$target])) {
            return 0;
        }

        $this->swept[$target] = true;

        return $this->sweep->sweep($connection, $storePrefix, self::KEEP);
    }

    /**
     * Every row of the cache table but the kept ones (compared in PHP, so no
     * LIKE wildcard of the prefix can widen the match), and every lock.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: int, 1: int}
     */
    private function thinDatabaseCache(DatabaseStore $store, array $config): array
    {
        $connection = $store->getConnection();
        $table = (string) ($config['table'] ?? 'cache');
        $kept = array_map(fn (string $prefix): string => $store->getPrefix().$prefix, self::KEEP);
        $deleted = 0;

        $connection->table($table)->select('key')->chunkById(self::DATABASE_CHUNK, function (Collection $rows) use ($connection, $table, $kept, &$deleted): void {
            $doomed = $rows->pluck('key')->map(fn (mixed $key): string => (string) $key)
                ->reject(function (string $key) use ($kept): bool {
                    foreach ($kept as $prefix) {
                        if (str_starts_with($key, $prefix)) {
                            return true;
                        }
                    }

                    return false;
                })->values()->all();

            if ($doomed !== []) {
                $deleted += $connection->table($table)->whereIn('key', $doomed)->delete();
            }
        }, 'key');

        /** @var ConnectionInterface $lockConnection */
        $lockConnection = $store->getLockConnection() ?? $connection;
        $locks = $lockConnection->table((string) ($config['lock_table'] ?? 'cache_locks'))->delete();

        return [$deleted, $locks];
    }
}
