<?php

use App\Auth\Permission;
use App\Auth\Role;
use App\Auth\RoleAssignment;
use App\Filament\Admin\Resources\Roles\Pages\ManageRoles;
use App\Models\AuditLog;
use App\Models\ClientLink;
use App\Models\QuestionVersion;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->superRole = RoleModel::findByName(Role::SuperAdmin->value, 'web');
    $this->adminRole = RoleModel::findByName(Role::Admin->value, 'web');
    $this->agentRole = RoleModel::findByName(Role::Agent->value, 'web');
});

/**
 * Ids of the named permissions, as the permissions checkbox list keys them.
 *
 * @param  list<Permission>  $permissions
 * @return list<int|string>
 */
function permissionIds(array $permissions): array
{
    return PermissionModel::query()->whereIn('name', array_map(fn (Permission $permission) => $permission->value, $permissions))
        ->pluck('id')->all();
}

/**
 * A custom role holding only the given permissions, and a user in it.
 *
 * @param  list<Permission>  $permissions
 */
function userWithCustomRole(string $roleName, array $permissions): User
{
    $role = RoleModel::create(['name' => $roleName, 'guard_name' => 'web']);
    $role->givePermissionTo(array_map(fn (Permission $permission) => $permission->value, $permissions));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return tap(User::factory()->create())->assignRole($roleName);
}

/**
 * The help line under a form field (its below-content text).
 */
function roleFieldHelp(Field $field): string
{
    return collect($field->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->getComponents() ?? [])
        ->map(fn ($component): string => $component instanceof Text ? (string) $component->getContent() : '')
        ->implode(' ');
}

/**
 * @return list<string>
 */
function roleGrants(RoleModel $role): array
{
    return $role->fresh()->permissions->pluck('name')->sort()->values()->all();
}

it('binds a policy to the model of every panel resource', function (): void {
    foreach (Filament::getPanels() as $panel) {
        foreach ($panel->getResources() as $resource) {
            expect(Gate::getPolicyFor($resource::getModel()))
                ->not->toBeNull("{$resource} has no policy for ".$resource::getModel());
        }
    }
});

it('binds a policy to the model of every relation manager tab, or names why its actions are authorized one by one', function (): void {
    // Tabs whose model has no policy: every action of theirs carries its own authorize(), and a
    // standard Create/Edit/Delete action added later would need a policy first.
    $explicitlyAuthorized = [
        ClientLink::class => 'link and unlink ask update on the sponsor (LinkedClientsRelationManager)',
        QuestionVersion::class => 'read-only history, no action (VersionsRelationManager)',
    ];

    foreach (Filament::getPanels() as $panel) {
        foreach ($panel->getResources() as $resource) {
            foreach ($resource::getRelations() as $relation) {
                $manager = is_string($relation) ? $relation : null;
                if ($manager === null || ! is_subclass_of($manager, RelationManager::class)) {
                    continue;
                }

                $relationship = (new ReflectionProperty($manager, 'relationship'))->getValue();
                $model = get_class((new ($resource::getModel()))->{$relationship}()->getRelated());

                expect(Gate::getPolicyFor($model) !== null || array_key_exists($model, $explicitlyAuthorized))
                    ->toBeTrue("{$manager} shows {$model}, which has no policy and is not on the explicit list");
            }
        }
    }
});

it('keeps an agent and a supervisor off the roles page and its actions', function (Role $role): void {
    $user = User::factory()->withRole($role)->create();
    $this->actingAs($user);

    $this->get('/admin/accounts/roles')->assertForbidden();
    Livewire::test(ManageRoles::class)->assertForbidden();

    expect($user->can('viewAny', RoleModel::class))->toBeFalse()
        ->and($user->can('create', RoleModel::class))->toBeFalse()
        ->and($user->can('update', $this->agentRole))->toBeFalse()
        ->and($user->can('delete', $this->agentRole))->toBeFalse()
        ->and($user->fresh()->can(Permission::ApiKeysManage->value))->toBeFalse();
})->with([Role::Agent, Role::Supervisor]);

it('never writes back the name of a built-in role from a crafted request', function (): void {
    $super = User::factory()->withRole(Role::SuperAdmin)->create();
    $this->actingAs($super);

    Livewire::test(ManageRoles::class)
        ->mountTableAction('edit', $this->superRole)
        ->set('mountedActions.0.data.name', 'former-super')
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($this->superRole->fresh()->name)->toBe(Role::SuperAdmin->value)
        ->and(app(RoleAssignment::class)->isSuperAdmin($super->fresh()))->toBeTrue();

    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin);

    Livewire::test(ManageRoles::class)
        ->mountTableAction('edit', $this->adminRole)
        ->set('mountedActions.0.data.name', 'super-admin-2')
        ->callMountedTableAction();

    expect($this->adminRole->fresh()->name)->toBe(Role::Admin->value)
        ->and(app(RoleAssignment::class)->isSuperAdmin($admin->fresh()))->toBeFalse();
});

it('shows the name of a built-in role as fixed and leaves it out of the saved data', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    Livewire::test(ManageRoles::class)
        ->mountTableAction('edit', $this->agentRole)
        ->assertFormFieldExists('name', fn (TextInput $field): bool => $field->isDisabled() && ! $field->isDehydrated()
            && roleFieldHelp($field) === __('Built-in role: its name is fixed, because the permission checks rely on it.'));
});

it('keeps an admin from editing the super-admin role and says why', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin);
    $before = roleGrants($this->superRole);

    $page = Livewire::test(ManageRoles::class)
        ->assertTableActionVisible('edit', $this->superRole)
        ->assertTableActionDisabled('edit', $this->superRole)
        ->assertTableActionExists('edit', fn (EditAction $action) => $action->getTooltip() === __('Only a SuperAdmin can change the SuperAdmin role.'), $this->superRole);

    // A hand-crafted request: the mounted edit of the super-admin row with a new name and no permissions.
    $page->set('mountedActions', [[
        'name' => 'edit',
        'arguments' => [],
        'context' => ['table' => true, 'recordKey' => (string) $this->superRole->getKey()],
        'data' => ['name' => 'former-super', 'permissions' => []],
    ]])->call('callMountedAction');

    expect($this->superRole->fresh()->name)->toBe(Role::SuperAdmin->value)
        ->and(roleGrants($this->superRole))->toBe($before)
        ->and($admin->can('update', $this->superRole))->toBeFalse()
        ->and($admin->can('update', $this->adminRole))->toBeTrue();

    // The same crafted request does reach the save for a role the admin may edit (the probe is not empty).
    Livewire::test(ManageRoles::class)->set('mountedActions', [[
        'name' => 'edit',
        'arguments' => [],
        'context' => ['table' => true, 'recordKey' => (string) $this->agentRole->getKey()],
        'data' => ['name' => 'former-agent', 'permissions' => permissionIds([Permission::AuditView])],
    ]])->call('callMountedAction');

    expect($this->agentRole->fresh()->name)->toBe(Role::Agent->value)
        ->and(roleGrants($this->agentRole))->toBe([Permission::AuditView->value]);
});

it('keeps an admin from emptying the permissions of the super-admin role', function (): void {
    $super = User::factory()->withRole(Role::SuperAdmin)->create();
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    Livewire::test(ManageRoles::class)
        ->callTableAction('edit', $this->superRole, ['permissions' => []]);

    expect(roleGrants($this->superRole))->toHaveCount(count(Permission::cases()))
        ->and($super->fresh()->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

it('lets a super-admin change the permissions of the super-admin role', function (): void {
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create());
    $kept = array_values(array_filter(Permission::cases(), fn (Permission $permission) => $permission !== Permission::NewsManage));

    Livewire::test(ManageRoles::class)
        ->callTableAction('edit', $this->superRole, ['permissions' => permissionIds($kept)])
        ->assertHasNoTableActionErrors();

    expect(roleGrants($this->superRole))->not->toContain(Permission::NewsManage->value)
        ->and(roleGrants($this->superRole))->toHaveCount(count(Permission::cases()) - 1);
});

it('refuses to name a custom role like a built-in one', function (string $name): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $custom = RoleModel::create(['name' => 'Helpers', 'guard_name' => 'web']);

    Livewire::test(ManageRoles::class)
        ->callTableAction('edit', $custom, ['name' => $name, 'permissions' => []])
        ->assertHasTableActionErrors(['name']);

    Livewire::test(ManageRoles::class)
        ->callAction('create', ['name' => $name, 'permissions' => []])
        ->assertHasActionErrors(['name']);

    expect($custom->fresh()->name)->toBe('Helpers')
        ->and(RoleModel::query()->where('name', $name)->exists())->toBeFalse();
})->with(['Super-Admin', ' super-admin', 'ADMIN']);

it('audits a plain rename of a custom role', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $custom = RoleModel::create(['name' => 'Helpers', 'guard_name' => 'web']);
    $custom->givePermissionTo(Permission::AuditView->value);

    Livewire::test(ManageRoles::class)
        ->callTableAction('edit', $custom, ['name' => 'Night shift', 'permissions' => permissionIds([Permission::AuditView])])
        ->assertHasNoTableActionErrors();

    $entry = AuditLog::query()->where('event', 'role.updated')->sole();
    expect($custom->fresh()->name)->toBe('Night shift')
        ->and($entry->context)->toMatchArray(['role' => 'Night shift', 'renamed_from' => 'Helpers', 'granted' => [], 'revoked' => []]);
});

it('refuses a role keeper granting their own role a permission they do not hold', function (): void {
    $keeper = userWithCustomRole('Role keeper', [Permission::AdminAccess, Permission::RolesManage]);
    $this->actingAs($keeper);
    $role = RoleModel::findByName('Role keeper', 'web');

    Livewire::test(ManageRoles::class)
        ->callTableAction('edit', $role, ['permissions' => PermissionModel::query()->pluck('id')->all()])
        ->assertHasTableActionErrors(['permissions']);

    expect(roleGrants($role))->toBe([Permission::AdminAccess->value, Permission::RolesManage->value])
        ->and($keeper->fresh()->can(Permission::ApiKeysManage->value))->toBeFalse();

    $entry = AuditLog::query()->where('event', 'role.permission_change_rejected')->sole();
    expect($entry->actor_id)->toBe($keeper->id)
        ->and($entry->context['rejected'])->toContain(Permission::ApiKeysManage->value)
        ->and($entry->context['rejected'])->not->toContain(Permission::RolesManage->value);
});

it('refuses a role keeper creating a role with a permission they do not hold', function (): void {
    $this->actingAs(userWithCustomRole('Role keeper', [Permission::AdminAccess, Permission::RolesManage]));

    Livewire::test(ManageRoles::class)
        ->callAction('create', ['name' => 'Shadow', 'permissions' => permissionIds([Permission::SettingsManage])])
        ->assertHasActionErrors(['permissions']);

    expect(RoleModel::query()->where('name', 'Shadow')->exists())->toBeFalse();
});

it('greys out the permissions the editor does not hold, with the reason', function (): void {
    $this->actingAs(userWithCustomRole('Role keeper', [Permission::AdminAccess, Permission::RolesManage, Permission::AuditView]));
    $held = array_map('strval', permissionIds([Permission::AdminAccess, Permission::RolesManage, Permission::AuditView]));

    Livewire::test(ManageRoles::class)
        ->mountTableAction('edit', $this->agentRole)
        ->assertFormFieldExists('permissions', function (CheckboxList $field) use ($held): bool {
            $enabled = array_map('strval', array_keys($field->getEnabledOptions()));
            sort($enabled);
            sort($held);

            return $enabled === $held
                && roleFieldHelp($field) === __('Greyed-out permissions are ones you do not hold yourself: you can neither grant nor revoke them.')
                && in_array(__('You do not hold this permission yourself.'), $field->getDescriptions(), true);
        });
});

it('does not grey out anything for an editor holding every permission', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    Livewire::test(ManageRoles::class)
        ->mountTableAction('edit', $this->agentRole)
        ->assertFormFieldExists('permissions', fn (CheckboxList $field): bool => count($field->getEnabledOptions()) === count(Permission::cases())
            && roleFieldHelp($field) === '' && array_filter($field->getDescriptions()) === []);
});

it('keeps the permissions the editor does not hold when the editor saves a role', function (): void {
    $this->actingAs(userWithCustomRole('Role keeper', [Permission::AdminAccess, Permission::RolesManage, Permission::AuditView]));
    $ops = RoleModel::create(['name' => 'Ops', 'guard_name' => 'web']);
    $ops->givePermissionTo([Permission::ApiKeysManage->value, Permission::AuditView->value]);

    // The normal save: the greyed-out permission stays ticked, the held one is revoked.
    Livewire::test(ManageRoles::class)
        ->callTableAction('edit', $ops, ['permissions' => permissionIds([Permission::ApiKeysManage])])
        ->assertHasNoTableActionErrors();

    expect(roleGrants($ops))->toBe([Permission::ApiKeysManage->value]);

    // A crafted save dropping the permission the editor does not hold is refused.
    Livewire::test(ManageRoles::class)
        ->callTableAction('edit', $ops, ['permissions' => []])
        ->assertHasTableActionErrors(['permissions']);

    expect(roleGrants($ops))->toBe([Permission::ApiKeysManage->value]);
});

it('restores the full permission set of the super-admin role from the command line', function (): void {
    Queue::fake();
    $this->superRole->syncPermissions([]);

    $this->artisan('hdid:make-admin', ['email' => 'rescue@example.test', '--password' => 'a-long-password-1'])
        ->expectsOutputToContain('restored')
        ->assertSuccessful();

    $rescue = User::query()->where('email', 'rescue@example.test')->sole();
    expect($rescue->hasRole(Role::SuperAdmin->value))->toBeTrue()
        ->and($rescue->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and(roleGrants($this->superRole))->toHaveCount(count(Permission::cases()))
        ->and(AuditLog::query()->where('event', 'account.admin_made_by_command')->sole()->context['role_permissions_restored'])
        ->toHaveCount(count(Permission::cases()));
});

it('recreates a renamed super-admin role from the command line', function (): void {
    Queue::fake();
    $this->superRole->update(['name' => 'former-super']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->artisan('hdid:make-admin', ['email' => 'rescue@example.test', '--password' => 'a-long-password-1'])
        ->expectsOutputToContain('recreated')
        ->assertSuccessful();

    $role = RoleModel::findByName(Role::SuperAdmin->value, 'web');
    $rescue = User::query()->where('email', 'rescue@example.test')->sole();
    expect($role->getKey())->not->toBe($this->superRole->getKey())
        ->and(roleGrants($role))->toHaveCount(count(Permission::cases()))
        ->and($rescue->hasRole(Role::SuperAdmin->value))->toBeTrue()
        ->and($rescue->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'account.admin_made_by_command')->sole()->context['role_recreated'])->toBeTrue();
});

it('takes a look-alike of a built-in role name as reserved', function (string $name): void {
    expect(app(RoleAssignment::class)->isReservedName($name))->toBeTrue();
})->with(['süper-admin', 'SUPER-ADMIN', ' super-admin ', "super\u{200B}-admin", 'ａｄｍｉｎ', 'super - admin']);

it('stops the rescue naming a look-alike role instead of reporting a SuperAdmin it did not make', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    RoleModel::query()->where('name', 'super-admin')->delete();
    $lookAlike = RoleModel::create(['name' => 'süper-admin', 'guard_name' => 'web']);
    // What MariaDB returns for the exact name: the look-alike row (its collation equates the two).
    RoleModel::retrieved(function (RoleModel $role) use ($lookAlike): void {
        if ($role->getKey() === $lookAlike->getKey()) {
            $role->setRawAttributes(['name' => 'süper-admin'] + $role->getAttributes(), sync: true);
        }
    });
    try {
        RoleModel::query()->where('id', $lookAlike->id)->update(['name' => 'super-admin']);
        $this->artisan('hdid:make-admin', ['email' => 'rescue@corp.hu', '--password' => 'a-long-password-1'])
            ->expectsOutputToContain('süper-admin')
            ->assertFailed();
    } finally {
        RoleModel::flushEventListeners();
    }

    expect(User::query()->where('email', 'rescue@corp.hu')->exists())->toBeFalse();
});
