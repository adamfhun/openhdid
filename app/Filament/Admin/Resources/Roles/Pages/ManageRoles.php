<?php

namespace App\Filament\Admin\Resources\Roles\Pages;

use App\Audit\RoleAudit;
use App\Filament\Admin\Resources\Roles\RoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Spatie\Permission\PermissionRegistrar;

class ManageRoles extends ManageRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['guard_name' => 'web'])
                ->after(function ($record): void {
                    app(PermissionRegistrar::class)->forgetCachedPermissions();
                    RoleAudit::recordChanges($record, 'role.created');
                }),
        ];
    }
}
