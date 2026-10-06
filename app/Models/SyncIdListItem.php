<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Models\Concerns\HasUuidKey;
use Database\Factories\SyncIdListItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry of the EMD ID list: the ID sent in the export payload's `{{ ids }}`
 * when selected and still listed. Additions, renames, removals, returns and
 * selection changes reach the audit log through Auditable.
 */
#[Fillable(['external_id', 'name', 'selected', 'first_seen_at', 'last_seen_at', 'removed_at'])]
class SyncIdListItem extends Model
{
    use Auditable;

    /** @use HasFactory<SyncIdListItemFactory> */
    use HasFactory;

    use HasUuidKey;

    /**
     * Every query stamps last_seen_at on every listed row; auditing it would
     * write one log row per item per sync.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['last_seen_at'];

    protected function casts(): array
    {
        return [
            'selected' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeListed(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    public function isListed(): bool
    {
        return $this->removed_at === null;
    }
}
