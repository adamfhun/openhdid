<?php

namespace App\Filament\Admin\Resources\Clients\Pages;

use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Concerns\GuardsUnsavedChanges;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    use GuardsUnsavedChanges;

    protected static string $resource = ClientResource::class;

    /**
     * The new client's page is the natural next step, not the edit form.
     */
    protected function getRedirectUrl(): string
    {
        return ClientResource::afterSaveUrl($this->getRecord());
    }
}
