<?php

namespace App\System;

/**
 * The production safeguards in one place, read from the configuration: the
 * environment names the container start check accepts, the proxy entries
 * that trust every address, and the values that switch a safeguard off in
 * production. The container refuses to start with these (runtime.php
 * check-env); on a classic installation nothing stops them, so the status
 * page names them (HealthChecks::environment).
 */
final class EnvironmentSafety
{
    /** The environment names the container start check accepts. */
    public const KNOWN_ENVIRONMENTS = ['production', 'staging', 'demo', 'local', 'testing'];

    /** TRUSTED_PROXIES entries that trust every address (Laravel reads * and ** so too). */
    public const TRUST_ALL = ['*', '**', '0.0.0.0/0', '::/0'];

    public static function environment(): string
    {
        return (string) app()->environment();
    }

    public static function isKnownEnvironment(): bool
    {
        return in_array(self::environment(), self::KNOWN_ENVIRONMENTS, true);
    }

    /**
     * Whether the production rules apply: in production, and under a name
     * the framework does not know (APP_ENV=prod would otherwise switch every
     * production-only rule off without a word).
     */
    public static function productionSafeguards(): bool
    {
        return app()->environment('production') || ! self::isKnownEnvironment();
    }

    /**
     * The TRUSTED_PROXIES entries that trust every address; the value may be
     * a comma-separated list or an array.
     *
     * @return list<string>
     */
    public static function trustAllProxies(): array
    {
        $value = config('trustedproxy.proxies');
        $entries = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_intersect(array_map(fn (mixed $entry): string => trim((string) $entry), $entries), self::TRUST_ALL));
    }

    /**
     * What a production installation must not run with, each naming its key.
     *
     * @return list<string>
     */
    public static function productionProblems(): array
    {
        $problems = [];

        if ((bool) config('app.debug')) {
            $problems[] = __('debug mode is on (APP_DEBUG=true): error pages show internals to anyone, and the panel shows every client login link it sends');
        }

        if (($trustAll = self::trustAllProxies()) !== []) {
            $problems[] = __('TRUSTED_PROXIES=:value trusts every address, so anyone can forge their address and the host of generated links', ['value' => implode(',', $trustAll)]);
        }

        if (config('session.secure') === false) {
            $problems[] = __('the session cookie also travels over plain http (SESSION_SECURE_COOKIE=false)');
        }

        if (parse_url((string) config('app.url'), PHP_URL_SCHEME) !== 'https') {
            $problems[] = __('APP_URL is not an https:// address');
        }

        $connection = (array) config('database.connections.'.config('database.default'), []);
        if (in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true) && blank($connection['password'] ?? null) && blank($connection['unix_socket'] ?? null)) {
            $problems[] = __('the database account has no password (DB_PASSWORD)');
        }

        return $problems;
    }

    /**
     * The stores that use Redis/Valkey while it has no password.
     *
     * @return list<string>
     */
    public static function redisWithoutPassword(): array
    {
        $stores = array_keys(array_filter([
            'CACHE_STORE' => config('cache.default') === 'redis',
            'QUEUE_CONNECTION' => config('queue.default') === 'redis',
            'SESSION_DRIVER' => config('session.driver') === 'redis',
        ]));

        return $stores !== [] && blank(config('database.redis.default.password')) ? $stores : [];
    }

    /**
     * A local (developer) setup under an address that is not the developer's own machine.
     */
    public static function localUnderPublicAddress(): bool
    {
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return self::environment() === 'local'
            && $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            && ! str_ends_with($host, '.test')
            && ! str_ends_with($host, '.localhost');
    }
}
