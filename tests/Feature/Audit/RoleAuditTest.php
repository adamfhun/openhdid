<?php

use App\Auth\Permission;
use App\Auth\Role;
use App\Filament\Admin\Resources\Roles\Pages\ManageRoles;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->syncRoles([Role::SuperAdmin->value]);
    $this->actingAs($this->admin);
});

it('audits granted and revoked permissions when a role is edited', function (): void {
    $role = RoleModel::findByName(Role::Agent->value, 'web');
    $before = $role->permissions->pluck('name')->all();
    $granted = PermissionModel::findByName(Permission::SettingsManage->value, 'web');
    $revoked = $before[0];
    $wanted = array_values(array_diff($before, [$revoked]));
    $wanted[] = $granted->name;
    $ids = PermissionModel::query()->whereIn('name', $wanted)->pluck('id')->all();

    Livewire::test(ManageRoles::class)
        ->callTableAction('edit', $role, ['name' => $role->name, 'permissions' => $ids])
        ->assertHasNoTableActionErrors();

    $log = AuditLog::query()->where('event', 'role.updated')->where('subject_id', $role->id)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->actor_id)->toBe($this->admin->id)
        ->and($log->context['granted'])->toBe([Permission::SettingsManage->value])
        ->and($log->context['revoked'])->toBe([$revoked])
        ->and($log->context['permissions'])->toContain(Permission::SettingsManage->value);
});

it('audits a newly created role with its permissions', function (): void {
    $ids = PermissionModel::query()->whereIn('name', [Permission::CallsView->value])->pluck('id')->all();

    Livewire::test(ManageRoles::class)
        ->callAction('create', ['name' => 'Auditor', 'permissions' => $ids])
        ->assertHasNoActionErrors();

    $log = AuditLog::query()->where('event', 'role.created')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->context['role'])->toBe('Auditor')
        ->and($log->context['permissions'])->toBe([Permission::CallsView->value]);
});
