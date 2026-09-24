<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Enums\CallStatus;
use App\Enums\ClientTier;
use App\Enums\IdSessionStatus;
use App\Models\Concerns\HasTimeOrderedUuidKey;
use Database\Factories\CallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['external_call_id', 'caller_number_raw', 'caller_number_e164', 'client_id', 'agent_user_id', 'status', 'queue', 'tier', 'metadata', 'arrived_at', 'answered_at', 'ended_at', 'handled_at', 'handled_by_user_id'])]
class Call extends Model
{
    use Auditable;

    /** @use HasFactory<CallFactory> */
    use HasFactory;

    use HasTimeOrderedUuidKey;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => CallStatus::class,
            'tier' => ClientTier::class,
            'metadata' => 'array',
            'arrived_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'handled_at' => 'datetime',
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

    /** @return HasMany<IdSession, $this> */
    public function idSessions(): HasMany
    {
        return $this->hasMany(IdSession::class);
    }

    /**
     * Calls of the service levels a user is currently looking at.
     *
     * @param  Builder<Call>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('tier', array_map(fn (ClientTier $t) => $t->value, $user->visibleTiers()));
    }

    /**
     * @param  Builder<Call>  $query
     */
    public function scopeOngoing(Builder $query): void
    {
        $query->whereNotIn('status', [CallStatus::Ended, CallStatus::Missed]);
    }

    /**
     * Calls that ended before any agent took them, not yet handled.
     *
     * @param  Builder<Call>  $query
     */
    public function scopeMissed(Builder $query): void
    {
        $query->whereNull('handled_at')->whereNull('agent_user_id')
            ->whereIn('status', [CallStatus::Missed, CallStatus::Ended]);
    }

    public function isHeldBy(User $user): bool
    {
        return $this->agent_user_id !== null && $this->agent_user_id === $user->id;
    }

    public function isHeldBySomeoneElse(User $user): bool
    {
        return $this->agent_user_id !== null && $this->agent_user_id !== $user->id;
    }

    public function isMissed(): bool
    {
        return $this->status->isOver() && $this->agent_user_id === null;
    }

    /**
     * Whether this agent may attach an identification (or a note) to the
     * call: the call they hold, or a missed call nobody handled yet, which
     * is being called back and can no longer be claimed. A ringing call
     * nobody holds still has to be claimed from the dashboard first, and a
     * handled missed call is closed for good.
     */
    public function isAttachableBy(User $user): bool
    {
        return $this->isHeldBy($user) || ($this->isMissed() && $this->handled_at === null);
    }

    /** The supplied number is still useful for display when normalization fails. */
    public function callerNumber(): ?string
    {
        return filled($this->caller_number_e164) ? $this->caller_number_e164 : (filled($this->caller_number_raw) ? $this->caller_number_raw : null);
    }

    public function markHandled(User $by): void
    {
        $this->forceFill(['handled_at' => now(), 'handled_by_user_id' => $by->id])->save();
    }

    /**
     * Identification sessions run on this call for the client it is matched to
     * now. Sessions of a client the call was moved away from do not count, so
     * a pass made by the previous client can never vouch for the new one.
     *
     * @return Collection<int, IdSession>
     */
    public function clientIdSessions(): Collection
    {
        if ($this->client_id === null) {
            return $this->newCollection();
        }

        return $this->idSessions->where('client_id', $this->client_id);
    }

    /**
     * The latest successful identification of the currently matched client
     * made on this call, if any.
     */
    public function passedSession(): ?IdSession
    {
        return $this->clientIdSessions()->where('status', IdSessionStatus::Passed)->sortByDesc('decided_at')->first();
    }

    /**
     * Once the caller has been identified an agent has really worked with
     * them: the call belongs to that agent and cannot go back to the queue.
     */
    public function isIdentified(): bool
    {
        return $this->passedSession() !== null;
    }
}
