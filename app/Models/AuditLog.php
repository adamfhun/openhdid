<?php

namespace App\Models;

use App\Models\Concerns\HasTimeOrderedUuidKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

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

    /**
     * The subject, also when its key is not a UUID: the Auditor keeps such a
     * key (the integer id of the package's Role) in the context as
     * subject_key, because the uuid column cannot hold it on MariaDB.
     */
    public function subjectRecord(): ?Model
    {
        $subject = $this->subject;
        $key = $this->context['subject_key'] ?? null;

        if ($subject !== null || $this->subject_id !== null || $this->subject_type === null || ! (is_int($key) || is_string($key))) {
            return $subject;
        }

        $class = Relation::getMorphedModel($this->subject_type) ?? $this->subject_type;
        if (! is_a($class, Model::class, true)) {
            return null;
        }

        $found = $class::query()->find($key);
        $this->setRelation('subject', $found);

        return $found;
    }
}
