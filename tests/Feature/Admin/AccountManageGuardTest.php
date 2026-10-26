<?php

use App\Auth\Permission;
use App\Auth\Role;
use App\Auth\RoleAssignment;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Taking over a stronger account (decision of 2026-10-26): a staff member
 * may change, unlock, reset, unbind, close or delete only an account whose
 * every role they could grant themselves; a SuperAdmin may do all of it.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $keeperRole = RoleModel::create(['name' => 'User keeper', 'guard_name' => 'web']);
    $keeperRole->givePermissionTo([Permission::AdminAccess->value, Permission::UsersManage->value]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->keeper = tap(User::factory()->create())->assignRole('User keeper');
    $this->admin = User::factory()->withRole(Role::Admin)->create([
        'email' => 'admin@example.test',
        'password' => Hash::make('the-admins-own-password'),
    ]);
    $this->admin->forceFill([
        'mfa_email_enabled_at' => now(),
        'locked_until' => now()->addHour(),
        'mfa_locked_until' => now()->addHour(),
    ])->save();
});

it('refuses a narrower user keeper the edit page of an admin, and records it', function (): void {
    $this->actingAs($this->keeper);

    Livewire::test(EditUser::class, ['record' => $this->admin->id])->assertForbidden();

    $entry = AuditLog::query()->where('event', 'user.manage_rejected')->sole();
    expect($entry->actor_id)->toBe($this->keeper->id)
        ->and($entry->subject_id)->toBe($this->admin->id)
        ->and($entry->context['action'])->toBe('edit')
        ->and($entry->context['roles'])->toBe([Role::Admin->value]);
});

it('keeps the e-mail and the password of an account from a page opened before it became an admin', function (): void {
    $this->actingAs($this->keeper);
    $target = User::factory()->create(['email' => 'target@example.test', 'password' => Hash::make('the-targets-own-password')]);
    $before = $target->password;
    $page = Livewire::test(EditUser::class, ['record' => $target->id]);

    // Meanwhile a SuperAdmin made the account an admin; the open page is refused on its next request.
    $target->assignRole(Role::Admin->value);

    $page->set('data.email', 'keeper-owned@example.test')->assertForbidden();

    expect($target->fresh()->email)->toBe('target@example.test')
        ->and($target->fresh()->password)->toBe($before)
        ->and(AuditLog::query()->where('event', 'user.manage_rejected')->where('subject_id', $target->id)->exists())->toBeTrue();
});

it('greys out every account action on an admin row for a narrower user keeper, with the reason', function (string $action): void {
    $this->actingAs($this->keeper);
    $reason = app(RoleAssignment::class)->manageRefusal($this->keeper, $this->admin);

    expect($reason)->toContain(Role::Admin->value);

    Livewire::test(ListUsers::class)
        ->assertActionVisible(TestAction::make($action)->table($this->admin))
        ->assertActionDisabled(TestAction::make($action)->table($this->admin))
        ->assertActionExists(TestAction::make($action)->table($this->admin), fn (Action $action): bool => $action->getTooltip() === $reason);
})->with(['edit', 'close', 'resetMfa', 'unlockLogin', 'delete']);

it('refuses and records the direct Livewire calls of a narrower user keeper on an admin row', function (): void {
    $this->actingAs($this->keeper);
    $page = Livewire::test(ListUsers::class);

    foreach (['resetMfa', 'unlockLogin', 'close', 'delete'] as $action) {
        $page->call('mountAction', $action, [], ['table' => true, 'recordKey' => $this->admin->getKey()]);
        $page->call('callMountedAction');
    }

    $admin = $this->admin->fresh();
    expect($admin->mfa_email_enabled_at)->not->toBeNull()
        ->and($admin->isLoginLocked())->toBeTrue()
        ->and($admin->mfa_locked_until->isFuture())->toBeTrue()
        ->and($admin->isClosed())->toBeFalse()
        ->and($admin->trashed())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'user.manage_rejected')->pluck('context')->pluck('action')->all())
        ->toBe(['resetMfa', 'unlockLogin', 'close', 'delete']);
});

it('lets the user keeper manage an account on their own level', function (): void {
    $this->actingAs($this->keeper);
    $peer = User::factory()->create(['email' => 'peer@example.test']);
    $peer->assignRole('User keeper');
    $peer->forceFill(['mfa_email_enabled_at' => now(), 'locked_until' => now()->addHour()])->save();

    expect(app(RoleAssignment::class)->canManage($this->keeper, $peer))->toBeTrue();

    Livewire::test(ListUsers::class)
        ->assertActionEnabled(TestAction::make('resetMfa')->table($peer))
        ->callAction(TestAction::make('resetMfa')->table($peer))
        ->callAction(TestAction::make('unlockLogin')->table($peer));

    Livewire::test(EditUser::class, ['record' => $peer->id])
        ->fillForm(['email' => 'peer-new@example.test', 'password' => 'a-brand-new-password-1'])
        ->mountAction('save')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $peer->refresh();
    expect($peer->mfa_email_enabled_at)->toBeNull()
        ->and($peer->isLoginLocked())->toBeFalse()
        ->and($peer->email)->toBe('peer-new@example.test')
        ->and(Hash::check('a-brand-new-password-1', $peer->password))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'user.manage_rejected')->exists())->toBeFalse();

    Livewire::test(ListUsers::class)->callAction(TestAction::make('close')->table($peer));
    expect($peer->fresh()->isClosed())->toBeTrue();
});

it('lets a super-admin manage an admin account', function (): void {
    $super = User::factory()->withRole(Role::SuperAdmin)->create();
    $this->actingAs($super);

    Livewire::test(ListUsers::class)->callAction(TestAction::make('resetMfa')->table($this->admin));

    expect($this->admin->fresh()->mfa_email_enabled_at)->toBeNull()
        ->and(app(RoleAssignment::class)->manageRefusal($super, $this->admin))->toBeNull();
});
