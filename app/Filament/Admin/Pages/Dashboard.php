<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\AgentCallsChartWidget;
use App\Filament\Admin\Widgets\AgentCallsTableWidget;
use App\Filament\Admin\Widgets\IdentificationsChartWidget;
use App\Reporting\AgentCallStats;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Widgets\WidgetConfiguration;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function content(Schema $schema): Schema
    {
        [$reports, $operations] = collect($this->getWidgets())->partition(
            fn (string|WidgetConfiguration $widget): bool => in_array(
                $widget instanceof WidgetConfiguration ? $widget->widget : $widget,
                [IdentificationsChartWidget::class, AgentCallsChartWidget::class, AgentCallsTableWidget::class],
                true,
            ),
        );
        $reportComponents = $this->getWidgetsSchemaComponents($reports->all());

        return $schema->components([
            Grid::make($this->getColumns())
                ->schema($this->getWidgetsSchemaComponents($operations->all())),
            Group::make([
                Section::make(__('Reports'))
                    ->extraAttributes(['class' => '[&_.fi-section-header-heading]:text-center'])
                    ->schema([$this->getFiltersFormContentComponent()]),
                Grid::make($this->getColumns())->schema($reportComponents),
            ])
                ->extraAttributes(['class' => 'mt-6 border-t border-gray-200 pt-8 dark:border-gray-700'])
                ->visible($reportComponents !== []),
        ]);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Select::make('days')
                ->label(__('Report period'))
                ->columnSpanFull()
                ->extraFieldWrapperAttributes(['class' => 'hdid-dashboard-period'])
                ->options(collect(AgentCallStats::DASHBOARD_PERIODS)->mapWithKeys(fn (int $days): array => [$days => __('Last :days days', ['days' => $days])])->all())
                ->default(AgentCallStats::DASHBOARD_DAYS)
                ->selectablePlaceholder(false)
                ->live(),
        ]);
    }
}
