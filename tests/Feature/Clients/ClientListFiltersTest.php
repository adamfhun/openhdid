<?php

use App\Auth\Role;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * A filter value that names no level (a forged link, a stale session) is
 * ignored: the list shows every record instead of failing.
 */

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    app(Settings::class)->set(SettingKey::PackagesStandard, ['Basic']);
    $this->premium = Client::factory()->synced()->create(['name' => 'Prémium Piroska']);
    $this->standard = Client::factory()->synced()->create(['name' => 'Normál Nándor', 'explicit_package' => null, 'implicit_package' => 'Basic']);
});

it('ignores an unknown level filter value instead of failing the client list', function (): void {
    Livewire::test(ListClients::class, ['tableFilters' => ['tier' => ['value' => 'gold']]])
        ->assertOk()
        ->assertCanSeeTableRecords([$this->premium, $this->standard]);
});

it('opens the client list from a link that carries a forged filter value', function (): void {
    $this->get(ClientResource::getUrl('index').'?filters[tier][value]=gold')->assertOk();
});

it('opens the client list again after a forged level value was stored in the session filter state', function (): void {
    session()->put(Livewire::test(ListClients::class)->instance()->getTableFiltersSessionKey(), ['tier' => ['value' => 'gold']]);

    Livewire::test(ListClients::class)->assertOk()->assertCanSeeTableRecords([$this->premium, $this->standard]);
});

it('still filters by a real level', function (): void {
    Livewire::test(ListClients::class)->filterTable('tier', 'standard')
        ->assertCanSeeTableRecords([$this->standard])->assertCanNotSeeTableRecords([$this->premium]);
});
