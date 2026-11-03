<?php

namespace App\Clients;

use App\Enums\ClientTier;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientLink;
use App\Settings\SettingKey;
use App\Support\HuDate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * What saving new package lists would do to the open clients, computed
 * before the save so the administrator confirms the consequences instead
 * of discovering them: who would lose portal access (a hard error, because
 * entitlement follows the lists at once), which sponsors stop sponsoring
 * and how many links end, whose tier moves between premium and standard.
 * The links of a closed sponsor end as well, and the linked clients closed
 * only with it reopen on save (ClientLinks::sweepIneligibleSponsors), so
 * those are counted and named too, like the linked clients whose own
 * implicit package joins the premium list: their link ends on save
 * (ClientLinks::endLinkOfSelfSponsored). Clients premium-only mode hides at
 * present are counted but not named.
 */
class PackageListImpact
{
    public const SAMPLE_SIZE = 10;

    public function __construct(
        private readonly ClientTiers $tiers,
        private readonly ClientLinks $links,
    ) {}

    /**
     * @param  list<mixed>  $premium  the proposed premium package names
     * @param  list<mixed>  $standard  the proposed standard package names
     * @return array{
     *     changed: bool,
     *     duplicates: list<string>,
     *     losing_access: array{count: int, hidden: int, sample: list<string>},
     *     sponsors: array{count: int, hidden: int, links: int, sample: list<string>},
     *     closed_sponsors: array{count: int, hidden: int, links: int, sample: list<string>},
     *     reopened: array{count: int, hidden: int, sample: list<string>},
     *     reopened_without_access: array{count: int, hidden: int, sample: list<string>},
     *     downgraded: array{count: int, hidden: int, sample: list<string>},
     *     upgraded: array{count: int, hidden: int, sample: list<string>},
     *     gaining_access: array{count: int, hidden: int, sample: list<string>, unserved: int},
     *     self_sponsored: array{count: int, hidden: int, sample: list<string>},
     *     leaving: list<string>,
     * }
     */
    public function estimate(array $premium, array $standard, bool $premiumOnly = false): array
    {
        $newPremium = self::normalizeList($premium);
        $newStandard = self::normalizeList($standard);
        // The lists as configured: premium-only mode does not count as a list change.
        $oldPremium = $this->tiers->configuredPackages(ClientTier::Premium);
        $oldStandard = $this->tiers->configuredPackages(ClientTier::Standard);

        $impact = [
            'changed' => $newPremium !== $oldPremium || $newStandard !== $oldStandard,
            'duplicates' => array_values(array_intersect($newPremium, $newStandard)),
            'losing_access' => ['count' => 0, 'hidden' => 0, 'sample' => []],
            'sponsors' => ['count' => 0, 'hidden' => 0, 'links' => 0, 'sample' => []],
            'closed_sponsors' => ['count' => 0, 'hidden' => 0, 'links' => 0, 'sample' => []],
            'reopened' => ['count' => 0, 'hidden' => 0, 'sample' => []],
            'reopened_without_access' => ['count' => 0, 'hidden' => 0, 'sample' => []],
            'downgraded' => ['count' => 0, 'hidden' => 0, 'sample' => []],
            'upgraded' => ['count' => 0, 'hidden' => 0, 'sample' => []],
            // Clients without portal access so far that the new lists let in.
            'gaining_access' => ['count' => 0, 'hidden' => 0, 'sample' => [], 'unserved' => 0],
            // Linked clients whose own implicit package joins the premium list: their link ends.
            'self_sponsored' => ['count' => 0, 'hidden' => 0, 'sample' => []],
            // The package names neither new list carries any more.
            'leaving' => array_values(array_diff([...$oldPremium, ...$oldStandard], [...$newPremium, ...$newStandard])),
            // With only premium clients served, a move to the standard level ends the service.
            'premium_only' => $premiumOnly,
        ];

        if (! $impact['changed']) {
            return $impact;
        }

        $linkCounts = ClientLink::query()->whereNull('ended_at')
            ->selectRaw('sponsor_client_id, count(*) as links')->groupBy('sponsor_client_id')->pluck('links', 'sponsor_client_id');
        $hidingNow = $this->tiers->premiumOnly();

        Client::query()->open()
            ->select(['id', 'name', 'implicit_package', 'explicit_package', 'package_override', 'package_override_until'])
            ->chunkById(500, function (Collection $clients) use (&$impact, $oldPremium, $oldStandard, $newPremium, $newStandard, $linkCounts, $hidingNow): void {
                foreach ($clients as $client) {
                    $packages = self::packagesOf($client);
                    $before = self::tierIn($packages, $oldPremium, $oldStandard);
                    $after = self::tierIn($packages, $newPremium, $newStandard);
                    $hidden = $hidingNow && $before !== ClientTier::Premium;

                    if ($before !== null && $after === null) {
                        self::count($impact['losing_access'], $client->name, $hidden);
                    } elseif ($before === null && $after !== null) {
                        self::count($impact['gaining_access'], $client->name, $hidden);
                        // While only premium clients are served, a standard one gets no service yet.
                        $impact['gaining_access']['unserved'] += $hidingNow && $after !== ClientTier::Premium ? 1 : 0;
                    } elseif ($before === ClientTier::Premium && $after === ClientTier::Standard) {
                        self::count($impact['downgraded'], $client->name, $hidden);
                    } elseif ($before === ClientTier::Standard && $after === ClientTier::Premium) {
                        self::count($impact['upgraded'], $client->name, $hidden);
                    }

                    $implicit = self::normalize($client->implicit_package);
                    $links = (int) ($linkCounts[$client->id] ?? 0);
                    if ($links > 0 && $implicit !== null && in_array($implicit, $oldPremium, true) && ! in_array($implicit, $newPremium, true)) {
                        self::count($impact['sponsors'], $client->name, $hidden);
                        $impact['sponsors']['links'] += $links;
                    }
                }
            });

        $this->countClosedSponsors($impact, $oldPremium, $newPremium, $oldStandard, $newStandard, $linkCounts, $hidingNow);
        $this->countSelfSponsored($impact, $oldPremium, $newPremium, $oldStandard, $hidingNow);

        return $impact;
    }

    /**
     * The linked clients, open or closed, whose own implicit package joins
     * the premium list: they no longer need a sponsor, and their link ends
     * on save; one closed only with its sponsor reopens, unless the
     * directory dropped or reclassified it (keepClosedReason()). A link whose
     * sponsor leaves the premium list ends for that reason and is counted
     * there already.
     *
     * @param  array<string, mixed>  $impact
     * @param  list<string>  $oldPremium
     * @param  list<string>  $newPremium
     * @param  list<string>  $oldStandard
     */
    private function countSelfSponsored(array &$impact, array $oldPremium, array $newPremium, array $oldStandard, bool $hidingNow): void
    {
        $joining = $this->tiers->rawNames(array_values(array_diff($newPremium, $oldPremium)));

        if ($joining === []) {
            return;
        }

        $leaving = $this->tiers->rawNames(array_values(array_diff($oldPremium, $newPremium)));

        Client::query()
            ->whereIn('implicit_package', $joining)
            ->whereHas('activeSponsorLink', fn ($query) => $query->whereHas('sponsor', fn ($query) => $query->withTrashed()->where(fn ($query) => $query->whereNull('implicit_package')->orWhereNotIn('implicit_package', $leaving))))
            ->with('externalRecord')
            ->select(['id', 'name', 'external_record_id', 'implicit_package', 'explicit_package', 'package_override', 'package_override_until', 'closed_at', 'closed_reason'])
            ->chunkById(500, function (Collection $linkedClients) use (&$impact, $oldPremium, $oldStandard, $hidingNow): void {
                foreach ($linkedClients as $linked) {
                    $hidden = $hidingNow && self::tierIn(self::packagesOf($linked), $oldPremium, $oldStandard) !== ClientTier::Premium;
                    self::count($impact['self_sponsored'], $linked->name, $hidden);

                    if ($linked->isClosed() && $linked->closed_reason === ClientLinks::CLOSE_REASON_SPONSOR && $this->links->keepClosedReason($linked) === null) {
                        self::count($impact['reopened'], $linked->name, $hidden);
                    }
                }
            });
    }

    /**
     * The closed sponsors whose implicit package leaves the premium list
     * lose their links on save just like the open ones, and each linked
     * client closed only because of its sponsor reopens then, unless the
     * directory dropped or reclassified it meanwhile (the very rule of
     * ClientLinks::endLinksOfIneligibleSponsor, keepClosedReason()). The
     * open-client scan above does not see either of them.
     *
     * @param  array<string, mixed>  $impact
     * @param  list<string>  $oldPremium
     * @param  list<string>  $newPremium
     * @param  list<string>  $oldStandard
     * @param  list<string>  $newStandard
     * @param  SupportCollection<array-key, mixed>  $linkCounts  active links per sponsor id
     */
    private function countClosedSponsors(array &$impact, array $oldPremium, array $newPremium, array $oldStandard, array $newStandard, SupportCollection $linkCounts, bool $hidingNow): void
    {
        $leaving = $this->tiers->rawNames(array_values(array_diff($oldPremium, $newPremium)));

        if ($leaving === []) {
            return;
        }

        $hidden = fn (Client $client): bool => $hidingNow && self::tierIn(self::packagesOf($client), $oldPremium, $oldStandard) !== ClientTier::Premium;
        $activeLinks = fn ($query) => $query->whereNull('ended_at');

        Client::query()->closed()
            ->whereIn('implicit_package', $leaving)
            ->whereHas('sponsoredLinks', $activeLinks)
            ->select(['id', 'name', 'implicit_package', 'explicit_package', 'package_override', 'package_override_until'])
            ->chunkById(500, function (Collection $sponsors) use (&$impact, $linkCounts, $hidden): void {
                foreach ($sponsors as $sponsor) {
                    self::count($impact['closed_sponsors'], $sponsor->name, $hidden($sponsor));
                    $impact['closed_sponsors']['links'] += (int) ($linkCounts[$sponsor->id] ?? 0);
                }
            });

        Client::query()->closed()
            ->where('closed_reason', ClientLinks::CLOSE_REASON_SPONSOR)
            ->whereHas('sponsorLinks', fn ($query) => $activeLinks($query)->whereHas('sponsor', fn ($query) => $query->whereIn('implicit_package', $leaving)))
            ->with('externalRecord')
            ->select(['id', 'name', 'external_record_id', 'implicit_package', 'explicit_package', 'package_override', 'package_override_until', 'closed_reason'])
            ->chunkById(500, function (Collection $dependents) use (&$impact, $hidden, $newPremium, $newStandard): void {
                foreach ($dependents as $dependent) {
                    if ($this->links->keepClosedReason($dependent) !== null) {
                        continue;
                    }

                    // Reopened, it is entitled by its own packages under the new lists, or not at all:
                    // an open account without portal access is what the losing-access rule refuses.
                    $bucket = self::tierIn(self::packagesOf($dependent), $newPremium, $newStandard) === null ? 'reopened_without_access' : 'reopened';
                    self::count($impact[$bucket], $dependent->name, $hidden($dependent));
                }
            });
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

        if (($impact['reopened_without_access']['count'] ?? 0) > 0) {
            $errors[] = __('Saving would reopen :n closed linked client(s), closed only because their sponsor was closed, without portal access: their own packages would be on neither list (:names). Put their package on the standard list first.', [
                'n' => $impact['reopened_without_access']['count'],
                'names' => self::names($impact['reopened_without_access']),
            ]);
        }

        if ($impact['losing_access']['count'] > 0) {
            $errors[] = trim(__(':n open client(s) would lose portal access because their package would be on neither list (:names). Move the package to the standard list, or close those accounts first; a package can only be removed once no open client wears it.', [
                'n' => $impact['losing_access']['count'],
                'names' => self::names($impact['losing_access']),
            ]).' '.$this->addedBy($impact['leaving'] ?? []));
        }

        return $errors;
    }

    /**
     * Which save put the leaving packages on the lists: the wearers of a
     * package a recent save added got their access from that save, and the
     * error names it (the hard rule stays, owner decision Q4).
     *
     * @param  list<string>  $leaving
     */
    private function addedBy(array $leaving): string
    {
        $sentences = [];

        foreach ($leaving as $package) {
            $added = AuditLog::query()->where('event', 'setting.changed')
                ->whereIn('context->key', [SettingKey::PackagesPremium->value, SettingKey::PackagesStandard->value])
                ->latest('id')->limit(50)->get()
                ->first(function (AuditLog $entry) use ($package): bool {
                    $was = array_map(fn (mixed $name): ?string => self::normalize(is_string($name) ? $name : null), (array) ($entry->context['from'] ?? []));
                    $is = array_map(fn (mixed $name): ?string => self::normalize(is_string($name) ? $name : null), (array) ($entry->context['to'] ?? []));

                    return in_array($package, $is, true) && ! in_array($package, $was, true);
                });

            if ($added !== null) {
                // The name as the save wrote it.
                $raw = collect((array) ($added->context['to'] ?? []))->first(fn (mixed $name): bool => is_string($name) && self::normalize($name) === $package);
                $sentences[] = __('The package :package was added to the lists by the save of :time (:by).', [
                    'package' => is_string($raw) ? $raw : $package,
                    'time' => $added->created_at->format(HuDate::DATETIME),
                    'by' => $added->actor?->name ?? '?',
                ]);
            }
        }

        return implode(' ', $sentences);
    }

    /**
     * @param  array<string, mixed>  $impact
     */
    public function needsConfirmation(array $impact): bool
    {
        return $impact['sponsors']['count'] > 0
            || ($impact['gaining_access']['count'] ?? 0) > 0
            || ($impact['self_sponsored']['count'] ?? 0) > 0
            || ($impact['closed_sponsors']['count'] ?? 0) > 0
            || ($impact['reopened']['count'] ?? 0) > 0
            || $impact['downgraded']['count'] > 0
            || $impact['upgraded']['count'] > 0;
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

        if (($impact['closed_sponsors']['count'] ?? 0) > 0) {
            $parts[] = __(':n closed sponsor(s) stop sponsoring as well, because their implicit package leaves the premium list; their :links active link(s) end on save (:names).', [
                'n' => $impact['closed_sponsors']['count'], 'links' => $impact['closed_sponsors']['links'], 'names' => self::names($impact['closed_sponsors']),
            ]);
        }

        if (($impact['self_sponsored']['count'] ?? 0) > 0) {
            $parts[] = __(':n linked client(s) get an implicit premium package of their own with this save, so they no longer need a sponsor: their link ends on save (:names), and closing their sponsor no longer closes them.', [
                'n' => $impact['self_sponsored']['count'], 'names' => self::names($impact['self_sponsored']),
            ]);
        }

        if (($impact['reopened']['count'] ?? 0) > 0) {
            $parts[] = __(':n linked client(s), closed only because their sponsor was closed, are reopened on save, because their link ends (:names). They can sign in to the portal and be identified again with whatever their own packages entitle them to.', [
                'n' => $impact['reopened']['count'], 'names' => self::names($impact['reopened']),
            ]);
        }

        $premiumOnly = $impact['premium_only'] ?? false;

        if (($impact['gaining_access']['count'] ?? 0) > 0) {
            $parts[] = __(':n client(s) without portal access so far get it with this save (:names): they can sign in, set a PIN and be identified.', [
                'n' => $impact['gaining_access']['count'], 'names' => self::names($impact['gaining_access']),
            ]);

            if (($impact['gaining_access']['unserved'] ?? 0) > 0) {
                $parts[] = __(':n of them are on the standard level and, as only premium clients are served, are not served yet.', ['n' => $impact['gaining_access']['unserved']]);
            }
        }

        if ($impact['downgraded']['count'] > 0) {
            $parts[] = $premiumOnly
                ? __(':n client(s) move from the premium to the standard tier and, as only premium clients are served, are no longer served (:names).', ['n' => $impact['downgraded']['count'], 'names' => self::names($impact['downgraded'])])
                : __(':n client(s) move from the premium to the standard tier (:names).', ['n' => $impact['downgraded']['count'], 'names' => self::names($impact['downgraded'])]);
        }

        if ($impact['upgraded']['count'] > 0) {
            $parts[] = $premiumOnly
                ? __(':n client(s) move from the standard to the premium tier and are served from now on (:names).', ['n' => $impact['upgraded']['count'], 'names' => self::names($impact['upgraded'])])
                : __(':n client(s) move from the standard to the premium tier (:names).', ['n' => $impact['upgraded']['count'], 'names' => self::names($impact['upgraded'])]);
        }

        return implode(' ', $parts);
    }

    /**
     * Who stops being served when premium-only mode goes on: the open clients
     * these lists put on the standard level. Nothing is closed or deleted;
     * they lose portal access and drop out of the staff views until the mode
     * goes off again.
     *
     * @param  list<mixed>  $premium
     * @param  list<mixed>  $standard
     * @return array{count: int, hidden: int, sample: list<string>}
     */
    public function premiumOnlyImpact(array $premium, array $standard): array
    {
        $premium = self::normalizeList($premium);
        $standard = self::normalizeList($standard);
        $impact = ['count' => 0, 'hidden' => 0, 'sample' => []];

        Client::query()->open()
            ->select(['id', 'name', 'implicit_package', 'explicit_package', 'package_override', 'package_override_until'])
            ->chunkById(500, function (Collection $clients) use (&$impact, $premium, $standard): void {
                foreach ($clients as $client) {
                    if (self::tierIn(self::packagesOf($client), $premium, $standard) === ClientTier::Standard) {
                        self::count($impact, $client->name, ! $this->tiers->isServed($client));
                    }
                }
            });

        return $impact;
    }

    /**
     * The consequences of switching premium-only mode on, for the confirmation.
     *
     * @param  array{count: int, hidden: int, sample: list<string>}  $impact
     */
    public function premiumOnlySummary(array $impact): string
    {
        $text = __('From now on only premium clients are served. Standard clients cannot sign in to the portal (those signed in are signed out at their next step), the phone menu does not recognise them, and they drop out of every staff view. Nothing is deleted or closed: switching premium-only off brings everything back.');

        return $impact['count'] > 0
            ? $text.' '.__(':n open client(s) are affected (:names).', ['n' => $impact['count'], 'names' => self::names($impact)])
            : $text.' '.__('No open client is on the standard level now.');
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
     * @return array<int, string>
     */
    private static function packagesOf(Client $client): array
    {
        return array_filter([
            self::normalize($client->implicit_package),
            self::normalize($client->explicit_package),
            $client->hasActivePackageOverride() ? self::normalize($client->package_override) : null,
        ]);
    }

    /**
     * A client premium-only mode hides at present is counted, not named.
     *
     * @param  array{count: int, hidden: int, sample: list<string>}  $bucket
     */
    private static function count(array &$bucket, string $name, bool $hidden): void
    {
        $bucket['count']++;

        if ($hidden) {
            $bucket['hidden']++;
        } elseif (count($bucket['sample']) < self::SAMPLE_SIZE) {
            $bucket['sample'][] = $name;
        }
    }

    /**
     * @param  array{count: int, hidden: int, sample: list<string>}  $bucket
     */
    private static function names(array $bucket): string
    {
        $names = implode(', ', $bucket['sample']);

        if (count($bucket['sample']) < $bucket['count'] - $bucket['hidden']) {
            $names .= ', …';
        }

        if ($bucket['hidden'] > 0) {
            $hidden = __(':n client(s) not served at present', ['n' => $bucket['hidden']]);
            $names = $names === '' ? $hidden : $names.'; '.$hidden;
        }

        return $names;
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
