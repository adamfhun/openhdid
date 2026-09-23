<?php

namespace App\Clients;

use App\Enums\ClientTier;
use App\Models\Client;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether a client may use the portal and at which service level.
 * A client qualifies when its implicit or explicit package is listed in the
 * premium or the standard package list (case-insensitive, trimmed).
 */
class ClientTiers
{
    public function __construct(private readonly Settings $settings) {}

    public function tierFor(Client $client): ?ClientTier
    {
        $packages = array_filter([
            $this->normalize($client->implicit_package),
            $this->normalize($client->explicit_package),
            $client->hasActivePackageOverride() ? $this->normalize($client->package_override) : null,
        ]);

        if ($packages === []) {
            return null;
        }

        if (array_intersect($packages, $this->packages(ClientTier::Premium)) !== []) {
            return ClientTier::Premium;
        }

        if (array_intersect($packages, $this->packages(ClientTier::Standard)) !== []) {
            return ClientTier::Standard;
        }

        return null;
    }

    /**
     * Service level of an inbound call. The call queue (one phone number =
     * one queue) decides; an unmapped queue falls back to the matched
     * client's tier, then to the configured default. During the pilot only
     * the premium call center is connected, so the default is premium.
     */
    public function tierForCall(?string $queue, ?Client $client = null): ClientTier
    {
        $queue = mb_strtolower(trim((string) $queue));
        $mapped = $this->settings->array(SettingKey::CallsQueueTiers);

        foreach ($mapped as $name => $tier) {
            if ($queue !== '' && mb_strtolower(trim((string) $name)) === $queue && ($resolved = ClientTier::tryFrom(mb_strtolower(trim((string) $tier)))) !== null) {
                return $resolved;
            }
        }

        if ($client !== null && ($tier = $this->tierFor($client)) !== null) {
            return $tier;
        }

        return ClientTier::tryFrom((string) $this->settings->string(SettingKey::CallsDefaultTier)) ?? ClientTier::Premium;
    }

    /**
     * Clients worth showing by default: anyone with portal access, anyone
     * closed (so they can be reopened), and anyone with any history. What
     * stays hidden is the untouched, directory-only account.
     *
     * @param  Builder<Client>  $query
     */
    public function scopeRelevant(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $this->scopeClients($q, ClientTier::cases());
            $q->orWhereNotNull('closed_at')
                ->orWhereNotNull('pin_hash')
                ->orWhereNotNull('package_override')
                ->orWhereNotNull('notes')
                ->orWhereHas('idSessions')
                ->orWhereHas('answers')
                ->orWhereHas('sponsorLinks')
                ->orWhereHas('sponsoredLinks')
                ->orWhereHas('calls');
        });
    }

    /**
     * Restrict a client query to the given service levels, by package name.
     *
     * @param  Builder<Client>  $query
     * @param  list<ClientTier>  $tiers
     */
    public function scopeClients(Builder $query, array $tiers): void
    {
        $names = [];

        foreach ($tiers as $tier) {
            $names = [...$names, ...$this->packages($tier)];
        }

        if ($names === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(fn (Builder $q) => $q
            ->whereIn(DB::raw('lower(trim(implicit_package))'), $names)
            ->orWhereIn(DB::raw('lower(trim(explicit_package))'), $names)
            ->orWhere(fn (Builder $o) => $o->whereIn(DB::raw('lower(trim(package_override))'), $names)->where('package_override_until', '>=', now())));
    }

    /**
     * Service levels that are in use: those with at least one package name
     * configured. A level without packages has no clients and no calls, so
     * the staff UI hides it entirely. With nothing configured every level
     * counts as active so that the panel stays usable.
     *
     * @return list<ClientTier>
     */
    public function activeTiers(): array
    {
        $active = array_values(array_filter(ClientTier::cases(), fn (ClientTier $tier) => $this->packages($tier) !== []));

        return $active === [] ? ClientTier::cases() : $active;
    }

    public function isActive(ClientTier $tier): bool
    {
        return in_array($tier, $this->activeTiers(), true);
    }

    /**
     * The client's own (implicit) package is premium, not only the explicit one.
     */
    public function hasImplicitPremium(Client $client): bool
    {
        $implicit = $this->normalize($client->implicit_package);

        return $implicit !== null && in_array($implicit, $this->packages(ClientTier::Premium), true);
    }

    /**
     * The level a single package name grants, or null when it is in no list.
     */
    public function tierOfPackage(?string $package): ?ClientTier
    {
        $name = $this->normalize($package);

        if ($name === null) {
            return null;
        }

        if (in_array($name, $this->packages(ClientTier::Premium), true)) {
            return ClientTier::Premium;
        }

        return in_array($name, $this->packages(ClientTier::Standard), true) ? ClientTier::Standard : null;
    }

    /**
     * The level the directory alone gives, ignoring any manual override.
     */
    public function tierFromDirectory(Client $client): ?ClientTier
    {
        $ranks = [ClientTier::Premium->value => 2, ClientTier::Standard->value => 1];
        $best = null;

        foreach ([$client->implicit_package, $client->explicit_package] as $package) {
            $tier = $this->tierOfPackage($package);

            if ($tier !== null && ($best === null || $ranks[$tier->value] > $ranks[$best->value])) {
                $best = $tier;
            }
        }

        return $best;
    }

    public function isEntitled(Client $client): bool
    {
        return $this->tierFor($client) !== null;
    }

    /**
     * @return array{email: ?string, phone: ?string, hours: ?string}
     */
    public function supportFor(ClientTier $tier): array
    {
        return match ($tier) {
            ClientTier::Premium => [
                'email' => $this->settings->string(SettingKey::SupportPremiumEmail),
                'phone' => $this->settings->string(SettingKey::SupportPremiumPhone),
                'hours' => $this->settings->string(SettingKey::SupportPremiumHours),
            ],
            ClientTier::Standard => [
                'email' => $this->settings->string(SettingKey::SupportStandardEmail),
                'phone' => $this->settings->string(SettingKey::SupportStandardPhone),
                'hours' => $this->settings->string(SettingKey::SupportStandardHours),
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function packages(ClientTier $tier): array
    {
        $key = $tier === ClientTier::Premium ? SettingKey::PackagesPremium : SettingKey::PackagesStandard;

        return array_values(array_filter(array_map(
            fn ($name) => $this->normalize(is_string($name) ? $name : null),
            $this->settings->array($key),
        )));
    }

    private function normalize(?string $package): ?string
    {
        $package = mb_strtolower(trim((string) $package));

        return $package === '' ? null : $package;
    }
}
