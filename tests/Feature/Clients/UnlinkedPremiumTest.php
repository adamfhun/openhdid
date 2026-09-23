<?php

use App\Auth\Role;
use App\Clients\ClientLinks;
use App\Clients\PackageOverrides;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

it('collects explicit-premium clients without a sponsor on one tab, a package override does not count', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $sponsor = Client::factory()->synced()->create(['name' => 'Fő Ügyfél', 'implicit_package' => 'Premium', 'explicit_package' => null]);
    $linked = Client::factory()->synced()->create(['name' => 'Kapcsolt Kata', 'implicit_package' => 'Basic', 'explicit_package' => 'Premium']);
    $waiting = Client::factory()->synced()->create(['name' => 'Váró Vilmos', 'implicit_package' => 'Basic', 'explicit_package' => 'Premium']);
    $overridden = Client::factory()->synced()->create(['name' => 'Felülírt Ferenc', 'implicit_package' => 'Basic', 'explicit_package' => null]);
    $closed = Client::factory()->synced()->create(['name' => 'Zárt Zoltán', 'implicit_package' => 'Basic', 'explicit_package' => 'Premium']);

    app(ClientLinks::class)->link($sponsor, $linked);
    app(PackageOverrides::class)->set($overridden, 'Premium', 'próba', now()->addDays(10), User::factory()->withRole(Role::Admin)->create());
    $closed->close('manual');

    $ids = ClientResource::scopeUnlinkedPremium(Client::query()->open())->pluck('id')->all();

    expect($ids)->toContain($waiting->id)
        ->and($ids)->not->toContain($sponsor->id, $linked->id, $closed->id, $overridden->id)
        ->and(ClientResource::unlinkedPremiumCount())->toBe(1)
        ->and(ClientResource::getNavigationBadge())->toBe('1')
        ->and(ClientResource::linkWarning($overridden))->toBeNull('an override has its own reason, it never asks for a sponsor link')
        ->and(ClientResource::linkWarning($waiting))->not->toBeNull();

    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    Livewire::test(ListClients::class, ['activeTab' => 'unlinked'])
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$sponsor, $linked, $closed, $overridden]);
});

it('hides the menu badge when the setting is off but keeps the list tab counter', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Client::factory()->synced()->create(['implicit_package' => 'Basic', 'explicit_package' => 'Premium']);
    Cache::forget('clients:unlinked-premium-count');

    expect(ClientResource::getNavigationBadge())->toBe('1');

    app(Settings::class)->set(SettingKey::ClientsUnlinkedBadge, false);

    expect(ClientResource::getNavigationBadge())->toBeNull()
        ->and(ClientResource::unlinkedPremiumCount())->toBe(1);
});

it('links a dependent client to a sponsor from its own record and keeps the override button visible', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    $sponsor = Client::factory()->synced()->create(['name' => 'Fő Ügyfél', 'implicit_package' => 'Premium', 'explicit_package' => null]);
    $waiting = Client::factory()->synced()->create(['name' => 'Váró Vilmos', 'implicit_package' => 'Basic', 'explicit_package' => 'Premium']);

    Livewire::test(ViewClient::class, ['record' => $waiting->getKey()])
        ->assertActionVisible('linkToSponsor')
        ->callAction('linkToSponsor', ['sponsor_id' => $sponsor->id])
        ->assertHasNoActionErrors()
        ->assertActionHidden('linkToSponsor');

    expect($waiting->fresh()->sponsor()?->id)->toBe($sponsor->id);

    // The sponsor is premium by directory: the override button stays, greyed out with the reason.
    Livewire::test(ViewClient::class, ['record' => $sponsor->getKey()])
        ->assertActionHidden('linkToSponsor')
        ->assertActionVisible('overridePackage')
        ->assertActionDisabled('overridePackage');

    // A standard client can still be raised, so the button is live there.
    $basic = Client::factory()->synced()->create(['name' => 'Alap Aladár', 'implicit_package' => 'Basic', 'explicit_package' => null]);
    Livewire::test(ViewClient::class, ['record' => $basic->getKey()])
        ->assertActionEnabled('overridePackage');
});
