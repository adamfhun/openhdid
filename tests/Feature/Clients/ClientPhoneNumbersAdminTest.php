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

/**
 * The manual sends the operator to the client page to add a number; the
 * tab must not be read-only there. Agents still only read it.
 */
it('lets client managers add a phone number on the client page itself', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());

    Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
        ->assertActionHidden(TestAction::make('create')->table())
        ->assertActionHidden(TestAction::make('delete')->table($this->second));

    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
        ->assertActionVisible(TestAction::make('create')->table())
        ->callAction(TestAction::make('create')->table(), ['number_e164' => '06 30 333 3333', 'label' => 'iroda'])
        ->assertHasNoFormErrors();

    expect($this->client->phoneNumbers()->where('number_e164', '+36303333333')->where('source', PhoneNumberSource::Admin)->whereNotNull('verified_at')->exists())->toBeTrue();
});
