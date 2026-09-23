<?php

namespace App\Filament\Admin\Widgets;

use App\Auth\Permission;
use App\Enums\IdSessionStatus;
use App\Models\IdSession;
use App\Reporting\AgentCallStats;
use App\Support\HuDate;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Facades\Cache;

/**
 * Identifications per day for the selected period, by outcome.
 */
class IdentificationsChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return auth()->user()?->can(Permission::IdentificationView->value) ?? false;
    }

    public function getHeading(): string
    {
        return __('Identifications, last :days days', ['days' => AgentCallStats::dashboardDays($this->pageFilters['days'] ?? null)]);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $period = AgentCallStats::dashboardDays($this->pageFilters['days'] ?? null);
        $days = collect(range($period - 1, 0))->map(fn (int $d) => today()->subDays($d));

        // One grouped query, shared by every agent for a minute: the chart is
        // decoration, not a live monitor.
        $counts = Cache::remember('hdid.dashboard.identifications.'.today()->toDateString().'.'.$period, 60, fn (): array => IdSession::query()
            ->whereBetween('started_at', [today()->subDays($period - 1), today()->endOfDay()])
            ->selectRaw('DATE(started_at) as day, status, COUNT(*) as n')
            ->groupBy('day', 'status')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->day.'|'.($row->status instanceof IdSessionStatus ? $row->status->value : $row->status) => (int) $row->n])
            ->all());

        $series = fn (IdSessionStatus $status) => $days->map(fn ($day) => $counts[$day->toDateString().'|'.$status->value] ?? 0)->all();

        return [
            'datasets' => [
                ['label' => __('passed'), 'data' => $series(IdSessionStatus::Passed), 'backgroundColor' => '#10b981'],
                ['label' => __('failed'), 'data' => $series(IdSessionStatus::Failed), 'backgroundColor' => '#ef4444'],
                ['label' => __('undecided'), 'data' => $series(IdSessionStatus::Undecided), 'backgroundColor' => '#f59e0b'],
            ],
            'labels' => $days->map(fn ($day) => $day->format(HuDate::MONTH_DAY))->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => ['x' => ['stacked' => true], 'y' => ['stacked' => true, 'ticks' => ['precision' => 0]]],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }
}
