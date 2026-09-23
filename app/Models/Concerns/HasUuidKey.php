<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids;

/**
 * Random (v4) UUID primary key for master data that is not time-ordered.
 */
trait HasUuidKey
{
    use HasVersion4Uuids;
}
