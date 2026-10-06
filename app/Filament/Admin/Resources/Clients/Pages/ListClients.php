<?php

namespace App\Filament\Admin\Resources\Clients\Pages;

use App\Filament\Actions\ExportClientsAction;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\ExternalRecords\ExternalRecordResource;
use App\Models\Client;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListClients extends ListRecords
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExportClientsAction::make(),
            CreateAction::make(),
        ];
    }

    /**
     * The "needs a link" tab collects every explicit-premium client without a
     * sponsor in one place; the badge shows how many are waiting.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $waiting = ClientResource::unlinkedPremiumCount();
        $closing = static::closingAtNextRun(Client::query())->count();

        return [
            'all' => Tab::make(__('All clients')),
            'unlinked' => Tab::make(__('Explicit premium without a link'))
                ->icon('heroicon-o-exclamation-triangle')
                ->badge($waiting > 0 ? $waiting : null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => ClientResource::scopeUnlinkedPremium($query)->open()),
            // The helpdesk sees how many open accounts the next EMD sync closes
            // if their records stay absent, before it happens.
            'closing' => Tab::make(__('Closes at the next EMD sync'))
                ->icon('heroicon-o-clock')
                ->badge($closing > 0 ? $closing : null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => static::closingAtNextRun($query)),
        ];
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public static function closingAtNextRun(Builder $query): Builder
    {
        return $query->open()->whereHas('externalRecord', fn (Builder $record) => $record->fromDirectory()->closingAtNextRun(ExternalRecordResource::threshold()));
    }
}
