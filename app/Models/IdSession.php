<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Enums\IdChannel;
use App\Enums\IdMethod;
use App\Enums\IdSessionStatus;
use App\Models\Concerns\HasTimeOrderedUuidKey;
use Database\Factories\IdSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One identification attempt (Q-A, PIN or mobile code) on one channel.
 * Q-A rule values are snapshotted from settings at start.
 */
#[Fillable(['client_id', 'agent_user_id', 'call_id', 'channel', 'method', 'status', 'required_accepted', 'max_questions', 'max_rejected', 'started_at', 'expires_at'])]
class IdSession extends Model
{
    use Auditable;

    /** @use HasFactory<IdSessionFactory> */
    use HasFactory;

    use HasTimeOrderedUuidKey;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'channel' => IdChannel::class,
            'method' => IdMethod::class,
            'status' => IdSessionStatus::class,
            'required_accepted' => 'integer',
            'max_questions' => 'integer',
            'max_rejected' => 'integer',
            'accepted_count' => 'integer',
            'rejected_count' => 'integer',
            'undecided_count' => 'integer',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    /**
     * The short call retention soft-deletes ended calls after a day, while
     * the session itself lives for years: without withTrashed() the call
     * behind an older identification would show up empty.
     *
     * @return BelongsTo<Call, $this>
     */
    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class)->withTrashed();
    }

    /** @return HasMany<IdSessionStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(IdSessionStep::class, 'id_session_id');
    }

    public function isOpen(): bool
    {
        return $this->status === IdSessionStatus::Open;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function askedCount(): int
    {
        return $this->accepted_count + $this->rejected_count + $this->undecided_count;
    }

    /**
     * @param  Builder<IdSession>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', IdSessionStatus::Open);
    }
}
