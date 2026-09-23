<?php

namespace App\Filament\Admin\Resources\ExternalRecords\Pages;

use App\Filament\Admin\Resources\ExternalRecords\ExternalRecordResource;
use Filament\Resources\Pages\ManageRecords;

class ManageExternalRecords extends ManageRecords
{
    protected static string $resource = ExternalRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
