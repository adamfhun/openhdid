<?php

namespace App\Filament\Admin\Resources\ExternalRecords\Pages;

use App\Filament\Admin\Resources\ExternalRecords\ExternalRecordResource;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageExternalRecords extends ManageRecords
{
    protected static string $resource = ExternalRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $closing = ExternalRecordResource::closingAtNextRunCount();

        return [
            'all' => Tab::make(__('All')),
            'closing' => Tab::make(__('Closes at the next sync'))
                ->icon('heroicon-o-exclamation-triangle')
                ->badge($closing > 0 ? $closing : null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->fromDirectory()->closingAtNextRun(ExternalRecordResource::threshold())),
            'missing' => Tab::make(__('Missing'))
                ->icon('heroicon-o-x-circle')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('missing_since')),
        ];
    }
}
