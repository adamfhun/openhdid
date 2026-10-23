<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Actions\UnbindSsoAction;
use App\Filament\Actions\UnlockLoginAction;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Concerns\GuardsUnsavedChanges;
use App\Filament\Concerns\LinksToDirectory;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    use GuardsUnsavedChanges;
    use LinksToDirectory;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UnlockLoginAction::make(),
            UnbindSsoAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * Users have no view page: back to the list after saving.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSavedNotification(): ?Notification
    {
        return $this->withDirectoryLink(parent::getSavedNotification());
    }
}
