<?php

namespace App\Reporting;

use App\Enums\ClientTier;
use App\Models\Call;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Calls handled by agents (the agent who took the call), grouped by agent,
 * call queue and day of arrival. Ended calls are soft-deleted after the
 * short call retention, so the statistics read the trashed rows too; only
 * the long retention removes them for good.
 */
class AgentCallStats
{
    public const DASHBOARD_DAYS = 14;

    public const DASHBOARD_PERIODS = [1, 7, 14, 28, 30];

    public const CACHE_SECONDS = 60;

    /**
     * The dashboard view: selected period and the tiers the user is looking at,
     * shared by everyone with the same view for a minute.
     */
    public function dashboard(User $user, mixed $days = self::DASHBOARD_DAYS): AgentCallSummary
    {
        $days = self::dashboardDays($days);
        $tiers = $user->visibleTiers();
        $key = 'hdid.dashboard.agent-calls.'.today()->toDateString().'.'.$days.'.'.implode(',', array_map(fn (ClientTier $t) => $t->value, $tiers));

        // Stored as an array: the cache stores only unserialize allow-listed classes.
        return AgentCallSummary::fromArray(Cache::remember($key, self::CACHE_SECONDS, fn (): array => $this->summarize(
            today()->subDays($days - 1),
            today(),
            $tiers,
        )->toArray()));
    }

    /** Raw dashboard filters are not validated by Filament. */
    public static function dashboardDays(mixed $days): int
    {
        return (is_int($days) || is_string($days)) && in_array((string) $days, array_map('strval', self::DASHBOARD_PERIODS), true)
            ? (int) $days
            : self::DASHBOARD_DAYS;
    }

    /**
     * @param  list<ClientTier>|null  $tiers  null: every tier
     */
    public function summarize(CarbonInterface $from, CarbonInterface $until, ?array $tiers = null): AgentCallSummary
    {
        $from = CarbonImmutable::instance($from)->startOfDay();
        $until = CarbonImmutable::instance($until)->endOfDay();

        $rows = Call::withTrashed()
            ->whereNotNull('agent_user_id')
            ->whereBetween('arrived_at', [$from, $until])
            ->when($tiers !== null, fn ($query) => $query->whereIn('tier', array_map(fn (ClientTier $t) => $t->value, $tiers)))
            ->selectRaw("agent_user_id, COALESCE(queue, '') AS queue, DATE(arrived_at) AS day, COUNT(*) AS n")
            ->groupBy('agent_user_id', 'queue', 'day')
            ->get();

        $names = User::withTrashed()->whereIn('id', $rows->pluck('agent_user_id')->unique())->pluck('name', 'id');

        $agents = [];
        $queueTotals = [];
        $dayTotals = [];

        foreach ($rows as $row) {
            $id = (string) $row->agent_user_id;
            $queue = (string) $row->queue;
            $n = (int) $row->n;

            $agents[$id] ??= ['id' => $id, 'name' => (string) ($names[$id] ?? '?'), 'total' => 0, 'by_queue' => [], 'by_day' => []];
            $agents[$id]['total'] += $n;
            $agents[$id]['by_queue'][$queue] = ($agents[$id]['by_queue'][$queue] ?? 0) + $n;
            $agents[$id]['by_day'][$row->day][$queue] = ($agents[$id]['by_day'][$row->day][$queue] ?? 0) + $n;
            $queueTotals[$queue] = ($queueTotals[$queue] ?? 0) + $n;
            $dayTotals[$row->day] = ($dayTotals[$row->day] ?? 0) + $n;
        }

        // Busiest first; ties in name order so the lists are stable.
        uksort($queueTotals, fn (string $a, string $b) => [$queueTotals[$b], $a] <=> [$queueTotals[$a], $b]);
        usort($agents, fn (array $a, array $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);
        $queues = array_map('strval', array_keys($queueTotals));
        foreach ($agents as &$agent) {
            $agent['by_queue'] = array_filter(array_combine($queues, array_map(fn (string $q) => $agent['by_queue'][$q] ?? 0, $queues)));
        }
        unset($agent);

        $days = [];
        for ($day = $from; $day->lte($until); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return new AgentCallSummary(
            days: $days,
            queues: $queues,
            agents: array_values($agents),
            dayTotals: $dayTotals,
            queueTotals: $queueTotals,
            total: array_sum($queueTotals),
        );
    }

    /**
     * Label of a queue for the screens and reports.
     */
    public static function queueLabel(string $queue): string
    {
        return $queue === '' ? __('No queue') : $queue;
    }

    /**
     * One colour per queue for the chart and the table legend, in queue order.
     *
     * @return list<string>
     */
    public static function palette(): array
    {
        return ['#2563eb', '#f59e0b', '#10b981', '#8b5cf6', '#ef4444', '#06b6d4', '#84cc16', '#ec4899'];
    }

    public static function colorFor(int $index): string
    {
        $palette = self::palette();

        return $palette[$index % count($palette)];
    }
}
