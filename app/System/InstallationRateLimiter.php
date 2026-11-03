<?php

namespace App\System;

use Illuminate\Cache\RateLimiter;

/**
 * The framework's rate limiter with every counter under this installation's
 * id ("hdid-<id>:", Installation::prefix()): the route throttles, the
 * panel's sign-in limit, the agents' hourly limit, the second-factor sends,
 * the per-address and per-number limits and the system-wide sign-in
 * ceilings. Two installations on one Valkey/Redis under one key prefix
 * (a staging copy of production, say) each count their own; the servers of
 * one installation share the APP_KEY and the APP_URL, so they still count
 * together.
 *
 * The framework cleans a key again inside its own calls (remaining() and
 * tooManyAttempts() go through attempts()), so the prefix is added only
 * once. The id is read on every call, never kept, so a changed APP_KEY or
 * APP_URL takes effect at once.
 */
class InstallationRateLimiter extends RateLimiter
{
    /**
     * Takes over the store and the named limiters (RateLimiter::for) of the
     * framework's limiter.
     */
    public static function from(RateLimiter $limiter): self
    {
        $own = new self($limiter->cache);
        $own->limiters = $limiter->limiters;

        return $own;
    }

    public function cleanRateLimiterKey(mixed $key): string
    {
        $clean = (string) parent::cleanRateLimiterKey((string) $key);
        $prefix = Installation::prefix();

        return str_starts_with($clean, $prefix) ? $clean : $prefix.$clean;
    }
}
