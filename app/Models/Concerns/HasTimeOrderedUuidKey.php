<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Time-ordered (v7) UUID primary key for event-like rows: sessions, steps,
 * calls, audit entries, sync runs, one-time codes.
 */
trait HasTimeOrderedUuidKey
{
    use HasUuids;
}
