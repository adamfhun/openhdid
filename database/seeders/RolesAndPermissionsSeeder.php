<?php

namespace Database\Seeders;

use App\Auth\Permission;
use App\Auth\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent and additive: creates missing permissions and roles and grants
 * newly introduced default permissions to the built-in roles that hold them
 * by default. A role that already exists only receives the permissions
 * created in this run, so the seeder never removes a permission an
 * administrator granted on the Roles page and never re-grants one they
 * revoked; re-running it on every upgrade is therefore safe.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var list<string> $createdPermissions */
        $createdPermissions = [];

        foreach (Permission::cases() as $permission) {
            if (PermissionModel::findOrCreate($permission->value, 'web')->wasRecentlyCreated) {
                $createdPermissions[] = $permission->value;
            }
        }

        foreach (Role::cases() as $role) {
            $roleModel = RoleModel::findOrCreate($role->value, 'web');

            $defaults = array_map(fn (Permission $permission) => $permission->value, $role->permissions());
            $grant = $roleModel->wasRecentlyCreated
                ? $defaults
                : array_values(array_intersect($defaults, $createdPermissions));

            if ($grant !== []) {
                $roleModel->givePermissionTo($grant);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
