<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Messaging\MessageKey;
use App\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Administrator override of a message's subject and body for one language.
 * A null body means "use the code default".
 */
#[Fillable(['key', 'locale', 'subject', 'body', 'updated_by_user_id'])]
class MessageTemplate extends Model
{
    use Auditable;
    use HasUuidKey;

    protected function casts(): array
    {
        return [
            'key' => MessageKey::class,
        ];
    }

    public function isCustomised(): bool
    {
        return $this->body !== null;
    }

    public function effectiveSubject(): ?string
    {
        return $this->subject ?? $this->key->defaultSubject($this->locale);
    }

    public function effectiveBody(): string
    {
        return $this->body ?? $this->key->defaultBody($this->locale);
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
