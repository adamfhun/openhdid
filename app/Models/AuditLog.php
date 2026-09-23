<?php

namespace App\Models;

use App\Models\Concerns\HasTimeOrderedUuidKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Table('audit_log')]
#[Fillable(['event', 'actor_type', 'actor_id', 'subject_type', 'subject_id', 'context', 'ip_address', 'user_agent', 'request_id'])]
class AuditLog extends Model
{
    use HasTimeOrderedUuidKey;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Soft-deleted accounts and records still resolve, so a "deleted" entry
     * names what was deleted instead of showing a bare id.
     */
    public function actor(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }
}
