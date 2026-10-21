<?php

namespace App\Models;

use App\Enums\SyncChangeKind;
use App\Models\Concerns\HasTimeOrderedUuidKey;
use Database\Factories\SyncRunChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One directory record's changes in one EMD sync run: the record diff and
 * the account and phone number diffs that followed from it.
 *
 * @phpstan-type ChangeEntry array{event: string, subject_type: ?string, subject_id: ?string, context: array<string, mixed>}
 */
#[Fillable(['sync_run_id', 'external_id', 'kind', 'name', 'email', 'subject_type', 'subject_id', 'changes'])]
class SyncRunChange extends Model
{
    /** @use HasFactory<SyncRunChangeFactory> */
    use HasFactory;

    use HasTimeOrderedUuidKey;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'external_id' => 'integer',
            'kind' => SyncChangeKind::class,
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SyncRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'sync_run_id')->withTrashed();
    }

    /**
     * The account the record drives (client or staff), when there is one.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }
}
