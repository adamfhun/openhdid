<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Concerns\GuardsUnsavedChanges;
use App\Filament\Concerns\LinksToDirectory;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use GuardsUnsavedChanges;
    use LinksToDirectory;

    protected static string $resource = UserResource::class;

    protected function getCreatedNotification(): ?Notification
    {
        return $this->withDirectoryLink(parent::getCreatedNotification());
    }
}
