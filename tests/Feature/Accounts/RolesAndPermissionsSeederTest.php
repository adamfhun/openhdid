<?php

use App\Auth\Permission;
use App\Auth\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('creates every permission and built-in role with its default permissions on a fresh database', function (): void {
    foreach (Permission::cases() as $permission) {
        expect(PermissionModel::query()->where('name', $permission->value)->where('guard_name', 'web')->exists())->toBeTrue();
    }

    foreach (Role::cases() as $role) {
        $roleModel = RoleModel::findByName($role->value, 'web');

        expect($roleModel->permissions()->pluck('name')->sort()->values()->all())
            ->toBe(collect($role->permissions())->map(fn (Permission $permission) => $permission->value)->sort()->values()->all());
    }
});

it('keeps a permission an administrator revoked from a built-in role when the seeder runs again on upgrade', function (): void {
    $agent = RoleModel::findByName(Role::Agent->value, 'web');
    $agent->revokePermissionTo(Permission::IdentificationManual->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($agent->fresh()->hasPermissionTo(Permission::IdentificationManual->value))->toBeFalse();

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(RoleModel::findByName(Role::Agent->value, 'web')->hasPermissionTo(Permission::IdentificationManual->value))
        ->toBeFalse('the upgrade seeder re-granted a permission the administrator had revoked');
});

it('keeps a permission an administrator granted to a built-in role beyond its defaults when the seeder runs again', function (): void {
    $agent = RoleModel::findByName(Role::Agent->value, 'web');
    $agent->givePermissionTo(Permission::AuditView->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(RoleModel::findByName(Role::Agent->value, 'web')->hasPermissionTo(Permission::AuditView->value))
        ->toBeTrue('the upgrade seeder removed a permission the administrator had granted');
});

it('still creates a missing permission and grants it to the roles that hold it by default', function (): void {
    PermissionModel::query()->where('name', Permission::ReportsExport->value)->where('guard_name', 'web')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(PermissionModel::query()->where('name', Permission::ReportsExport->value)->exists())->toBeFalse();

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(PermissionModel::query()->where('name', Permission::ReportsExport->value)->exists())->toBeTrue()
        ->and(RoleModel::findByName(Role::Supervisor->value, 'web')->hasPermissionTo(Permission::ReportsExport->value))->toBeTrue()
        ->and(RoleModel::findByName(Role::Admin->value, 'web')->hasPermissionTo(Permission::ReportsExport->value))->toBeTrue()
        ->and(RoleModel::findByName(Role::Agent->value, 'web')->hasPermissionTo(Permission::ReportsExport->value))->toBeFalse();
});

it('recreates a deleted built-in role with its default permissions without touching the other roles', function (): void {
    $admin = RoleModel::findByName(Role::Admin->value, 'web');
    $admin->revokePermissionTo(Permission::AuditView->value);
    RoleModel::query()->where('name', Role::Agent->value)->where('guard_name', 'web')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(RolesAndPermissionsSeeder::class);

    $agent = RoleModel::findByName(Role::Agent->value, 'web');

    expect($agent->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(collect(Role::Agent->permissions())->map(fn (Permission $permission) => $permission->value)->sort()->values()->all())
        ->and(RoleModel::findByName(Role::Admin->value, 'web')->hasPermissionTo(Permission::AuditView->value))->toBeFalse();
});
