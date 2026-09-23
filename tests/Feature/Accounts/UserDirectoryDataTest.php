<?php

use App\Auth\Role;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('shows the directory identifier, job title and department of a synced staff user, read-only', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');

    $user = User::factory()->synced()->withRole(Role::Agent)->create(['name' => 'Szinkron Szilvia']);
    $user->externalRecord->update(['external_id' => 4242, 'title' => 'Csoportvezető', 'department' => 'Prémium vonal']);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->assertSee('4242')->assertSee('Csoportvezető')->assertSee('Prémium vonal');

    Livewire::test(ListUsers::class)
        ->assertTableColumnExists('job_title')
        ->assertCanSeeTableRecords([$user])
        ->assertSee('Csoportvezető');
});

it('shows populated optional directory fields and keeps them read-only while hiding empty ones', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');
    $user = User::factory()->synced()->withRole(Role::Agent)->create();
    $user->externalRecord->update(['login_name' => 'szilvia.login', 'room' => 'B-204', 'employment_status' => 'Aktív jogviszony']);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->assertSee('szilvia.login')->assertSee('B-204')->assertSee('Aktív jogviszony')
        ->set('data.directory_login_name', 'tampered')->call('save')->assertHasNoFormErrors();
    expect($user->externalRecord->fresh()->login_name)->toBe('szilvia.login');

    $user->externalRecord->update(['login_name' => null, 'room' => null, 'employment_status' => null]);
    Livewire::test(EditUser::class, ['record' => $user->id])
        ->assertDontSee(__('Login name'))->assertDontSee(__('Room'))->assertDontSee(__('Employment status'));
});
