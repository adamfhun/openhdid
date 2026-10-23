<?php

namespace App\Filament\Admin\Resources\Users\Pages;

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

    /**
     * Unlock appears only while the login is locked; the sign-in binding's
     * own button stands in the "Company sign-in binding" block of the form.
     */
    protected function getHeaderActions(): array
    {
        return [
            UnlockLoginAction::make(),
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
