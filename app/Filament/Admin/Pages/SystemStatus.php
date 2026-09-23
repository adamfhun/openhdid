<?php

namespace App\Filament\Admin\Pages;

use App\Auth\Permission;
use App\Filament\Admin\Clusters\System;
use App\System\Check;
use App\System\HealthChecks;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * One screen for "is everything running": workers, transports, interfaces.
 */
class SystemStatus extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?string $cluster = System::class;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'status';

    protected string $view = 'filament.admin.system-status';

    public static function getNavigationLabel(): string
    {
        return __('System status');
    }

    public function getTitle(): string
    {
        return __('System status');
    }

    /**
     * The release version is shown here only, never on public responses.
     */
    public function getSubheading(): ?string
    {
        $version = config('hdid.version');

        return $version ? __('Version :version', ['version' => $version]) : null;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    /**
     * @return list<Check>
     */
    public function getChecks(): array
    {
        return app(HealthChecks::class)->all();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')->label(__('Refresh'))->icon('heroicon-o-arrow-path')->action(fn () => null),
        ];
    }
}
