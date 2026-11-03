<?php

use App\Auth\Role;
use App\Enums\ApiKeyScope;
use App\Filament\Admin\Resources\ApiKeys\Pages\ManageApiKeys;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\User;
use App\Support\HuDate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

/*
 * A dangerous confirmation names what it acts on (G21c): the account, the
 * client, the key. And no action opens from the address of a panel page
 * (?action=, ?tableAction=; owner decision Q13), so a link cannot lead
 * someone into a prepared confirmation.
 */

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create());
});

it('names the account and the end of its lock in the unlock confirmation', function (): void {
    $peer = User::factory()->withRole(Role::Agent)->create(['name' => 'Zárolt Zoltán', 'locked_until' => now()->addHour()]);
    $client = Client::factory()->synced()->create(['name' => 'Zárolt Zsófia', 'locked_until' => now()->addHour()]);
    $names = fn (Model $record) => fn (Action $action): bool => str_contains((string) $action->getModalHeading(), $record->name)
        && str_contains((string) $action->getModalDescription(), $record->email)
        && str_contains((string) $action->getModalDescription(), $record->locked_until->format(HuDate::DATETIME));

    Livewire::test(ListUsers::class)->assertActionExists(TestAction::make('unlockLogin')->table($peer), $names($peer));
    Livewire::test(ListClients::class)->assertActionExists(TestAction::make('unlockLogin')->table($client), $names($client));
});

it('names the record in every dangerous confirmation of the account and key lists', function (string $case): void {
    $user = User::factory()->withRole(Role::Agent)->create(['name' => 'Törlendő Tamás']);
    $client = Client::factory()->synced()->create(['name' => 'Törlendő Tünde']);
    ['key' => $key] = ApiKey::generate('Partner kulcs', ApiKeyScope::CallCenter);

    [$page, $action, $name] = match ($case) {
        'delete staff' => [ListUsers::class, TestAction::make('delete')->table($user), 'Törlendő Tamás'],
        'delete client' => [ListClients::class, TestAction::make('delete')->table($client), 'Törlendő Tünde'],
        'revoke key' => [ManageApiKeys::class, TestAction::make('revoke')->table($key), 'Partner kulcs'],
        'rotate key secret' => [ManageApiKeys::class, TestAction::make('rotateSecret')->table($key), 'Partner kulcs'],
    };

    Livewire::test($page)->assertActionExists($action, fn (Action $action): bool => str_contains((string) $action->getModalHeading().' '.$action->getModalDescription(), $name));
})->with(['delete staff', 'delete client', 'revoke key', 'rotate key secret']);

it('opens no action from the address of a panel page', function (): void {
    $peer = User::factory()->withRole(Role::Agent)->create(['locked_until' => now()->addHour()]);

    $page = Livewire::withQueryParams(['tableAction' => 'unlockLogin', 'tableActionRecord' => $peer->id])->test(ListUsers::class)
        ->call('loadTable')
        ->call('mountDefaultActions');

    expect($page->get('mountedActions'))->toBeEmpty()
        ->and($peer->fresh()->isLoginLocked())->toBeTrue();
});
