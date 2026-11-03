<?php

namespace Tests\Support;

use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * A test clock that can stand in the first pass of the hour the clocks go
 * back (2026-10-25 02:00–03:00 CEST in Europe/Budapest).
 *
 * Carbon rebuilds a frozen "now" from its wall-clock text, and an ambiguous
 * 02:xx always lands on the second (CET) pass: a test frozen at 00:15 UTC
 * silently runs at 01:15 UTC. This clock rebuilds "now" from the frozen
 * instant's epoch seconds instead.
 *
 * Enable it with Date::use(RepeatedHourClock::class) and reset it with
 * Date::useDefault() in afterEach. Move it only with travelTo() and explicit
 * UTC instants (Carbon::parse('…', 'UTC')); travel() and the RateLimiter read
 * the plain Carbon clock, which still collapses the first pass.
 */
class RepeatedHourClock extends Carbon
{
    public static function now(DateTimeZone|string|int|null $timezone = null): static
    {
        $frozen = static::getTestNow();

        if (! $frozen instanceof \DateTimeInterface) {
            return parent::now($timezone);
        }

        return static::createFromFormat('U.u', $frozen->format('U.u'))
            ->setTimezone($timezone ?? date_default_timezone_get());
    }
}
