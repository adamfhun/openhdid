<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Auth\Contracts\Principal;
use App\Clients\ClientLinks;
use App\Enums\PrincipalType;
use App\Models\Concerns\HasAccountLifecycle;
use App\Models\Concerns\HasUuidKey;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Caller account: the person the helpdesk has to identify.
 */
#[Fillable(['name', 'email', 'external_record_id', 'implicit_package', 'explicit_package', 'notes'])]
#[Hidden(['pin_hash', 'pin_lookup', 'remember_token'])]
class Client extends Authenticatable implements Principal
{
    use Auditable;
    use HasAccountLifecycle {
        close as private closeAccount;
        reopen as private reopenAccount;
    }
    use HasApiTokens;

    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    use HasUuidKey;
    use Notifiable;
    use SoftDeletes;

    /** @var list<string> */
    protected array $auditExclude = ['pin_failed_attempts', 'pin_locked_until', 'pin_lockout_count', 'last_login_at'];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'pin_set_at' => 'datetime',
            'pin_locked_until' => 'datetime',
            'pin_failed_attempts' => 'integer',
            'pin_lockout_count' => 'integer',
            'package_override_from' => 'datetime',
            'package_override_until' => 'datetime',
            'link_warning_muted_at' => 'datetime',
            'link_warning_muted_until' => 'datetime',
        ];
    }

    public function principalType(): PrincipalType
    {
        return PrincipalType::Client;
    }

    /**
     * A time-boxed manual override of the explicit package (see PackageOverrides).
     */
    public function hasActivePackageOverride(): bool
    {
        return $this->package_override !== null
            && ($this->package_override_until === null || $this->package_override_until->isFuture());
    }

    /**
     * The "explicit premium without a link" warning is silenced for this
     * client (see ClientLinks::muteWarning); an expired mute no longer counts.
     */
    public function hasMutedLinkWarning(): bool
    {
        return $this->link_warning_muted_at !== null
            && ($this->link_warning_muted_until === null || $this->link_warning_muted_until->isFuture());
    }

    /**
     * @param  Builder<Client>  $query
     */
    public function scopeLinkWarningMuted(Builder $query, bool $muted = true): void
    {
        $active = fn (Builder $q) => $q->whereNotNull('link_warning_muted_at')
            ->where(fn (Builder $w) => $w->whereNull('link_warning_muted_until')->orWhere('link_warning_muted_until', '>', now()));

        $muted ? $active($query) : $query->whereNot(fn (Builder $q) => $active($q));
    }

    public function isSynced(): bool
    {
        return $this->external_record_id !== null;
    }

    public function hasPin(): bool
    {
        return $this->pin_hash !== null;
    }

    /**
     * Closing a sponsor closes the clients linked to it; reopening brings
     * back those that were closed only because of the sponsor.
     */
    public function close(string $reason): void
    {
        $wasOpen = ! $this->isClosed();
        $this->closeAccount($reason);

        if ($wasOpen) {
            app(ClientLinks::class)->closeLinkedClients($this);
        }
    }

    public function reopen(): void
    {
        $wasClosed = $this->isClosed();
        $this->reopenAccount();

        if ($wasClosed) {
            app(ClientLinks::class)->reopenLinkedClients($this);
        }
    }

    /** @return HasMany<ClientLink, $this> links this client sponsors, ended ones included */
    public function sponsoredLinks(): HasMany
    {
        return $this->hasMany(ClientLink::class, 'sponsor_client_id');
    }

    /** @return HasMany<ClientLink, $this> */
    public function activeLinks(): HasMany
    {
        return $this->sponsoredLinks()->whereNull('ended_at');
    }

    /** @return HasMany<ClientLink, $this> links where this client is the linked one */
    public function sponsorLinks(): HasMany
    {
        return $this->hasMany(ClientLink::class, 'linked_client_id');
    }

    /** @return HasMany<ClientLink, $this> */
    public function activeSponsorLink(): HasMany
    {
        return $this->sponsorLinks()->whereNull('ended_at');
    }

    /**
     * Uses the eager-loaded active link when the caller preloaded it, so a
     * list does not run two queries per row.
     */
    public function sponsor(): ?Client
    {
        if ($this->relationLoaded('activeSponsorLink')) {
            return $this->getRelation('activeSponsorLink')->first()?->sponsor;
        }

        return $this->activeSponsorLink()->with('sponsor')->first()?->sponsor;
    }

    /** @return BelongsTo<User, $this> */
    public function packageOverrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'package_override_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function linkWarningMutedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'link_warning_muted_by_user_id');
    }

    /** @return HasMany<ClientPhoneNumber, $this> */
    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(ClientPhoneNumber::class);
    }

    /** @return HasMany<ClientAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(ClientAnswer::class);
    }

    /** @return HasMany<Call, $this> */
    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    /** @return HasMany<IdSession, $this> */
    public function idSessions(): HasMany
    {
        return $this->hasMany(IdSession::class);
    }

    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    public function primaryPhoneNumber(): ?string
    {
        return $this->primaryPhone()?->number_e164;
    }

    public function primaryPhone(): ?ClientPhoneNumber
    {
        $numbers = $this->relationLoaded('phoneNumbers') ? $this->phoneNumbers : $this->phoneNumbers()->get();

        return $numbers->firstWhere('is_primary', true) ?? $numbers->first();
    }
}
