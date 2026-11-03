<?php

use App\Auth\Role;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * Deleting a staff account signs it out everywhere and closes it with the
 * administrator's reason, so a restore brings back neither an old session
 * nor a remember-me cookie, and a returning EMD record does not reopen it.
 */

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($this->admin);
});

it('signs a deleted staff member out everywhere, and the restored account stays closed', function (string $from): void {
    $staff = User::factory()->synced()->withRole(Role::Agent)->create(['remember_token' => 'old-token']);
    $epoch = (int) $staff->session_epoch;

    if ($from === 'list') {
        Livewire::test(ListUsers::class)->callAction(TestAction::make('delete')->table($staff));
    } else {
        Livewire::test(EditUser::class, ['record' => $staff->id])->callAction('delete');
    }

    $deleted = User::withTrashed()->find($staff->id);
    expect($deleted->trashed())->toBeTrue()
        ->and($deleted->closed_reason)->toBe('admin')
        ->and((int) $deleted->session_epoch)->toBe($epoch + 1)
        ->and($deleted->remember_token)->not->toBe('old-token');

    Livewire::test(ListUsers::class)->filterTable('trashed', true)->callAction(TestAction::make('restore')->table($deleted));
    expect($staff->fresh()->trashed())->toBeFalse()
        ->and($staff->fresh()->isClosed())->toBeTrue();
})->with(['list', 'edit page']);

it('keeps a staff member that the sync closed closed by the administrator after deleting and restoring it', function (): void {
    $staff = User::factory()->synced()->withRole(Role::Agent)->closed('sync.missing')->create();

    Livewire::test(ListUsers::class)->callAction(TestAction::make('delete')->table($staff));

    expect(User::withTrashed()->find($staff->id)->closed_reason)->toBe('admin')
        ->and(AuditLog::query()->where('event', 'account.close_reason_changed')->sole()->context)->toMatchArray(['previous_reason' => 'sync.missing', 'via' => 'delete']);
});

it('closes and signs out the staff member whatever path deletes the row', function (): void {
    $staff = User::factory()->synced()->withRole(Role::Agent)->create(['remember_token' => 'old-token']);

    $staff->delete();

    $deleted = User::withTrashed()->find($staff->id);
    expect($deleted->closed_reason)->toBe('admin')
        ->and($deleted->remember_token)->not->toBe('old-token');
});
