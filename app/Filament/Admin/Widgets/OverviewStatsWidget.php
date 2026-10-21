<?php

namespace App\Filament\Admin\Widgets;

use App\Auth\Permission;
use App\Reporting\DashboardCounters;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Today at a glance: live call load (of the levels the user is viewing, like
 * the tables below) and how identifications went. The numbers come from
 * DashboardCounters, shared by everyone with the same view for one poll
 * interval, so thirty agents polling cost one round of queries, not thirty.
 */
class OverviewStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->can(Permission::CallsView->value) ?? false;
    }

    public function mount(): void
    {
        $this->pollingInterval = app(Settings::class)->int(SettingKey::CallsDashboardPollSeconds).'s';
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $counters = app(DashboardCounters::class)->forUser($user);

        $stats = [
            Stat::make(__('Ongoing calls'), $counters['ongoing'])
                ->description(__('Waiting or in progress'))
                ->descriptionIcon('heroicon-m-phone')
                ->color('info'),
            Stat::make(__('Missed calls'), $counters['missed'])
                ->description(__('Not yet handled'))
                ->descriptionIcon('heroicon-m-phone-x-mark')
                ->color('warning'),
        ];

        // The identification numbers are the same data the identification
        // chart and the sessions list show, so they follow the same
        // permission; an agent sees the call tiles only.
        if ($counters['identifications'] !== null) {
            $i = $counters['identifications'];
            $stats[] = Stat::make(__('Identifications today'), $i['total'])
                ->description(__(':p passed · :f failed', ['p' => $i['passed'], 'f' => $i['failed']]))
                ->descriptionIcon('heroicon-m-identification')
                ->chart($i['trend'])
                ->color($i['total'] > 0 && $i['failed'] / max($i['total'], 1) > 0.3 ? 'danger' : 'success');
        }

        if ($counters['clients'] !== null) {
            $stats[] = Stat::make(__('Clients'), $counters['clients']['total'])
                ->description(__(':n with a PIN', ['n' => $counters['clients']['with_pin']]))
                ->descriptionIcon('heroicon-m-user-group')
                ->color('gray');
        }

        return $stats;
    }
}
