<?php

namespace App\System;

use App\Audit\Auditor;
use App\Enums\PrincipalType;
use App\Models\OneTimeCode;
use Illuminate\Contracts\Cache\CanFlushLocks;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\FileSessionHandler;
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
 * session store and the cache are emptied wherever they live (a database
 * table, files, or the main and the session Valkey). The cache flush drops
 * the locks of running work too: the callers stop the workers and the
 * scheduler first. Audited as access.revoked_all with the counts.
 */
final class SignOutEveryone
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @return array{users: int, clients: int, tokens: int, codes: int, session_store: string, cache_store: string, sessions_separate: bool}
     */
    public function run(string $reason): array
    {
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

        // Every live one-time secret (login link, SMS, mobile and IVR code) is used up:
        // the dump brings back the ones consumed or revoked since the backup.
        $codes = OneTimeCode::query()->usable()->update(['consumed_at' => now(), 'lookup' => null]);

        $this->emptySessionStore();
        $this->emptyCache();

        $result = [
            'users' => $accounts[PrincipalType::User->value],
            'clients' => $accounts[PrincipalType::Client->value],
            'tokens' => $tokens,
            'codes' => $codes,
            'session_store' => (string) config('session.driver'),
            'cache_store' => (string) config('cache.default'),
            'sessions_separate' => SessionStore::onRedis() && ! SessionStore::sharesMainStore(),
        ];

        $this->auditor->record('access.revoked_all', null, ['reason' => $reason] + $result);

        return $result;
    }

    /**
     * The sessions wherever the configured driver keeps them: a cache store
     * (redis, memcached, and so the session Valkey's database), the database
     * table or the session files.
     */
    private function emptySessionStore(): void
    {
        $handler = app('session')->driver()->getHandler();

        if ($handler instanceof CacheBasedSessionHandler) {
            $handler->getCache()->flush();
        } elseif ($handler instanceof DatabaseSessionHandler) {
            DB::connection(config('session.connection'))->table((string) config('session.table'))->delete();
        } elseif ($handler instanceof FileSessionHandler) {
            File::delete(File::files((string) config('session.files')));
        }
    }

    /**
     * The cache (and, on a store that keeps them apart, the locks): the
     * lockouts, rate limits and replay guards of the backup time must not
     * come back either.
     */
    private function emptyCache(): void
    {
        $cache = Cache::store();
        $cache->flush();

        $store = $cache->getStore();
        if ($store instanceof CanFlushLocks && $store->hasSeparateLockStore()) {
            $cache->flushLocks();
        }
    }
}
