<?php

namespace App\Clients;

use App\Audit\Auditor;
use App\Enums\ClientTier;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The rare exception to "the directory is the truth": a time-boxed,
 * reasoned override of a client's explicit package, kept in its own
 * columns so that the sync never touches it and it never touches the
 * synced value. Ends by expiry, by hand, or when the directory catches up.
 */
class PackageOverrides
{
    public function __construct(
        private readonly ClientTiers $tiers,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @throws ValidationException
     */
    public function set(Client $client, string $package, string $reason, Carbon $until, ?User $by = null): void
    {
        $package = trim($package);
        $known = [...$this->tiers->packages(ClientTier::Premium), ...$this->tiers->packages(ClientTier::Standard)];

        if (! in_array(mb_strtolower($package), $known, true)) {
            throw ValidationException::withMessages(['package' => __('Only a package name from the configured lists can be used.')]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('A reason is required.')]);
        }

        if ($until->isPast()) {
            throw ValidationException::withMessages(['until' => __('The end date must be in the future.')]);
        }

        if ($this->rank($this->tiers->tierOfPackage($package)) <= $this->rank($this->tiers->tierFromDirectory($client))) {
            throw ValidationException::withMessages(['package' => __('An override may only raise the level. EMD already gives this client :tier; choose a higher package or none.', ['tier' => $this->tiers->tierFromDirectory($client)?->label() ?? __('no access')])]);
        }

        $client->forceFill([
            'package_override' => $package,
            'package_override_reason' => trim($reason),
            'package_override_by_user_id' => $by?->id,
            'package_override_from' => now(),
            'package_override_until' => $until,
        ])->save();

        $this->auditor->record('client.package_override_set', $client, ['package' => $package, 'reason' => trim($reason), 'until' => $until->toIso8601String(), 'directory_explicit' => $client->explicit_package], $by);
    }

    public function end(Client $client, string $how, ?User $by = null): void
    {
        if ($client->package_override === null) {
            return;
        }

        $previous = $client->package_override;

        $client->forceFill([
            'package_override' => null,
            'package_override_reason' => null,
            'package_override_by_user_id' => null,
            'package_override_from' => null,
            'package_override_until' => null,
        ])->save();

        $this->auditor->record('client.package_override_ended', $client, ['package' => $previous, 'how' => $how], $by);
    }

    /**
     * The directory now carries the overridden value: the override is moot.
     */
    public function endIfDirectoryCaughtUp(Client $client): void
    {
        if ($client->hasActivePackageOverride() && $this->same($client->explicit_package, $client->package_override)) {
            $this->end($client, 'directory_caught_up');
        }
    }

    /**
     * Housekeeping for the scheduler: close expired overrides with a trace.
     */
    public function endExpired(): int
    {
        $count = 0;

        // chunkById pages on the key: the ended rows leave the filter, so an
        // offset-paged walk would skip every row beyond the first page.
        Client::query()->whereNotNull('package_override')->where('package_override_until', '<', now())
            ->chunkById(200, function ($clients) use (&$count): void {
                foreach ($clients as $client) {
                    $this->end($client, 'expired');
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Premium outranks standard outranks no access.
     */
    private function rank(?ClientTier $tier): int
    {
        return match ($tier) {
            ClientTier::Premium => 2,
            ClientTier::Standard => 1,
            default => 0,
        };
    }

    private function same(?string $a, ?string $b): bool
    {
        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }
}
