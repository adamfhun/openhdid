<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Concerns\GuardsUnsavedChanges;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use GuardsUnsavedChanges;

    protected static string $resource = UserResource::class;
}
