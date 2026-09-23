<?php

namespace App\Filament\Admin\Clusters;

use App\Filament\Admin\NavigationGroup;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Settings, directory sync, keys, logs and health.
 */
class System extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Administration;

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('System');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('System');
    }
}
