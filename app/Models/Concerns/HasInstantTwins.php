<?php

namespace App\Models\Concerns;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * A time column kept as local wall-clock text (APP_TIMEZONE) next to an
 * epoch twin (`<name>_unix`) that holds the same instant in seconds.
 *
 * On the night the clocks go back, 02:00–03:00 passes twice and the text
 * cannot tell the two passes apart: a limit compared as text treats the
 * second pass as the first. Every expiry, budget and freshness window that
 * guards a secret or a decision compares the twin instead (whereInstant).
 * Rows without a twin (written before it existed, or by a plain query-builder
 * insert) fall back to the wall clock.
 */
trait HasInstantTwins
{
    public static function twinOf(string $column): string
    {
        return Str::beforeLast($column, '_at').'_unix';
    }

    /**
     * The column and its twin for a query-builder write, which bypasses the
     * model's mutators.
     *
     * @return array<string, int|string>
     */
    public static function instantColumns(string $column, CarbonInterface $at): array
    {
        $local = $at->copy()->setTimezone(date_default_timezone_get());

        return [$column => $local->format((new static)->getDateFormat()), static::twinOf($column) => $local->getTimestamp()];
    }

    /**
     * Compare a time column as a real instant: on its twin, or on the wall
     * clock for a row that has no twin.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereInstant(Builder $query, string $column, string $operator, CarbonInterface $at): void
    {
        $twin = $query->qualifyColumn(static::twinOf($column));
        $column = $query->qualifyColumn($column);
        $local = $at->copy()->setTimezone(date_default_timezone_get());

        $query->where(fn (Builder $query) => $query
            ->where($twin, $operator, $local->getTimestamp())
            ->orWhere(fn (Builder $query) => $query->whereNull($twin)->where($column, $operator, $local)));
    }

    /**
     * The accessor callbacks that write the column and its twin together, so
     * they always name the same instant: Attribute::make(...$this->instantTwin('x_at')).
     * With readBack the attribute is also read from the twin, which gives
     * the true offset in the repeated hour; without it the column reads as
     * before (only the limits need the twin). Not an Attribute itself:
     * Eloquent takes every method returning one for an accessor.
     *
     * @return array{get: (Closure(mixed, array<string, mixed>): ?CarbonInterface)|null, set: Closure(mixed): array<string, int|string|null>}
     */
    protected function instantTwin(string $column, bool $readBack = true): array
    {
        $twin = static::twinOf($column);

        return [
            'get' => $readBack ? function (mixed $value, array $attributes) use ($twin): ?CarbonInterface {
                if (isset($attributes[$twin])) {
                    return Date::createFromTimestamp((int) $attributes[$twin], date_default_timezone_get());
                }

                return $value === null ? null : $this->asDateTime($value);
            } : null,
            'set' => function (mixed $value) use ($column, $twin): array {
                if ($value === null) {
                    return [$column => null, $twin => null];
                }

                $local = $this->asDateTime($value)->setTimezone(date_default_timezone_get());

                return [$column => $local->format($this->getDateFormat()), $twin => $local->getTimestamp()];
            },
        ];
    }
}
