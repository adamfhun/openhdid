<?php

use App\Auth\Role;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\Client;
use App\Models\SsoIdentity;
use App\Models\User;
use App\Support\HuDate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The client page (2026-10-23): the header keeps Identify and Edit, every
 * other button stands next to the data it acts on, and a button that remedies
 * a state (lock, binding) exists only while the state holds. A button next to
 * an entry that is hidden is left out of the page schema altogether, so
 * "hidden" is asserted as "does not exist".
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function entryAction(string $name, string $entry): TestAction
{
    return TestAction::make($name)->schemaComponent($entry);
}

it('keeps only identify and edit in the header and puts every other button next to its data', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $client = Client::factory()->synced()->withPhone()->withPin('123456')->create();

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertActionVisible('identify')
        ->assertActionVisible('edit')
        ->assertActionDoesNotExist('setPin')
        ->assertActionDoesNotExist('sendMagicLink')
        ->assertActionDoesNotExist('unlockLogin')
        ->assertActionDoesNotExist('unbindSso')
        ->assertActionVisible(entryAction('setPin', 'pin_hash'))
        ->assertActionVisible(entryAction('clearPin', 'pin_hash'))
        ->assertActionVisible(entryAction('sendMagicLink', 'last_login_at'))
        ->assertActionVisible(entryAction('close', 'closed_at'))
        ->assertActionDoesNotExist(entryAction('reopen', 'closed_at'))
        ->assertActionDoesNotExist(entryAction('unlockLogin', 'closed_at'))
        ->assertActionVisible(entryAction('editNotes', 'notes'))
        ->assertActionVisible(entryAction('openDirectoryRecord', 'external_id'))
        ->assertActionVisible(entryAction('overridePackage', 'package_override'))
        ->assertActionDoesNotExist(entryAction('unbindSso', 'sso_bindings'));
});

it('shows the sign-in lock on the status badge and offers the unlock only while it lasts', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $client = Client::factory()->synced()->create(['locked_until' => now()->addHour()]);

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertSee(__('Open · login locked until :until', ['until' => $client->locked_until->format(HuDate::DATETIME)]))
        ->assertSee(__('Locked after repeated wrong SMS codes; the lock ends by itself, or an administrator lifts it.'))
        ->callAction(entryAction('unlockLogin', 'closed_at'));

    expect($client->fresh()->isLoginLocked())->toBeFalse();

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertDontSee('login locked until')
        ->assertActionDoesNotExist(entryAction('unlockLogin', 'closed_at'));
});

it('names the PIN lock on the PIN badge only while it lasts', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $client = Client::factory()->synced()->withPhone()->withPin('123456')->create(['pin_locked_until' => now()->addMinutes(30)]);

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertSee(__('PIN locked until :until', ['until' => $client->pin_locked_until->format(HuDate::DATETIME)]))
        ->assertSee('wrong attempts in a row');

    $client->forceFill(['pin_locked_until' => now()->subMinute()])->save();

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertSee(__('PIN set'))
        ->assertDontSee('PIN locked until');
});

it('notes a missing or an about-to-close EMD record under the external id, and nothing otherwise', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $client = Client::factory()->synced()->create();

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertDontSee('Missing from the EMD sync')
        ->assertDontSee('closes at the next sync');

    // Threshold 2 by default: one missed run away from being made missing.
    $client->externalRecord->update(['missed_runs' => 1]);
    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertSee('closes at the next sync');

    $client->externalRecord->update(['missed_runs' => 2, 'missing_since' => now()]);
    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertSee('Missing from the EMD sync since')
        ->assertDontSee('closes at the next sync');
});

it('adds and edits the internal note from the record page and sets it off only while it is filled', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $client = Client::factory()->synced()->create(['notes' => null]);

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertSee(__('Add note'))
        ->assertDontSeeHtml(ClientResource::NOTE_CLASSES)
        ->callAction(entryAction('editNotes', 'notes'), ['notes' => "Hallássérült: lassan, tagoltan beszéljünk.\nCsak délután hívható."])
        ->assertHasNoActionErrors();

    expect($client->fresh()->notes)->toBe("Hallássérült: lassan, tagoltan beszéljünk.\nCsak délután hívható.");

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertSee('Hallássérült: lassan, tagoltan beszéljünk.')
        ->assertSee(__('Edit note'))
        ->assertSeeHtml(ClientResource::NOTE_CLASSES);

    // Reading the note is for everyone with the client view; writing it is client management.
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertSee('Hallássérült: lassan, tagoltan beszéljünk.')
        ->assertActionDoesNotExist(entryAction('editNotes', 'notes'));
});

it('closes and reopens the account from the record page', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $client = Client::factory()->synced()->create();

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->callAction(entryAction('close', 'closed_at'));

    expect($client->fresh()->isClosed())->toBeTrue();

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertActionDoesNotExist(entryAction('close', 'closed_at'))
        ->assertSee(__('Closed (:r)', ['r' => __('admin')]))
        ->callAction(entryAction('reopen', 'closed_at'));

    expect($client->fresh()->isClosed())->toBeFalse();
});

it('offers the EMD record link only to those who may see the directory', function (): void {
    $client = Client::factory()->synced()->create();

    $this->actingAs(User::factory()->withRole(Role::Supervisor)->create());
    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertActionDoesNotExist(entryAction('openDirectoryRecord', 'external_id'));

    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertActionVisible(entryAction('openDirectoryRecord', 'external_id'));
});

it('lists the unlock in the row menu of the client list only for a locked client', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $locked = Client::factory()->synced()->create(['locked_until' => now()->addHour()]);
    $open = Client::factory()->synced()->create();

    Livewire::test(ListClients::class)
        ->assertTableActionVisible('unlockLogin', $locked)
        ->assertTableActionHidden('unlockLogin', $open)
        ->assertTableActionVisible('close', $open);
});

it('clears a staff binding from the binding block of the edit form, only while one exists', function (): void {
    $admin = User::factory()->synced()->withRole(Role::Admin)->create();
    $user = User::factory()->synced()->withRole(Role::Agent)->create();
    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->assertActionDoesNotExist('unbindSso')
        ->assertActionDoesNotExist(TestAction::make('unbindSso')->schemaComponent('ssoBinding'));

    SsoIdentity::factory()->forAccount($user, subject: 'oid-agent')->create();

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->callAction(TestAction::make('unbindSso')->schemaComponent('ssoBinding'))
        ->assertNotified();

    expect($user->ssoIdentities()->count())->toBe(0);
});
