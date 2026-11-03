<?php

namespace App\System;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;
use Throwable;

/**
 * Deletes the keys of one store from a Valkey/Redis database by key prefix,
 * never with FLUSHDB: the database may also hold the queue (its "queues:"
 * keys sit under the connection prefix only, beside the cache prefix) and
 * the keys of another installation under a prefix of its own. A sweep
 * SCANs for "<connection prefix><store prefix>*" (the glob characters of
 * the prefix escaped), skips the queue's keys whatever the store prefix is
 * (an empty CACHE_PREFIX would match them) and the kept key prefixes (the
 * replay guards), and UNLINKs the rest in batches (DEL where UNLINK is not
 * allowed). The keys go to UNLINK without the connection prefix, which the
 * client adds by itself.
 */
final class RedisKeySweep
{
    /** Keys asked per SCAN step, and keys per UNLINK. */
    public const BATCH = 1000;

    /** The queue's keys, right after the connection prefix (Illuminate\Queue\RedisQueue). */
    public const QUEUE_PREFIX = 'queues:';

    /**
     * Delete every key of the store prefix on the connection, except the
     * queue's and those under one of the kept prefixes (relative to the
     * store prefix). Returns the number of keys deleted.
     *
     * @param  list<string>  $keep
     */
    public function sweep(Connection $connection, string $storePrefix, array $keep = []): int
    {
        $connectionPrefix = self::connectionPrefix($connection);
        $queue = $connectionPrefix.self::QUEUE_PREFIX;
        $kept = array_map(fn (string $prefix): string => $connectionPrefix.$storePrefix.$prefix, $keep);
        $match = self::pattern($connectionPrefix.$storePrefix);
        $cursor = self::initialCursor($connection);
        $deleted = 0;

        do {
            $result = $connection->scan($cursor, ['match' => $match, 'count' => self::BATCH]);

            if (! is_array($result)) {
                break;
            }

            [$cursor, $keys] = $result;
            $doomed = [];

            foreach (is_array($keys) ? $keys : [] as $key) {
                $key = (string) $key;

                if (str_starts_with($key, $queue) || self::startsWithAny($key, $kept)) {
                    continue;
                }

                $doomed[] = $connectionPrefix !== '' && str_starts_with($key, $connectionPrefix) ? substr($key, strlen($connectionPrefix)) : $key;
            }

            foreach (array_chunk(array_values(array_unique($doomed)), self::BATCH) as $batch) {
                $deleted += $this->delete($connection, $batch);
            }
        } while ($cursor !== null && $cursor !== false && (string) $cursor !== '0');

        return $deleted;
    }

    /**
     * The prefix the client puts in front of every key of the connection
     * (REDIS_PREFIX), read the way the framework's own cache tag cleanup does.
     */
    public static function connectionPrefix(Connection $connection): string
    {
        if ($connection instanceof PhpRedisConnection) {
            return (string) $connection->_prefix('');
        }

        if ($connection instanceof PredisConnection) {
            $prefix = $connection->getOptions()->prefix ?? null;

            return is_object($prefix) && method_exists($prefix, 'getPrefix') ? (string) $prefix->getPrefix() : (string) ($prefix ?? '');
        }

        return '';
    }

    /**
     * The SCAN glob for every key that starts with the given prefix: the
     * glob characters of the prefix itself match only themselves.
     */
    public static function pattern(string $prefix): string
    {
        return addcslashes($prefix, '\\*?[]').'*';
    }

    /**
     * Where the connection's keys live, "host:port/database", from its
     * configuration (database.redis.<name>), so that two connections to the
     * same database are swept once.
     */
    public static function location(Connection $connection): string
    {
        $name = (string) $connection->getName();
        $config = (array) config("database.redis.{$name}", []);

        if ($config === []) {
            return 'connection:'.$name;
        }

        if (! empty($config['url'])) {
            $config = array_merge($config, (new ConfigurationUrlParser)->parseConfiguration($config));
        }

        return strtolower(trim((string) ($config['host'] ?? ''))).':'.trim((string) ($config['port'] ?? '6379')).'/'.trim((string) ($config['database'] ?? '0'));
    }

    /**
     * The cursor a SCAN starts from: phpredis 6.1 and later take null, older
     * phpredis and predis "0" (as the framework's own tag cleanup does).
     */
    private static function initialCursor(Connection $connection): ?string
    {
        $version = $connection instanceof PhpRedisConnection ? phpversion('redis') : false;

        return $version !== false && version_compare($version, '6.1.0', '>=') ? null : '0';
    }

    /**
     * @param  list<string>  $prefixes
     */
    private static function startsWithAny(string $key, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * UNLINK frees the memory in the background; a server or an ACL user
     * that does not allow it gets DEL. A store that refuses both stops the
     * sign-out loudly: its sessions would otherwise stay valid.
     *
     * @param  list<string>  $keys
     */
    private function delete(Connection $connection, array $keys): int
    {
        try {
            $removed = $connection->command('unlink', $keys);
        } catch (Throwable) {
            $removed = false;
        }

        if ($removed === false) {
            $removed = $connection->command('del', $keys);
        }

        if ($removed === false) {
            throw new RuntimeException('The Valkey/Redis server refused both UNLINK and DEL: allow them (and SCAN) for the application\'s user.');
        }

        return is_int($removed) ? $removed : count($keys);
    }
}
