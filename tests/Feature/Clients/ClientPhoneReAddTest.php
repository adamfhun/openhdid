<?php

use App\Auth\Role;
use App\Enums\PhoneNumberSource;
use App\Enums\PhoneVerificationSource;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Clients\RelationManagers\PhoneNumbersRelationManager;
use App\Models\Client;
use App\Models\ClientPhoneNumber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/**
 * A removed number is soft-deleted and keeps its row; the unique index on
 * (client, number) counts that row too. Taking the same number up again, on
 * the panel or on the portal, must bring that row back instead of failing.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('lets staff add a number again that was removed from the client earlier', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    $client = Client::factory()->synced()->create();
    $old = $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::ClientSelf, 'label' => 'régi', 'is_primary' => true]);
    $old->delete();

    Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->callAction(TestAction::make('create')->table(), ['number_e164' => '06 30 123 4567', 'label' => 'mobil'])
        ->assertHasNoFormErrors();

    $phone = $client->phoneNumbers()->sole();
    expect($phone->id)->toBe($old->id, 'the removed row comes back, no second row is made')
        ->and($phone->label)->toBe('mobil')
        ->and($phone->source)->toBe(PhoneNumberSource::Admin)
        ->and($phone->verified_via)->toBe(PhoneVerificationSource::Admin)
        ->and($phone->verified_by_user_id)->toBe($admin->id)
        ->and(ClientPhoneNumber::withTrashed()->where('client_id', $client->id)->count())->toBe(1);
});

it('lets staff change a number to one that was removed from the client earlier', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');
    $client = Client::factory()->synced()->create();
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin])->delete();
    $current = $client->phoneNumbers()->create(['number_e164' => '+36307654321', 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);

    Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->callAction(TestAction::make('edit')->table($current), ['number_e164' => '+36301234567'])
        ->assertHasNoFormErrors();

    expect($client->phoneNumbers()->pluck('number_e164')->all())->toBe(['+36301234567'])
        ->and(ClientPhoneNumber::withTrashed()->where('client_id', $client->id)->where('number_e164', '+36301234567')->count())->toBe(1);
});

it('lets the client add a number on the portal again after removing it, to be confirmed again', function (): void {
    $client = Client::factory()->synced()->create();
    Sanctum::actingAs($client, guard: 'client');
    $this->withHeader('Referer', config('app.url'));

    $this->postJson('/api/v1/client/phone-numbers', ['number' => '+36301234567', 'label' => 'mobil'])->assertSuccessful();
    $phone = $client->phoneNumbers()->sole();
    $phone->forceFill(['verified_at' => now(), 'verified_via' => PhoneVerificationSource::Sms])->save();

    $this->deleteJson('/api/v1/client/phone-numbers/'.$phone->id)->assertSuccessful();
    expect($client->phoneNumbers()->count())->toBe(0);

    $this->postJson('/api/v1/client/phone-numbers', ['number' => '06 30 123 4567', 'label' => 'újra'])->assertSuccessful();

    $again = $client->phoneNumbers()->sole();
    expect($again->number_e164)->toBe('+36301234567')
        ->and($again->label)->toBe('újra')
        ->and($again->isVerified())->toBeFalse()
        ->and($again->is_primary)->toBeTrue();

    // And once more after a removal by staff.
    $again->delete();
    $this->postJson('/api/v1/client/phone-numbers', ['number' => '+36301234567'])->assertSuccessful();
    expect($client->phoneNumbers()->count())->toBe(1);
});
