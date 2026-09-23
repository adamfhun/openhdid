<?php

namespace App\Filament\Admin\Resources\Clients\Pages;

use App\Filament\Admin\Resources\Clients\ClientResource;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    /**
     * The new client's page is the natural next step, not the edit form.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
