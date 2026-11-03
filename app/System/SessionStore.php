<?php

namespace App\System;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Support\Carbon;
use Illuminate\Support\ConfigurationUrlParser;
use Throwable;

/**
 * Where the sessions live. With SESSION_DRIVER=redis they belong in a
 * Valkey of their own (the "session" connection, REDIS_SESSION_*) that may
 * evict the least recently used sessions when a flood of sign-in pages fills
 * it; the main one (queue, locks, rate limits, replay guards, cache) refuses
 * writes instead of dropping keys. Without REDIS_SESSION_HOST the session
 * connection falls back to the main server, which keeps older installations
 * working; the status page says so.
 *
 * The scheduler writes a heartbeat of this installation into the session
 * store every minute: another installation's heartbeat found there means two
 * installations keep their sessions in one store. Each still refuses the
 * other's sessions (AuthSessions binds them to the installation), but a
 * sign-out of everyone, a restore or a flood on one reaches the other, so
 * the status page turns red.
 */
final class SessionStore
{
    /** This installation's heartbeat, in the session store. */
    public const HEARTBEAT_KEY = 'hdid.session-store.heartbeat';

    /** Sightings of another installation, in the session store, where both installations see them. */
    public const CONFLICT_KEY = 'hdid.session-store.conflict';

    public static function onRedis(): bool
    {
        return config('session.driver') === 'redis';
    }

    /**
     * The Redis connection the session driver uses.
     */
    public static function connection(): string
    {
        return (string) (config('session.connection') ?: 'default');
    }

    /**
     * Whether the sessions sit on the server of the queue and the locks (the
     * default connection): the same host and port, whatever database number.
     */
    public static function sharesMainStore(): bool
    {
        $connection = self::connection();

        return $connection === 'default' || self::endpoint($connection) === self::endpoint('default');
    }

    /**
     * host:port of a configured Redis connection, its URL taken into account.
     */
    private static function endpoint(string $connection): string
    {
        $config = (array) config("database.redis.{$connection}", []);

        if (! empty($config['url'])) {
            $config = array_merge($config, (new ConfigurationUrlParser)->parseConfiguration($config));
        }

        return strtolower(trim((string) ($config['host'] ?? ''))).':'.trim((string) ($config['port'] ?? '6379'));
    }

    /**
     * The cache the sessions live in, when the driver keeps them in one
     * (SESSION_DRIVER=redis), or null (database, file, array, cookie).
     */
    public static function cache(): ?Repository
    {
        $handler = app('session')->driver()->getHandler();

        return $handler instanceof CacheBasedSessionHandler ? $handler->getCache() : null;
    }

    /**
     * Write this installation's heartbeat into the session store (once a
     * minute, from the scheduler). Nothing to do where the sessions do not
     * live in a cache store; an unreachable session store is the tile's
     * business (it turns red on its own), not a reason to skip the rest of
     * the scheduler's heartbeat.
     */
    public static function beat(): void
    {
        try {
            if (($store = self::cache()) !== null) {
                Installation::beat(self::HEARTBEAT_KEY, 'session_store', $store, self::CONFLICT_KEY);
            }
        } catch (Throwable) {
            // The session_store tile names the unreachable store.
        }
    }

    /**
     * Another installation seen in the session store lately, or null.
     *
     * @return array{installation: string, source: string, at: Carbon}|null
     */
    public static function conflict(): ?array
    {
        try {
            $store = self::cache();

            return $store === null ? null : Installation::conflict($store, self::CONFLICT_KEY);
        } catch (Throwable) {
            return null;
        }
    }
}
