<?php

namespace App\Models;

use App\Enums\SyncRunStatus;
use App\Enums\SyncSource;
use App\Models\Concerns\HasTimeOrderedUuidKey;
use Database\Factories\SyncRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['source', 'status', 'file_name', 'started_at', 'finished_at', 'stats', 'skipped_rows', 'error', 'triggered_by_user_id', 'dry_run'])]
class SyncRun extends Model
{
    /** @use HasFactory<SyncRunFactory> */
    use HasFactory;

    use HasTimeOrderedUuidKey;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'source' => SyncSource::class,
            'status' => SyncRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'stats' => 'array',
            'skipped_rows' => 'array',
            'dry_run' => 'boolean',
        ];
    }
}
