<?php

use App\Auth\Role;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Admin Ádám', 'email' => 'admin@corp.hu']);
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');

    $this->szilvia = User::factory()->synced()->withRole(Role::Supervisor)->create(['name' => 'Szinkron Szilvia', 'email' => 'szilvia@corp.hu']);
    $this->szilvia->externalRecord->update(['external_id' => 4242, 'company' => 'Központ Zrt.', 'title' => 'Csoportvezető', 'department' => 'Prémium vonal', 'login_name' => 'szilvi.login']);
    $this->bela = User::factory()->synced()->withRole(Role::Agent)->create(['name' => 'Ügyintéző Béla', 'email' => 'bela@corp.hu']);
    $this->bela->externalRecord->update(['external_id' => 5151, 'title' => 'Ügyintéző', 'department' => 'Normál vonal']);
});

it('finds staff by every field the list shows, word by word', function (string $search, array $expected): void {
    $everyone = ['szilvia' => $this->szilvia, 'bela' => $this->bela, 'admin' => $this->admin];
    $found = array_map(fn (string $key): User => $everyone[$key], $expected);
    $missing = array_values(array_diff_key($everyone, array_flip($expected)));

    Livewire::test(ListUsers::class)->searchTable($search)
        ->assertCanSeeTableRecords($found)
        ->assertCanNotSeeTableRecords($missing);
})->with([
    'job title' => ['csoportvezető', ['szilvia']],
    'department' => ['vonal', ['szilvia', 'bela']],
    'role' => ['supervisor', ['szilvia']],
    'external id' => ['5151', ['bela']],
    'company' => ['Központ', ['szilvia']],
    'login name' => ['szilvi.login', ['szilvia']],
    'e-mail' => ['bela@corp', ['bela']],
    'words over different fields' => ['Szilvia prémium', ['szilvia']],
    'a word nobody carries' => ['Szilvia normál', []],
]);
