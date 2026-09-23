<?php

namespace App\Filament\Admin\Clusters;

use App\Filament\Admin\NavigationGroup;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Staff accounts and their roles.
 */
class Accounts extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Administration;

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('Accounts');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('Accounts');
    }
}
