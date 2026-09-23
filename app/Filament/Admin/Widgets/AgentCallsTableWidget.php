<?php

namespace App\Filament\Admin\Widgets;

use App\Auth\Permission;
use App\Reporting\AgentCallStats;
use App\Reporting\AgentCallSummary;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * The numbers behind the agent chart: one row per agent in the same order,
 * one column per day, every cell broken down by call queue, totals in the
 * last column and the last row.
 */
class AgentCallsTableWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 5;

    // Rendered with the page: the summary is cached and cheap, and a lazy
    // placeholder would leave an empty card under the chart.
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.admin.widgets.agent-calls-table';

    public static function canView(): bool
    {
        return auth()->user()?->can(Permission::CallsView->value) ?? false;
    }

    public function getSummary(): AgentCallSummary
    {
        return app(AgentCallStats::class)->dashboard(auth()->user(), $this->pageFilters['days'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'summary' => $this->getSummary(),
            'days' => AgentCallStats::dashboardDays($this->pageFilters['days'] ?? null),
            'colors' => AgentCallStats::palette(),
        ];
    }
}
