<?php

namespace App\Filament\Admin\Clusters;

use App\Filament\Admin\NavigationGroup;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * What clients read: news, message templates, and the outbox.
 */
class Content extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Administration;

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('Content');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('Content');
    }
}
