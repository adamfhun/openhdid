<?php

namespace App\Filament\Admin\Widgets;

use App\Auth\Permission;
use App\Reporting\AgentCallStats;
use App\Reporting\AgentCallSummary;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Calls taken per agent in the selected period: one row per agent (busiest
 * on top), one thin bar per call queue. Follows the call view switch like
 * the other call widgets.
 */
class AgentCallsChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can(Permission::CallsView->value) ?? false;
    }

    public function getHeading(): string
    {
        $tier = auth()->user()?->activeTier();

        return $tier
            ? __(':tier calls taken by agent, last :days days', ['tier' => $tier->label(), 'days' => AgentCallStats::dashboardDays($this->pageFilters['days'] ?? null)])
            : __('Calls taken by agent, last :days days', ['days' => AgentCallStats::dashboardDays($this->pageFilters['days'] ?? null)]);
    }

    public function getDescription(): ?string
    {
        return __('One bar per call queue; agents with at least one call, busiest first.');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function summary(): AgentCallSummary
    {
        return app(AgentCallStats::class)->dashboard(auth()->user(), $this->pageFilters['days'] ?? null);
    }

    protected function getMaxHeight(): ?string
    {
        $summary = $this->summary();
        $bars = max(1, count($summary->agents)) * max(1, count($summary->queues));

        return min(900, 90 + $bars * 14).'px';
    }

    protected function getData(): array
    {
        $summary = $this->summary();

        $datasets = [];
        foreach ($summary->queues as $index => $queue) {
            $datasets[] = [
                'label' => AgentCallStats::queueLabel($queue),
                'data' => array_map(fn (array $agent) => $agent['by_queue'][$queue] ?? 0, $summary->agents),
                'backgroundColor' => AgentCallStats::colorFor($index),
                'barThickness' => 8,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => array_map(fn (array $agent) => $agent['name'], $summary->agents),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'scales' => [
                'x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
                'y' => ['ticks' => ['autoSkip' => false]],
            ],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }
}
