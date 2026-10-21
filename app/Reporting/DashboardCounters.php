<?php

namespace App\Reporting;

use App\Auth\Permission;
use App\Clients\ClientTiers;
use App\Enums\ClientTier;
use App\Enums\IdSessionStatus;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * The numbers on the dashboard's top tiles, computed once per poll interval
 * for everyone with the same view (visible levels and permissions) instead of
 * once per agent per poll: thirty agents polling every five seconds used to
 * run the seven-day grouping and the client count six times a second.
 *
 * @phpstan-type Counters array{ongoing: int, missed: int, identifications: ?array{total: int, passed: int, failed: int, trend: list<int>}, clients: ?array{total: int, with_pin: int}}
 */
class DashboardCounters
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ClientTiers $tiers,
    ) {}

    /**
     * @return Counters
     */
    public function forUser(User $user): array
    {
        $tiers = $user->visibleTiers();
        $seesIdentifications = $user->can(Permission::IdentificationView->value);
        $seesClients = $user->can(Permission::ClientsView->value);

        $key = 'hdid.dashboard.counters.'.today()->toDateString().'.'
            .implode(',', array_map(fn (ClientTier $t) => $t->value, $tiers))
            .'.'.($seesIdentifications ? 'i' : '-').($seesClients ? 'c' : '-');

        return Cache::remember($key, $this->ttlSeconds(), fn (): array => $this->compute($tiers, $seesIdentifications, $seesClients));
    }

    /**
     * A tile may be one poll interval behind a change; the status tile text
     * and the manual say so.
     */
    public function ttlSeconds(): int
    {
        return max(2, $this->settings->int(SettingKey::CallsDashboardPollSeconds));
    }

    /**
     * @param  list<ClientTier>  $tiers
     * @return Counters
     */
    private function compute(array $tiers, bool $seesIdentifications, bool $seesClients): array
    {
        $tierValues = array_map(fn (ClientTier $t) => $t->value, $tiers);

        $counters = [
            'ongoing' => Call::query()->ongoing()->whereIn('tier', $tierValues)->count(),
            'missed' => Call::query()->missed()->whereIn('tier', $tierValues)->count(),
            'identifications' => null,
            'clients' => null,
        ];

        if ($seesIdentifications) {
            // One grouped query for the last seven days instead of a count per day and status.
            $byDay = IdSession::query()
                ->where('started_at', '>=', today()->subDays(6)->startOfDay())
                ->selectRaw('DATE(started_at) as day, status, COUNT(*) as n')
                ->groupBy('day', 'status')
                ->get()
                ->groupBy('day');

            $todayRows = $byDay->get(today()->toDateString(), collect());

            $counters['identifications'] = [
                'total' => (int) $todayRows->sum('n'),
                'passed' => (int) $todayRows->where('status', IdSessionStatus::Passed)->sum('n'),
                'failed' => (int) $todayRows->where('status', IdSessionStatus::Failed)->sum('n'),
                'trend' => collect(range(6, 0))->map(fn (int $daysAgo) => (int) $byDay
                    ->get(today()->subDays($daysAgo)->toDateString(), collect())
                    ->where('status', IdSessionStatus::Passed)
                    ->sum('n'))->all(),
            ];
        }

        if ($seesClients) {
            $clients = Client::query()->open()->tap(fn ($query) => $this->tiers->scopeServed($query))
                ->selectRaw('COUNT(*) as total, SUM(CASE WHEN pin_hash IS NOT NULL THEN 1 ELSE 0 END) as with_pin')
                ->first();

            $counters['clients'] = ['total' => (int) ($clients->total ?? 0), 'with_pin' => (int) ($clients->with_pin ?? 0)];
        }

        return $counters;
    }
}
