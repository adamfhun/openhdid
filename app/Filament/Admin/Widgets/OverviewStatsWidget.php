<?php

namespace App\Filament\Admin\Widgets;

use App\Auth\Permission;
use App\Enums\IdSessionStatus;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Today at a glance: live call load (of the levels the user is viewing, like
 * the tables below) and how identifications went.
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
        $seesIdentifications = $user?->can(Permission::IdentificationView->value) ?? false;
        $since = today()->subDays(6)->startOfDay();

        // One grouped query for the last seven days instead of a count per day and status.
        $byDay = ! $seesIdentifications ? collect() : IdSession::query()
            ->where('started_at', '>=', $since)
            ->selectRaw('DATE(started_at) as day, status, COUNT(*) as n')
            ->groupBy('day', 'status')
            ->get()
            ->groupBy('day');

        $todayRows = $byDay->get(today()->toDateString(), collect());
        $total = (int) $todayRows->sum('n');
        $passed = (int) $todayRows->where('status', IdSessionStatus::Passed)->sum('n');
        $failed = (int) $todayRows->where('status', IdSessionStatus::Failed)->sum('n');

        $trend = collect(range(6, 0))->map(fn (int $daysAgo) => (int) $byDay
            ->get(today()->subDays($daysAgo)->toDateString(), collect())
            ->where('status', IdSessionStatus::Passed)
            ->sum('n'))->all();

        $clients = ! ($user?->can(Permission::ClientsView->value) ?? false) ? null : Client::query()->open()
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN pin_hash IS NOT NULL THEN 1 ELSE 0 END) as with_pin')
            ->first();

        $stats = [
            Stat::make(__('Ongoing calls'), Call::query()->ongoing()->visibleTo($user)->count())
                ->description(__('Waiting or in progress'))
                ->descriptionIcon('heroicon-m-phone')
                ->color('info'),
            Stat::make(__('Missed calls'), Call::query()->missed()->visibleTo($user)->count())
                ->description(__('Not yet handled'))
                ->descriptionIcon('heroicon-m-phone-x-mark')
                ->color('warning'),
        ];

        // The identification numbers are the same data the identification
        // chart and the sessions list show, so they follow the same
        // permission; an agent sees the call tiles only.
        if ($user?->can(Permission::IdentificationView->value)) {
            $stats[] = Stat::make(__('Identifications today'), $total)
                ->description(__(':p passed · :f failed', ['p' => $passed, 'f' => $failed]))
                ->descriptionIcon('heroicon-m-identification')
                ->chart($trend)
                ->color($total > 0 && $failed / max($total, 1) > 0.3 ? 'danger' : 'success');
        }

        if ($user?->can(Permission::ClientsView->value)) {
            $stats[] = Stat::make(__('Clients'), (int) ($clients->total ?? 0))
                ->description(__(':n with a PIN', ['n' => (int) ($clients->with_pin ?? 0)]))
                ->descriptionIcon('heroicon-m-user-group')
                ->color('gray');
        }

        return $stats;
    }
}
