<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client whose premium access comes from another client's premium
 * package. When the sponsor's account is closed, the linked accounts are
 * closed with it. Ended links are kept for the record.
 */
#[Fillable(['sponsor_client_id', 'linked_client_id', 'created_by_user_id', 'ended_by_user_id', 'ended_at'])]
class ClientLink extends Model
{
    use Auditable;
    use HasUuidKey;

    protected function casts(): array
    {
        return ['ended_at' => 'datetime'];
    }

    /** @return BelongsTo<Client, $this> */
    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'sponsor_client_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function linked(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'linked_client_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * @param  Builder<ClientLink>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('ended_at');
    }
}
