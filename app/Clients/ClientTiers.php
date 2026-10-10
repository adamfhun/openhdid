<?php

namespace App\Clients;

use App\Enums\ClientTier;
use App\Models\Client;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Decides whether a client may use the portal and at which service level.
 * A client qualifies when its implicit or explicit package is listed in the
 * premium or the standard package list (case-insensitive, trimmed). In
 * premium-only mode the standard list grants nothing: every rule that asks
 * for a client's level (portal, sign-in paths, phone menu, call views) then
 * serves premium clients only, and the staff views hide everybody else
 * through scopeServed() and isServed().
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

        // Premium-only has no standard level to fall back to: a caller nobody
        // recognised on an unmapped queue may well be a premium client calling
        // from another number, so the call stays in view. A queue mapped to
        // standard keeps its level (and stays out of view).
        if ($this->premiumOnly()) {
            return ClientTier::Premium;
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

        $raw = $this->rawNames($names);

        if ($raw === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(fn (Builder $q) => $q
            ->whereIn('implicit_package', $raw)
            ->orWhereIn('explicit_package', $raw)
            ->orWhere(fn (Builder $o) => $o->whereIn('package_override', $raw)->where('package_override_until', '>=', now())));
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
        if ($this->premiumOnly()) {
            return [ClientTier::Premium];
        }

        $active = array_values(array_filter(ClientTier::cases(), fn (ClientTier $tier) => $this->packages($tier) !== []));

        return $active === [] ? ClientTier::cases() : $active;
    }

    /**
     * The levels as configured, whatever the mode: reports cover the calls
     * as they were recorded, standard ones included.
     *
     * @return list<ClientTier>
     */
    public function configuredTiers(): array
    {
        $configured = array_values(array_filter(ClientTier::cases(), fn (ClientTier $tier) => $this->configuredPackages($tier) !== []));

        return $configured === [] ? ClientTier::cases() : $configured;
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
     * The package names that grant this level now: in premium-only mode the
     * standard list grants nothing.
     *
     * @return list<string>
     */
    public function packages(ClientTier $tier): array
    {
        return $tier === ClientTier::Standard && $this->premiumOnly() ? [] : $this->configuredPackages($tier);
    }

    /**
     * The package names on the list as configured, whatever the mode; the
     * package list checks of the settings page compare these.
     *
     * @return list<string>
     */
    public function configuredPackages(ClientTier $tier): array
    {
        $key = $tier === ClientTier::Premium ? SettingKey::PackagesPremium : SettingKey::PackagesStandard;

        return array_values(array_filter(array_map(
            fn ($name) => $this->normalize(is_string($name) ? $name : null),
            $this->settings->array($key),
        )));
    }

    public function premiumOnly(): bool
    {
        return $this->settings->bool(SettingKey::PackagesPremiumOnly);
    }

    /**
     * Whether the system serves (shows, recognises) this client: everybody
     * normally, only premium clients in premium-only mode.
     */
    public function isServed(?Client $client): bool
    {
        return $client !== null && (! $this->premiumOnly() || $this->tierFor($client) === ClientTier::Premium);
    }

    /**
     * The client's name where a staff view may show it, null otherwise (an
     * earlier call or session of a client no longer served shows as unknown).
     */
    public function servedName(?Client $client): ?string
    {
        return $this->isServed($client) ? $client->name : null;
    }

    /**
     * Free text written about a client (a call note, the reason of a manual
     * identification, a message's subject or error) where a staff view may
     * show it; for a client no longer served, a marker instead.
     */
    public function servedText(?Client $client, ?string $text): ?string
    {
        return $text === null || $client === null || $this->isServed($client) ? $text : __('hidden (client not served at present)');
    }

    /**
     * Restrict a client query (or a join on the clients table) to the clients
     * the system serves; outside premium-only mode it changes nothing.
     *
     * @param  Builder<Client>|QueryBuilder  $query
     */
    public function scopeServed(Builder|QueryBuilder $query, string $table = 'clients'): void
    {
        if (! $this->premiumOnly()) {
            return;
        }

        $raw = $this->rawNames($this->packages(ClientTier::Premium));

        if ($raw === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(fn ($q) => $this->wherePackagesIn($q, $raw, $table));
    }

    /**
     * Open clients the standard list would serve but premium-only mode holds
     * back (zero outside the mode): for the status page.
     */
    public function heldBackCount(): int
    {
        if (! $this->premiumOnly()) {
            return 0;
        }

        $standard = $this->rawNames($this->configuredPackages(ClientTier::Standard));

        if ($standard === []) {
            return 0;
        }

        // Counted as a difference: NOT over the nullable package columns would
        // drop the rows SQL rates as unknown.
        $onStandard = fn (): Builder => Client::query()->open()->where(fn (Builder $q) => $this->wherePackagesIn($q, $standard));
        $premium = $this->rawNames($this->packages(ClientTier::Premium));

        return $onStandard()->count() - ($premium === [] ? 0 : $onStandard()->where(fn (Builder $q) => $this->wherePackagesIn($q, $premium))->count());
    }

    /**
     * Whether premium-only mode is why this client is not served: the
     * configured lists put it on the standard level.
     */
    public function heldBackByPremiumOnly(Client $client): bool
    {
        if (! $this->premiumOnly() || $this->tierFor($client) !== null) {
            return false;
        }

        $packages = array_filter([
            $this->normalize($client->implicit_package),
            $this->normalize($client->explicit_package),
            $client->hasActivePackageOverride() ? $this->normalize($client->package_override) : null,
        ]);

        return array_intersect($packages, $this->configuredPackages(ClientTier::Standard)) !== [];
    }

    /**
     * Implicit, explicit or a live override package among the raw names.
     *
     * @param  Builder<Client>|QueryBuilder  $query
     * @param  list<string>  $raw
     */
    private function wherePackagesIn(Builder|QueryBuilder $query, array $raw, string $table = 'clients'): void
    {
        $query->whereIn($table.'.implicit_package', $raw)
            ->orWhereIn($table.'.explicit_package', $raw)
            ->orWhere(fn ($o) => $o->whereIn($table.'.package_override', $raw)
                ->where(fn ($u) => $u->whereNull($table.'.package_override_until')->orWhere($table.'.package_override_until', '>=', now())));
    }

    private function normalize(?string $package): ?string
    {
        return PackageName::normalize($package);
    }

    /**
     * The package names as they stand in the clients table whose normalized
     * form is among the given names. SQL compares raw strings, so the case-,
     * accent- and space-insensitive matching happens here and the queries get
     * exact values.
     *
     * @param  list<string>  $normalized
     * @return list<string>
     */
    public function rawNames(array $normalized): array
    {
        if ($normalized === []) {
            return [];
        }

        $raw = collect();

        foreach (['implicit_package', 'explicit_package', 'package_override'] as $column) {
            $raw = $raw->merge(Client::query()->withTrashed()->whereNotNull($column)->distinct()->pluck($column));
        }

        return $raw
            ->filter(fn ($name): bool => in_array(PackageName::normalize(is_string($name) ? $name : null), $normalized, true))
            ->unique()
            ->values()
            ->all();
    }
}
