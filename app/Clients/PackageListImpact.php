<?php

namespace App\Clients;

use App\Enums\ClientTier;
use App\Models\Client;
use App\Models\ClientLink;
use Illuminate\Database\Eloquent\Collection;

/**
 * What saving new package lists would do to the open clients, computed
 * before the save so the administrator confirms the consequences instead
 * of discovering them: who would lose portal access (a hard error, because
 * entitlement follows the lists at once), which sponsors stop sponsoring
 * and how many links end, whose tier moves between premium and standard.
 */
class PackageListImpact
{
    public const SAMPLE_SIZE = 10;

    public function __construct(private readonly ClientTiers $tiers) {}

    /**
     * @param  list<mixed>  $premium  the proposed premium package names
     * @param  list<mixed>  $standard  the proposed standard package names
     * @return array{
     *     changed: bool,
     *     duplicates: list<string>,
     *     losing_access: array{count: int, sample: list<string>},
     *     sponsors: array{count: int, links: int, sample: list<string>},
     *     downgraded: array{count: int, sample: list<string>},
     *     upgraded: array{count: int, sample: list<string>},
     * }
     */
    public function estimate(array $premium, array $standard): array
    {
        $newPremium = self::normalizeList($premium);
        $newStandard = self::normalizeList($standard);
        $oldPremium = $this->tiers->packages(ClientTier::Premium);
        $oldStandard = $this->tiers->packages(ClientTier::Standard);

        $impact = [
            'changed' => $newPremium !== $oldPremium || $newStandard !== $oldStandard,
            'duplicates' => array_values(array_intersect($newPremium, $newStandard)),
            'losing_access' => ['count' => 0, 'sample' => []],
            'sponsors' => ['count' => 0, 'links' => 0, 'sample' => []],
            'downgraded' => ['count' => 0, 'sample' => []],
            'upgraded' => ['count' => 0, 'sample' => []],
        ];

        if (! $impact['changed']) {
            return $impact;
        }

        $linkCounts = ClientLink::query()->whereNull('ended_at')
            ->selectRaw('sponsor_client_id, count(*) as links')->groupBy('sponsor_client_id')->pluck('links', 'sponsor_client_id');

        Client::query()->open()
            ->select(['id', 'name', 'implicit_package', 'explicit_package', 'package_override', 'package_override_until'])
            ->chunkById(500, function (Collection $clients) use (&$impact, $oldPremium, $oldStandard, $newPremium, $newStandard, $linkCounts): void {
                foreach ($clients as $client) {
                    $packages = array_filter([
                        self::normalize($client->implicit_package),
                        self::normalize($client->explicit_package),
                        $client->hasActivePackageOverride() ? self::normalize($client->package_override) : null,
                    ]);
                    $before = self::tierIn($packages, $oldPremium, $oldStandard);
                    $after = self::tierIn($packages, $newPremium, $newStandard);

                    if ($before !== null && $after === null) {
                        self::count($impact['losing_access'], $client->name);
                    } elseif ($before === ClientTier::Premium && $after === ClientTier::Standard) {
                        self::count($impact['downgraded'], $client->name);
                    } elseif ($before === ClientTier::Standard && $after === ClientTier::Premium) {
                        self::count($impact['upgraded'], $client->name);
                    }

                    $implicit = self::normalize($client->implicit_package);
                    $links = (int) ($linkCounts[$client->id] ?? 0);
                    if ($links > 0 && $implicit !== null && in_array($implicit, $oldPremium, true) && ! in_array($implicit, $newPremium, true)) {
                        self::count($impact['sponsors'], $client->name);
                        $impact['sponsors']['links'] += $links;
                    }
                }
            });

        return $impact;
    }

    /**
     * Saving is refused outright for these: a name on both lists, and a
     * change that would lock open clients out of the portal (the lists
     * decide entitlement on every request). A package leaves the premium
     * list through the standard list, or after its wearers were closed.
     *
     * @param  array<string, mixed>  $impact
     * @return list<string>
     */
    public function errors(array $impact): array
    {
        $errors = [];

        if ($impact['duplicates'] !== []) {
            $errors[] = __('The same package name is on both lists: :names. A package belongs to exactly one list.', ['names' => implode(', ', $impact['duplicates'])]);
        }

        if ($impact['losing_access']['count'] > 0) {
            $errors[] = __(':n open client(s) would lose portal access because their package would be on neither list (:names). Move the package to the standard list, or close those accounts first; a package can only be removed once no open client wears it.', [
                'n' => $impact['losing_access']['count'],
                'names' => self::names($impact['losing_access']),
            ]);
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $impact
     */
    public function needsConfirmation(array $impact): bool
    {
        return $impact['sponsors']['count'] > 0 || $impact['downgraded']['count'] > 0 || $impact['upgraded']['count'] > 0;
    }

    /**
     * The consequences spelled out for the confirmation modal.
     *
     * @param  array<string, mixed>  $impact
     */
    public function summary(array $impact): string
    {
        $parts = [];

        if ($impact['sponsors']['count'] > 0) {
            $parts[] = __(':n sponsor(s) stop sponsoring because their implicit package leaves the premium list; their :links active link(s) end on save, which indirectly affects that many linked clients (:names). The linked clients are not closed; they keep whatever their own packages entitle them to.', [
                'n' => $impact['sponsors']['count'], 'links' => $impact['sponsors']['links'], 'names' => self::names($impact['sponsors']),
            ]);
        }

        if ($impact['downgraded']['count'] > 0) {
            $parts[] = __(':n client(s) move from the premium to the standard tier (:names).', ['n' => $impact['downgraded']['count'], 'names' => self::names($impact['downgraded'])]);
        }

        if ($impact['upgraded']['count'] > 0) {
            $parts[] = __(':n client(s) move from the standard to the premium tier (:names).', ['n' => $impact['upgraded']['count'], 'names' => self::names($impact['upgraded'])]);
        }

        return implode(' ', $parts);
    }

    /**
     * @param  list<string>  $premium
     * @param  list<string>  $standard
     * @param  array<int, string>  $packages
     */
    private static function tierIn(array $packages, array $premium, array $standard): ?ClientTier
    {
        if ($packages === []) {
            return null;
        }

        if (array_intersect($packages, $premium) !== []) {
            return ClientTier::Premium;
        }

        return array_intersect($packages, $standard) !== [] ? ClientTier::Standard : null;
    }

    /**
     * @param  array{count: int, sample: list<string>}  $bucket
     */
    private static function count(array &$bucket, string $name): void
    {
        $bucket['count']++;

        if (count($bucket['sample']) < self::SAMPLE_SIZE) {
            $bucket['sample'][] = $name;
        }
    }

    /**
     * @param  array{count: int, sample: list<string>}  $bucket
     */
    private static function names(array $bucket): string
    {
        $names = implode(', ', $bucket['sample']);

        return $bucket['count'] > count($bucket['sample']) ? $names.', …' : $names;
    }

    /**
     * @param  list<mixed>  $list
     * @return list<string>
     */
    public static function normalizeList(array $list): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($name) => self::normalize(is_string($name) ? $name : null), $list))));
    }

    private static function normalize(?string $package): ?string
    {
        return PackageName::normalize($package);
    }
}
