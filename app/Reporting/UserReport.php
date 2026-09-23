<?php

namespace App\Reporting;

use App\Clients\ClientTiers;
use App\Enums\CallStatus;
use App\Enums\ClientTier;
use App\Enums\IdMethod;
use App\Enums\IdSessionStatus;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\IdSession;
use App\Models\User;
use App\Support\HuDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Builds the staff report: every table is computed from grouped queries
 * over the period, so the size of the period only changes the number of
 * day columns, not the number of queries. Ended calls and finished
 * identification sessions are soft-deleted by the short retention, so the
 * queries read the trashed rows too.
 */
class UserReport
{
    /**
     * The widest period one report may cover. The limit lives here, not in
     * the page: the rule is the service's, the page only renders the error.
     */
    public const MAX_DAYS = 400;

    public function __construct(
        private readonly AgentCallStats $callStats,
        private readonly ClientTiers $tiers,
    ) {}

    /**
     * @param  list<ReportSection>  $sections
     */
    public function build(User $actor, CarbonInterface $from, CarbonInterface $until, array $sections): UserReportResult
    {
        $from = CarbonImmutable::instance($from)->startOfDay();
        $until = CarbonImmutable::instance($until)->endOfDay();

        // Whole calendar days: the period ends at the end of its last day.
        if ((int) $from->diffInDays($until->startOfDay()) + 1 > self::MAX_DAYS) {
            throw new ReportPeriodTooLongException(__('The period may span at most :days days.', ['days' => self::MAX_DAYS]));
        }

        $tables = [];
        foreach (ReportSection::cases() as $section) {
            if (! in_array($section, $sections, true)) {
                continue;
            }

            array_push($tables, ...match ($section) {
                ReportSection::Users => [$this->users()],
                ReportSection::Calls => $this->calls($from, $until),
                ReportSection::CallsByDay => [$this->callsByDay($from, $until)],
                ReportSection::Identifications => [$this->identifications($from, $until)],
                ReportSection::Activity => [$this->activity($from, $until)],
            });
        }

        return new UserReportResult($from, $until, $tables, $actor, CarbonImmutable::now());
    }

    private function users(): ReportTable
    {
        $rows = User::query()->with(['roles', 'externalRecord'])->orderBy('name')->get()
            ->map(fn (User $user): array => [
                $user->name,
                $user->email,
                $user->roles->pluck('name')->sort()->implode(', '),
                $user->isClosed() ? __('Closed').($user->closed_reason ? ' ('.__($user->closed_reason).')' : '') : __('Active'),
                $user->last_login_at?->format(HuDate::DATETIME),
                $user->externalRecord?->jobTitle(),
                $user->externalRecord?->departmentName(),
                implode(', ', array_map(fn (ClientTier $t) => $t->label(), $user->handledTiers())),
            ])
            ->all();

        return new ReportTable(
            ReportSection::Users,
            ReportSection::Users->label(),
            [__('Name'), __('E-mail'), __('Roles'), __('Status'), __('Last login'), __('Title'), __('Department'), __('Handles calls of')],
            $rows,
        );
    }

    /**
     * @return list<ReportTable>
     */
    private function calls(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $summary = $this->callStats->summarize($from, $until);
        $tiers = $this->tiers->activeTiers();

        $byTier = Call::withTrashed()
            ->whereNotNull('agent_user_id')
            ->whereBetween('arrived_at', [$from, $until])
            ->selectRaw('agent_user_id, tier, COUNT(*) AS n')
            ->groupBy('agent_user_id', 'tier')
            ->get()
            ->groupBy('agent_user_id');

        // Duration is summed in PHP: SQLite and MariaDB have no common date arithmetic.
        $seconds = [];
        $counts = [];
        Call::withTrashed()
            ->whereNotNull('agent_user_id')
            ->whereNotNull('answered_at')
            ->whereNotNull('ended_at')
            ->whereBetween('arrived_at', [$from, $until])
            ->select(['id', 'agent_user_id', 'answered_at', 'ended_at'])
            ->lazy(2000)
            ->each(function (Call $call) use (&$seconds, &$counts): void {
                $seconds[$call->agent_user_id] = ($seconds[$call->agent_user_id] ?? 0) + max(0, (int) $call->answered_at->diffInSeconds($call->ended_at));
                $counts[$call->agent_user_id] = ($counts[$call->agent_user_id] ?? 0) + 1;
            });
        $durations = collect($seconds)->map(fn (int $sum, string $id) => (int) round($sum / $counts[$id]));

        // Anchored on the call's own day, like every other number in the
        // report: on handled_at this column and the daily table disagree
        // whenever a call is marked handled after the period ends, and a
        // closed period would keep changing afterwards.
        $handledMissed = Call::withTrashed()
            ->whereNull('agent_user_id')
            ->whereIn('status', [CallStatus::Missed, CallStatus::Ended])
            ->whereNotNull('handled_by_user_id')
            ->whereBetween('arrived_at', [$from, $until])
            ->selectRaw('handled_by_user_id, COUNT(*) AS n')
            ->groupBy('handled_by_user_id')
            ->pluck('n', 'handled_by_user_id');

        // Someone who only marked missed calls as handled still belongs in the table.
        $agents = $summary->agents;
        $known = array_column($agents, 'id');
        $extraIds = array_values(array_diff(array_map('strval', $handledMissed->keys()->all()), $known));
        if ($extraIds !== []) {
            foreach (User::withTrashed()->whereIn('id', $extraIds)->orderBy('name')->get() as $user) {
                $agents[] = ['id' => (string) $user->id, 'name' => $user->name, 'total' => 0, 'by_queue' => [], 'by_day' => []];
            }
        }

        $headers = array_merge(
            [__('Agent'), __('Calls taken')],
            array_map(fn (string $q) => __('Queue: :name', ['name' => AgentCallStats::queueLabel($q)]), $summary->queues),
            array_map(fn (ClientTier $t) => __('Level: :name', ['name' => $t->label()]), $tiers),
            [__('Average duration (mm:ss)'), __('Missed calls marked handled')],
        );

        $rows = [];
        foreach ($agents as $agent) {
            $tierCounts = $byTier->get($agent['id'], collect());
            $rows[] = array_merge(
                [$agent['name'], $agent['total']],
                array_map(fn (string $q) => $agent['by_queue'][$q] ?? 0, $summary->queues),
                array_map(fn (ClientTier $t) => (int) $tierCounts->first(fn ($r) => ($r->tier instanceof ClientTier ? $r->tier->value : $r->tier) === $t->value)?->n, $tiers),
                [self::duration($durations->get($agent['id'])), (int) ($handledMissed[$agent['id']] ?? 0)],
            );
        }

        $totals = array_merge(
            [__('Total'), $summary->total],
            array_map(fn (string $q) => $summary->queueTotals[$q] ?? 0, $summary->queues),
            array_map(fn (ClientTier $t) => (int) $byTier->flatten()->filter(fn ($r) => ($r->tier instanceof ClientTier ? $r->tier->value : $r->tier) === $t->value)->sum('n'), $tiers),
            ['', (int) $handledMissed->sum()],
        );

        $daily = new ReportTable(
            ReportSection::Calls,
            __('Calls taken by agent, per day'),
            array_merge([__('Agent')], array_map(fn (string $d) => CarbonImmutable::parse($d)->format(HuDate::DATE), $summary->days), [__('Total')]),
            array_map(fn (array $agent) => array_merge(
                [$agent['name']],
                array_map(fn (string $d) => $summary->dayCount($agent, $d), $summary->days),
                [$agent['total']],
            ), $summary->agents),
            array_merge([__('Total')], array_map(fn (string $d) => $summary->dayTotals[$d] ?? 0, $summary->days), [$summary->total]),
        );

        return [
            new ReportTable(ReportSection::Calls, ReportSection::Calls->label(), $headers, $rows, $totals,
                __('A call counts for the agent who took it, on the day it arrived. Duration runs from answering to the end of the call.')),
            $daily,
        ];
    }

    private function callsByDay(CarbonImmutable $from, CarbonImmutable $until): ReportTable
    {
        $rows = Call::withTrashed()
            ->whereBetween('arrived_at', [$from, $until])
            ->selectRaw('DATE(arrived_at) AS day, COUNT(*) AS total, '
                .'SUM(CASE WHEN agent_user_id IS NOT NULL THEN 1 ELSE 0 END) AS taken, '
                ."SUM(CASE WHEN status IN ('".CallStatus::Missed->value."', '".CallStatus::Ended->value."') AND agent_user_id IS NULL THEN 1 ELSE 0 END) AS missed, "
                ."SUM(CASE WHEN status IN ('".CallStatus::Missed->value."', '".CallStatus::Ended->value."') AND agent_user_id IS NULL AND handled_at IS NOT NULL THEN 1 ELSE 0 END) AS missed_handled")
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $data = [];
        $sum = ['total' => 0, 'taken' => 0, 'missed' => 0, 'missed_handled' => 0];
        for ($day = $from; $day->lte($until); $day = $day->addDay()) {
            $row = $rows->get($day->toDateString());
            $values = ['total' => (int) ($row->total ?? 0), 'taken' => (int) ($row->taken ?? 0), 'missed' => (int) ($row->missed ?? 0), 'missed_handled' => (int) ($row->missed_handled ?? 0)];
            foreach ($values as $k => $v) {
                $sum[$k] += $v;
            }
            $data[] = [$day->format(HuDate::DATE), ...array_values($values)];
        }

        return new ReportTable(
            ReportSection::CallsByDay,
            ReportSection::CallsByDay->label(),
            [__('Day'), __('All calls'), __('Taken by an agent'), __('Missed'), __('Missed, marked handled')],
            $data,
            [__('Total'), ...array_values($sum)],
            __('A missed call ended before any agent took it; "marked handled" means an agent closed it from the dashboard afterwards.'),
        );
    }

    private function identifications(CarbonImmutable $from, CarbonImmutable $until): ReportTable
    {
        $rows = IdSession::withTrashed()
            ->whereNotNull('agent_user_id')
            ->whereBetween('started_at', [$from, $until])
            ->selectRaw('agent_user_id, method, status, COUNT(*) AS n')
            ->groupBy('agent_user_id', 'method', 'status')
            ->get();

        $names = User::withTrashed()->whereIn('id', $rows->pluck('agent_user_id')->unique())->pluck('name', 'id');
        $methods = IdMethod::cases();
        $value = fn ($enum) => $enum instanceof \BackedEnum ? $enum->value : (string) $enum;

        $perAgent = [];
        foreach ($rows as $row) {
            $id = (string) $row->agent_user_id;
            $perAgent[$id] ??= ['name' => (string) ($names[$id] ?? '?'), 'total' => 0, 'methods' => [], 'passed' => 0, 'failed' => 0, 'undecided' => 0, 'other' => 0];
            $n = (int) $row->n;
            $perAgent[$id]['total'] += $n;
            $perAgent[$id]['methods'][$value($row->method)] = ($perAgent[$id]['methods'][$value($row->method)] ?? 0) + $n;
            $bucket = match ($value($row->status)) {
                IdSessionStatus::Passed->value => 'passed',
                IdSessionStatus::Failed->value => 'failed',
                IdSessionStatus::Undecided->value => 'undecided',
                default => 'other',
            };
            $perAgent[$id][$bucket] += $n;
        }

        uasort($perAgent, fn (array $a, array $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        $data = [];
        $sum = ['total' => 0, 'passed' => 0, 'failed' => 0, 'undecided' => 0, 'other' => 0, 'methods' => []];
        foreach ($perAgent as $agent) {
            $data[] = array_merge(
                [$agent['name'], $agent['total']],
                array_map(fn (IdMethod $m) => $agent['methods'][$m->value] ?? 0, $methods),
                [$agent['passed'], $agent['failed'], $agent['undecided'], $agent['other'], self::percent($agent['passed'], $agent['total'])],
            );
            foreach (['total', 'passed', 'failed', 'undecided', 'other'] as $k) {
                $sum[$k] += $agent[$k];
            }
            foreach ($agent['methods'] as $m => $n) {
                $sum['methods'][$m] = ($sum['methods'][$m] ?? 0) + $n;
            }
        }

        return new ReportTable(
            ReportSection::Identifications,
            ReportSection::Identifications->label(),
            array_merge(
                [__('Agent'), __('Attempts')],
                array_map(fn (IdMethod $m) => $m->label(), $methods),
                [__('Passed'), __('Failed'), __('Undecided'), __('Expired or cancelled'), __('Success rate (%)')],
            ),
            $data,
            array_merge(
                [__('Total'), $sum['total']],
                array_map(fn (IdMethod $m) => $sum['methods'][$m->value] ?? 0, $methods),
                [$sum['passed'], $sum['failed'], $sum['undecided'], $sum['other'], self::percent($sum['passed'], $sum['total'])],
            ),
            __('An attempt counts for the agent who ran it, on the day it started. Success rate = passed / all attempts.'),
        );
    }

    private function activity(CarbonImmutable $from, CarbonImmutable $until): ReportTable
    {
        $userType = (new User)->getMorphClass();

        $rows = AuditLog::query()
            ->where('actor_type', $userType)
            ->whereBetween('created_at', [$from, $until])
            ->selectRaw("actor_id, COUNT(*) AS actions, SUM(CASE WHEN event = 'login.succeeded' THEN 1 ELSE 0 END) AS logins, MAX(created_at) AS last_at")
            ->groupBy('actor_id')
            ->get()
            ->keyBy('actor_id');

        $data = [];
        $sum = ['logins' => 0, 'actions' => 0];
        foreach (User::withTrashed()->whereIn('id', $rows->keys())->orderBy('name')->get() as $user) {
            $row = $rows->get($user->id);
            $data[] = [$user->name, (int) $row->logins, (int) $row->actions, CarbonImmutable::parse($row->last_at)->format(HuDate::DATETIME)];
            $sum['logins'] += (int) $row->logins;
            $sum['actions'] += (int) $row->actions;
        }

        usort($data, fn (array $a, array $b) => [$b[2], $a[0]] <=> [$a[2], $b[0]]);

        return new ReportTable(
            ReportSection::Activity,
            ReportSection::Activity->label(),
            [__('User'), __('Logins'), __('Audited actions'), __('Last action')],
            $data,
            [__('Total'), $sum['logins'], $sum['actions'], ''],
            __('Counted from the audit log: every entry the user caused, logins included.'),
        );
    }

    private static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '';
        }

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    private static function percent(int $part, int $whole): string
    {
        return $whole === 0 ? '' : (string) round($part / $whole * 100, 1);
    }
}
