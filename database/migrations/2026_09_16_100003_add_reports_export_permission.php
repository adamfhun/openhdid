<?php

use App\Auth\Permission;
use App\Auth\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * The reports permission must exist as soon as the Reports page is deployed,
 * without waiting for the roles seeder to be re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = PermissionModel::findOrCreate(Permission::ReportsExport->value, 'web');

        foreach (Role::cases() as $role) {
            if (! in_array(Permission::ReportsExport, $role->permissions(), true)) {
                continue;
            }

            RoleModel::query()->where('name', $role->value)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        PermissionModel::query()->where('name', Permission::ReportsExport->value)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
