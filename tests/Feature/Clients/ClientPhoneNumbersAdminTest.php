<?php

use App\Auth\Role;
use App\Enums\PhoneNumberSource;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Clients\RelationManagers\PhoneNumbersRelationManager;
use App\Models\Client;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->client = Client::factory()->synced()->create();
    $this->first = $this->client->phoneNumbers()->create(['number_e164' => '+36301111111', 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);
    $this->second = $this->client->phoneNumbers()->create(['number_e164' => '+36302222222', 'source' => PhoneNumberSource::Admin, 'is_primary' => false]);
});

/**
 * The primary number receives the login code and the PIN text message, so
 * changing it needs the client management permission, not just read access.
 */
it('lets only client managers make a phone number primary', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());

    Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
        ->assertActionHidden(TestAction::make('makePrimary')->table($this->second));

    expect($this->second->fresh()->is_primary)->toBeFalse();

    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
        ->callAction(TestAction::make('makePrimary')->table($this->second));

    expect($this->second->fresh()->is_primary)->toBeTrue();
});
