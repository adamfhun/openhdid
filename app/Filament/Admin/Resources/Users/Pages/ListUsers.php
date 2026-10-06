<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\ExternalRecords\ExternalRecordResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $closing = static::closingAtNextRun(User::query())->count();

        return [
            'all' => Tab::make(__('All')),
            'closing' => Tab::make(__('Closes at the next EMD sync'))
                ->icon('heroicon-o-clock')
                ->badge($closing > 0 ? $closing : null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => static::closingAtNextRun($query)),
        ];
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function closingAtNextRun(Builder $query): Builder
    {
        return $query->open()->whereHas('externalRecord', fn (Builder $record) => $record->fromDirectory()->closingAtNextRun(ExternalRecordResource::threshold()));
    }
}
