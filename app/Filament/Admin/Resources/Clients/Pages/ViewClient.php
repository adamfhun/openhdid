<?php

namespace App\Filament\Admin\Resources\Clients\Pages;

use App\Audit\Auditor;
use App\Filament\Actions\SendMagicLinkAction;
use App\Filament\Actions\SetPinAction;
use App\Filament\Admin\Resources\Clients\ClientResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        app(Auditor::class)->record('client.viewed', $this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        return [
            ClientResource::identifyAction(),
            ClientResource::linkToSponsorAction(),
            ClientResource::muteLinkWarningAction(),
            ClientResource::unmuteLinkWarningAction(),
            SetPinAction::make(),
            SendMagicLinkAction::make(),
            ClientResource::overrideAction(),
            ClientResource::endOverrideAction(),
            EditAction::make(),
        ];
    }
}
