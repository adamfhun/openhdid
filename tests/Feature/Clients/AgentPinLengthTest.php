<?php

use App\Auth\Role;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('lets an agent set a pin of any length between six and ten digits', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    Filament::setCurrentPanel('admin');
    $client = Client::factory()->synced()->create();

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->callAction('setPin', ['pin' => '12345', 'send_sms' => false])
        ->assertHasActionErrors(['pin']);

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->callAction('setPin', ['pin' => '123456789', 'send_sms' => false])
        ->assertHasNoActionErrors();

    expect(Hash::check('123456789', $client->fresh()->pin_hash))->toBeTrue();
});

it('uses configured limits for the suggested and manually entered agent pin', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    Filament::setCurrentPanel('admin');
    app(Settings::class)->setMany([
        SettingKey::PinMinLength->value => 11,
        SettingKey::PinMaxLength->value => 12,
    ]);
    $client = Client::factory()->synced()->create();

    $page = Livewire::test(ViewClient::class, ['record' => $client->id])->mountAction('setPin');
    $suggestion = $page->get('mountedActions.0.data.pin');
    expect($suggestion)->toMatch('/^[0-9]{11}$/');

    $page->fillForm(['pin' => '1234567890', 'send_sms' => false])->callMountedAction()->assertHasActionErrors(['pin']);
    expect($client->fresh()->hasPin())->toBeFalse();

    $page->fillForm(['pin' => '012345678901', 'send_sms' => false])->callMountedAction()->assertHasNoActionErrors();
    expect(Hash::check('012345678901', $client->fresh()->pin_hash))->toBeTrue();
});

it('shows a field error when an agent selects an already assigned pin', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    Filament::setCurrentPanel('admin');
    Client::factory()->withPin('123456')->create();
    $client = Client::factory()->synced()->create();

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->callAction('setPin', ['pin' => '123456', 'send_sms' => false])
        ->assertHasActionErrors(['pin']);

    expect($client->fresh()->hasPin())->toBeFalse();
});
