<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Auth\Contracts\Principal;
use App\Auth\Permission;
use App\Clients\ClientTiers;
use App\Enums\ClientTier;
use App\Enums\PrincipalType;
use App\Models\Concerns\HasAccountLifecycle;
use App\Models\Concerns\HasUuidKey;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Staff account: helpdesk agents, supervisors and admins.
 */
#[Fillable(['name', 'email', 'password', 'external_record_id', 'handles_tiers', 'active_tier'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, Principal
{
    use Auditable;
    use HasAccountLifecycle;
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use HasUuidKey;
    use Notifiable;
    use SoftDeletes;

    protected string $guard_name = 'web';

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'handles_tiers' => 'array',
            'active_tier' => ClientTier::class,
            'closed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function principalType(): PrincipalType
    {
        return PrincipalType::User;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->isClosed()) {
            return false;
        }

        return $this->hasAnyPermission([Permission::AdminAccess->value, Permission::HelpdeskAccess->value]);
    }

    /** @return HasMany<IdSession, $this> */
    public function idSessions(): HasMany
    {
        return $this->hasMany(IdSession::class, 'agent_user_id');
    }

    /**
     * Service levels this user may handle, limited to the levels in use
     * (those with configured packages); empty or missing means all of them.
     *
     * @return list<ClientTier>
     */
    public function handledTiers(): array
    {
        $active = app(ClientTiers::class)->activeTiers();
        $chosen = array_values(array_filter(array_map(fn ($t) => ClientTier::tryFrom((string) $t), $this->handles_tiers ?? [])));
        $tiers = array_values(array_filter($chosen, fn (ClientTier $t) => in_array($t, $active, true)));

        return $tiers === [] ? $active : $tiers;
    }

    public function handlesTier(ClientTier $tier): bool
    {
        return in_array($tier, $this->handledTiers(), true);
    }

    /**
     * Which calls the user is looking at right now: the tier they switched
     * to, or everything they may handle. Only calls and call queues follow
     * this; clients and everything else follow the user's permissions.
     *
     * @return list<ClientTier>
     */
    public function visibleTiers(): array
    {
        if ($this->active_tier instanceof ClientTier && $this->handlesTier($this->active_tier)) {
            return [$this->active_tier];
        }

        return $this->handledTiers();
    }

    /**
     * Whether the current view is narrowed to one tier.
     */
    public function activeTier(): ?ClientTier
    {
        return count($this->visibleTiers()) === 1 && count($this->handledTiers()) > 1 ? $this->visibleTiers()[0] : null;
    }

    public function switchTier(?ClientTier $tier): void
    {
        $this->forceFill(['active_tier' => $tier !== null && $this->handlesTier($tier) ? $tier : null])->saveQuietly();
    }
}
