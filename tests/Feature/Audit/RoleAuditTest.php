<?php

use App\Audit\Auditor;
use App\Auth\Permission;
use App\Auth\Role;
use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use App\Filament\Admin\Resources\Roles\Pages\ManageRoles;
use App\Filament\Admin\Resources\Roles\RoleResource;
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

    $log = AuditLog::query()->where('event', 'role.updated')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->subject_type)->toBe($role->getMorphClass())
        // The integer key of the package's Role stays out of the uuid column (MariaDB refuses it).
        ->and($log->subject_id)->toBeNull()
        ->and($log->context['subject_key'])->toBe($role->id)
        ->and($log->subjectRecord()?->is($role))->toBeTrue()
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

it('names a role entry in the audit log by the role, although its key is kept in the context', function (): void {
    $role = RoleModel::findByName(Role::Agent->value, 'web');
    $log = app(Auditor::class)->record('role.updated', $role, ['role' => $role->name]);

    expect($log->fresh()->subject_id)->toBeNull()
        ->and(AuditLogResource::subjectLabel($log->fresh()))->toBe(__('Role').' · '.$role->name)
        ->and(AuditLogResource::describe($log->fresh()->subjectRecord())['url'])->toBe(RoleResource::getUrl('index'));

    $role->delete();

    expect(AuditLogResource::subjectLabel($log->fresh()))->toBe('Role · '.$role->id);
});

it('keeps a key that is not a UUID out of the actor and subject columns', function (): void {
    $actor = new class extends Illuminate\Foundation\Auth\User
    {
        protected $table = 'users';

        protected $keyType = 'string';

        public $incrementing = false;
    };
    $actor->forceFill(['id' => 'service-account']);
    $role = RoleModel::findByName(Role::Agent->value, 'web');

    $log = app(Auditor::class)->record('role.updated', $role, [], $actor)->fresh();

    expect($log->actor_id)->toBeNull()
        ->and($log->context['actor_key'])->toBe('service-account')
        ->and($log->subject_id)->toBeNull()
        ->and($log->context['subject_key'])->toBe($role->id);

    $user = User::factory()->create();
    $userLog = app(Auditor::class)->record('user.updated', $user, [], $this->admin)->fresh();

    expect($userLog->subject_id)->toBe($user->id)
        ->and($userLog->actor_id)->toBe($this->admin->id)
        ->and($userLog->context)->not->toHaveKeys(['subject_key', 'actor_key']);
});
