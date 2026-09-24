<?php

use App\Auth\Role;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    $this->superAdminRoleId = RoleModel::findByName(Role::SuperAdmin->value, 'web')->getKey();
    $this->agentRoleId = RoleModel::findByName(Role::Agent->value, 'web')->getKey();
});

it('refuses an admin promoting themselves to super-admin on the user edit page', function (): void {
    $page = Livewire::test(EditUser::class, ['record' => $this->admin->id])
        ->fillForm(['roles' => [$this->superAdminRoleId]])
        ->call('save');

    expect($this->admin->fresh()->hasRole(Role::SuperAdmin->value))->toBeFalse()
        ->and($this->admin->fresh()->hasRole(Role::Admin->value))->toBeTrue();
    $page->assertHasFormErrors(['roles']);

    $entry = AuditLog::query()->where('event', 'user.role_escalation_rejected')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->actor_id)->toBe($this->admin->id)
        ->and($entry->subject_id)->toBe($this->admin->id)
        ->and($entry->context['rejected'])->toBe([Role::SuperAdmin->value]);
});

it('refuses an admin granting super-admin to another user', function (): void {
    $agent = User::factory()->withRole(Role::Agent)->create();

    $page = Livewire::test(EditUser::class, ['record' => $agent->id])
        ->fillForm(['roles' => [$this->superAdminRoleId]])
        ->call('save');

    expect($agent->fresh()->hasRole(Role::SuperAdmin->value))->toBeFalse()
        ->and($agent->fresh()->hasRole(Role::Agent->value))->toBeTrue();
    $page->assertHasFormErrors(['roles']);
});

it('refuses an admin creating a new super-admin user', function (): void {
    $page = Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Új Főrendszergazda',
            'email' => 'new-super@example.test',
            'password' => 'very-long-password-123',
            'roles' => [$this->superAdminRoleId],
        ])
        ->call('create');

    expect(User::query()->where('email', 'new-super@example.test')->exists())->toBeFalse();
    $page->assertHasFormErrors(['roles']);
    expect(AuditLog::query()->where('event', 'user.role_escalation_rejected')->count())->toBe(1);
});

it('still lets an admin grant the other roles', function (): void {
    $user = User::factory()->withRole(Role::Supervisor)->create();

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm(['roles' => [$this->agentRoleId]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->hasRole(Role::Agent->value))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'user.role_escalation_rejected')->count())->toBe(0);
});

it('lets a super-admin grant the super-admin role', function (): void {
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create());
    $agent = User::factory()->withRole(Role::Agent)->create();

    Livewire::test(EditUser::class, ['record' => $agent->id])
        ->fillForm(['roles' => [$this->superAdminRoleId]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($agent->fresh()->hasRole(Role::SuperAdmin->value))->toBeTrue();
});

it('keeps an admin off the edit page of a super-admin so the password cannot be taken over', function (): void {
    $super = User::factory()->withRole(Role::SuperAdmin)->create(['password' => 'original-password-1']);

    Livewire::test(EditUser::class, ['record' => $super->id])->assertForbidden();

    expect(Hash::check('original-password-1', $super->fresh()->password))->toBeTrue()
        ->and($this->admin->can('update', $super))->toBeFalse()
        ->and($this->admin->can('delete', $super))->toBeFalse()
        ->and($this->admin->can('restore', $super))->toBeFalse();
});

it('keeps an admin from closing a super-admin account from the users list', function (): void {
    $super = User::factory()->withRole(Role::SuperAdmin)->create();

    // A hand-crafted Livewire request skips the visibility check of callTableAction.
    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('close', $super)
        ->mountTableAction('close', $super)
        ->callMountedTableAction();

    expect($super->fresh()->isClosed())->toBeFalse();
});

it('lets an admin close an ordinary account from the users list', function (): void {
    $agent = User::factory()->withRole(Role::Agent)->create();

    Livewire::test(ListUsers::class)
        ->assertTableActionVisible('close', $agent)
        ->callTableAction('close', $agent);

    expect($agent->fresh()->isClosed())->toBeTrue();
});

it('does not let a self-promoted admin reach the super-admin-only export payload', function (): void {
    Livewire::test(EditUser::class, ['record' => $this->admin->id])
        ->fillForm(['roles' => [$this->superAdminRoleId]])
        ->call('save');

    $this->actingAs($this->admin->fresh());

    expect(fn () => app(Settings::class)->set(SettingKey::SyncExportPayload, '{"ids":"escalated"}'))
        ->toThrow(HttpException::class);
});
