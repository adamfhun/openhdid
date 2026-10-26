<?php

use App\Auth\Permission;
use App\Auth\Role;
use App\Auth\RoleAssignment;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Text;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who may hand out which role (decision of 2026-10-25): only a role whose
 * every permission the actor holds; the SuperAdmin role only a SuperAdmin.
 * A held role the actor could not grant is neither lost nor swapped out.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->adminRole = RoleModel::findByName(Role::Admin->value, 'web');
    $this->agentRole = RoleModel::findByName(Role::Agent->value, 'web');
    $this->superRole = RoleModel::findByName(Role::SuperAdmin->value, 'web');
});

/**
 * A custom role holding only the given permissions.
 *
 * @param  list<Permission>  $permissions
 */
function grantSubsetRole(string $name, array $permissions): RoleModel
{
    $role = RoleModel::create(['name' => $name, 'guard_name' => 'web']);
    $role->givePermissionTo(array_map(fn (Permission $permission): string => $permission->value, $permissions));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $role;
}

/**
 * A staff member whose only role is a custom one with users.manage (and the
 * panel), but nothing else: the "user keeper" of the review finding.
 */
function grantSubsetUserKeeper(): User
{
    grantSubsetRole('User keeper', [Permission::AdminAccess, Permission::UsersManage]);

    return tap(User::factory()->create())->assignRole('User keeper');
}

/**
 * The help line under a form field (its below-content text).
 */
function grantSubsetFieldHelp(Field $field): string
{
    return collect($field->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->getComponents() ?? [])
        ->map(fn ($component): string => $component instanceof Text ? (string) $component->getContent() : '')
        ->implode(' ');
}

it('refuses a user keeper granting themselves the admin role', function (): void {
    $keeper = grantSubsetUserKeeper();
    $this->actingAs($keeper);
    $own = RoleModel::findByName('User keeper', 'web');

    Livewire::test(EditUser::class, ['record' => $keeper->id])
        ->fillForm(['roles' => [$own->getKey(), $this->adminRole->getKey()]])
        ->call('save')
        ->assertHasFormErrors(['roles']);

    expect($keeper->fresh()->hasRole(Role::Admin->value))->toBeFalse()
        ->and($keeper->fresh()->hasRole('User keeper'))->toBeTrue();

    $entry = AuditLog::query()->where('event', 'user.role_escalation_rejected')->sole();
    expect($entry->actor_id)->toBe($keeper->id)
        ->and($entry->subject_id)->toBe($keeper->id)
        ->and($entry->context['rejected'])->toBe([Role::Admin->value])
        ->and($entry->context['missing'][Role::Admin->value])->toContain(Permission::SettingsManage->value)
        ->and($entry->context['missing'][Role::Admin->value])->not->toContain(Permission::UsersManage->value);
});

it('refuses a user keeper granting the admin role to someone else, also on a new account', function (): void {
    $this->actingAs(grantSubsetUserKeeper());
    $target = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $target->id])
        ->fillForm(['roles' => [$this->adminRole->getKey()]])
        ->call('save')
        ->assertHasFormErrors(['roles']);

    expect($target->fresh()->roles)->toHaveCount(0);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Új rendszergazda',
            'email' => 'new-admin@example.test',
            'password' => 'very-long-password-123',
            'roles' => [$this->adminRole->getKey()],
        ])
        ->call('create')
        ->assertHasFormErrors(['roles']);

    expect(User::query()->where('email', 'new-admin@example.test')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'user.role_escalation_rejected')->count())->toBe(2);
});

it('lets a user keeper grant a role within their own permissions, to themselves too', function (): void {
    $keeper = grantSubsetUserKeeper();
    $this->actingAs($keeper);
    $helper = grantSubsetRole('Panel helper', [Permission::AdminAccess]);
    $own = RoleModel::findByName('User keeper', 'web');
    $target = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $target->id])
        ->fillForm(['roles' => [$helper->getKey()]])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(EditUser::class, ['record' => $keeper->id])
        ->fillForm(['roles' => [$own->getKey(), $helper->getKey()]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->hasRole('Panel helper'))->toBeTrue()
        ->and($keeper->fresh()->hasRole('Panel helper'))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'user.role_escalation_rejected')->count())->toBe(0);
});

it('greys out the roles a user keeper cannot grant, with the reason', function (): void {
    $this->actingAs(grantSubsetUserKeeper());
    $helper = grantSubsetRole('Panel helper', [Permission::AdminAccess]);
    $own = RoleModel::findByName('User keeper', 'web');

    Livewire::test(CreateUser::class)
        ->assertFormFieldExists('roles', function (Select $field) use ($helper, $own): bool {
            $enabled = array_map('strval', array_keys($field->getEnabledOptions()));
            sort($enabled);
            $expected = [(string) $helper->getKey(), (string) $own->getKey()];
            sort($expected);
            $help = grantSubsetFieldHelp($field);

            return $enabled === $expected
                && str_contains($help, __('You can only grant or remove a role whose every permission you hold, and only a SuperAdmin can grant the SuperAdmin role.'))
                && str_contains($help, __(':role (missing permissions: :count)', ['role' => Role::Admin->value, 'count' => count(Role::Admin->permissions()) - 2]));
        });
});

it('keeps an account holding a role the user keeper cannot grant out of their reach, and the role on it', function (): void {
    $keeper = grantSubsetUserKeeper();
    $this->actingAs($keeper);
    $helper = grantSubsetRole('Panel helper', [Permission::AdminAccess]);
    $target = User::factory()->withRole(Role::Agent)->create(['name' => 'Régi név']);

    // Since 2026-10-26 the whole account is out of reach (RoleAssignment::canManage)...
    Livewire::test(EditUser::class, ['record' => $target->id])->assertForbidden();

    // ...and the service still refuses swapping the held role out, whoever calls it.
    expect(fn () => app(RoleAssignment::class)->assertAssignable($keeper, [$helper->name], $target))
        ->toThrow(AuthorizationException::class);

    expect($target->fresh()->name)->toBe('Régi név')
        ->and($target->fresh()->hasRole(Role::Agent->value))->toBeTrue();

    $entry = AuditLog::query()->where('event', 'user.role_escalation_rejected')->sole();
    expect($entry->context['rejected'])->toBe([Role::Agent->value])
        ->and($entry->context['not_removable'])->toBe([Role::Agent->value]);
});

it('lets an admin grant every role within their permissions but not the super-admin role', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin);
    $everything = grantSubsetRole('Everything but the name', Permission::cases());
    $target = User::factory()->withRole(Role::Agent)->create();
    $roles = app(RoleAssignment::class);

    expect($roles->canGrant($admin, Role::Admin->value))->toBeTrue()
        ->and($roles->canGrant($admin, Role::Supervisor->value))->toBeTrue()
        ->and($roles->canGrant($admin, $everything))->toBeTrue()
        ->and($roles->canGrant($admin, Role::SuperAdmin->value))->toBeFalse();

    Livewire::test(EditUser::class, ['record' => $target->id])
        ->fillForm(['roles' => [$this->adminRole->getKey(), $everything->getKey()]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->hasRole(Role::Admin->value))->toBeTrue()
        ->and($target->fresh()->hasRole('Everything but the name'))->toBeTrue();
});

it('lets a super-admin grant every role, even with the role emptied of permissions', function (): void {
    $super = User::factory()->withRole(Role::SuperAdmin)->create();
    $this->superRole->syncPermissions([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($super->fresh());
    $roles = app(RoleAssignment::class);

    foreach (RoleModel::query()->pluck('name') as $name) {
        expect($roles->canGrant($super->fresh(), $name))->toBeTrue();
    }
});

it('names the role that cannot be granted and the permissions it lacks in the refusal', function (): void {
    $keeper = grantSubsetUserKeeper();
    $this->actingAs($keeper);
    // Listed in the order of the Permission enum.
    $lacking = array_filter(Permission::cases(), fn (Permission $permission): bool => in_array($permission, Role::Supervisor->permissions(), true));

    expect(fn () => app(RoleAssignment::class)->assertAssignable($keeper, [Role::Supervisor->value], User::factory()->create()))
        ->toThrow(__('You cannot grant the role :role: you do not hold these of its permissions: :permissions.', [
            'role' => Role::Supervisor->value,
            'permissions' => implode(', ', array_map(fn (Permission $permission): string => $permission->label(), $lacking)),
        ]));
});

it('records who gave and took which role on the Users page', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin);
    $target = User::factory()->withRole(Role::Agent)->create();
    $supervisor = RoleModel::findByName(Role::Supervisor->value, 'web');

    Livewire::test(EditUser::class, ['record' => $target->id])
        ->fillForm(['roles' => [$supervisor->getKey()]])
        ->call('save')
        ->assertHasNoFormErrors();

    $entry = AuditLog::query()->where('event', 'user.roles_changed')->sole();
    expect($entry->subject_id)->toBe($target->id)
        ->and($entry->actor_id)->toBe($admin->id)
        ->and($entry->context)->toMatchArray(['added' => [Role::Supervisor->value], 'removed' => [Role::Agent->value]]);

    // A save that leaves the roles as they were writes nothing.
    Livewire::test(EditUser::class, ['record' => $target->id])->fillForm(['name' => 'New Name'])->call('save')->assertHasNoFormErrors();
    expect(AuditLog::query()->where('event', 'user.roles_changed')->count())->toBe(1);
});

it('refuses a save over a form loaded before the target got a role the actor cannot grant', function (): void {
    $keeper = grantSubsetUserKeeper();
    $this->actingAs($keeper);
    $target = User::factory()->create();
    $page = Livewire::test(EditUser::class, ['record' => $target->id]);

    // Meanwhile a SuperAdmin made the target an admin; the stale form does not list it.
    $target->assignRole(Role::Admin->value);

    // The open page is refused on its next request (RoleAssignment::canManage).
    $page->set('data.roles', [])->assertForbidden();
    expect($target->fresh()->hasRole(Role::Admin->value))->toBeTrue();
});

it('refuses odd spellings of a role id the actor cannot grant', function (string $spelling): void {
    $keeper = grantSubsetUserKeeper();
    $this->actingAs($keeper);
    $own = RoleModel::findByName('User keeper', 'web');
    $id = $this->adminRole->getKey();

    Livewire::test(EditUser::class, ['record' => $keeper->id])
        ->set('data.roles', [(string) $own->getKey(), str_replace('{id}', (string) $id, $spelling)])
        ->call('save')
        ->assertHasFormErrors(['roles']);

    expect($keeper->fresh()->hasRole(Role::Admin->value))->toBeFalse();
})->with(['leading zero' => ['0{id}'], 'exponent' => ['{id}e0'], 'leading space' => [' {id}']]);
