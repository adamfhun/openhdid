<?php

namespace App\Filament\Admin\Resources\NewsPosts\Pages;

use App\Filament\Admin\Resources\NewsPosts\NewsPostResource;
use App\Filament\Concerns\LimitsSearchInput;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageNewsPosts extends ManageRecords
{
    use LimitsSearchInput;

    protected static string $resource = NewsPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['author_user_id' => auth()->id()]),
        ];
    }
}
