<?php

namespace App\System;

use Illuminate\Support\ConfigurationUrlParser;

/**
 * Where the sessions live. With SESSION_DRIVER=redis they belong in a
 * Valkey of their own (the "session" connection, REDIS_SESSION_*) that may
 * evict the least recently used sessions when a flood of sign-in pages fills
 * it; the main one (queue, locks, rate limits, replay guards, cache) refuses
 * writes instead of dropping keys. Without REDIS_SESSION_HOST the session
 * connection falls back to the main server, which keeps older installations
 * working; the status page says so.
 */
final class SessionStore
{
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
}
