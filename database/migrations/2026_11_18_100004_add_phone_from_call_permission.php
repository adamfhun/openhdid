<?php

use App\Auth\Permission;
use App\Auth\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permission to add the number a caller rings from to the client during
 * the call (after the identification, with the caller's consent), for the
 * roles that hold it by default: an installation already running gets it
 * without the roles seeder being run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = PermissionModel::findOrCreate(Permission::ClientsPhoneAddFromCall->value, 'web');

        foreach (Role::cases() as $role) {
            if (! in_array(Permission::ClientsPhoneAddFromCall, $role->permissions(), true)) {
                continue;
            }

            RoleModel::query()->where('name', $role->value)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        PermissionModel::query()->where('name', Permission::ClientsPhoneAddFromCall->value)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
