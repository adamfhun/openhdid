<?php

use App\Auth\Role;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Identification\ClientAnswers;
use App\Models\Client;
use App\Models\Question;
use App\Models\SsoIdentity;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
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

it('names in the send-login-link confirmation that the client\'s earlier link stops working', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $client = Client::factory()->synced()->create();

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->mountAction(entryAction('sendMagicLink', 'last_login_at'))
        ->assertMountedActionModalSee(__('The link signs the client in once and expires after the configured minutes. Any login link sent to the client earlier stops working.'));
});

it('lists the identification methods on the client page, each with whether it can be used and why not', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $client = Client::factory()->synced()->withPhone()->withPin('482915')->create();
    $questions = Question::factory()->count(6)->create();
    foreach ($questions as $question) {
        app(ClientAnswers::class)->save($client, $question, 'válasz');
    }

    // Agents may not check the PIN (the default): the page says so instead of leaving a grey button to guesswork.
    $this->get(ClientResource::getUrl('view', ['record' => $client]))->assertOk()
        ->assertSee(__('Identification: available methods'))
        ->assertSee(__('PIN check by an agent'))
        ->assertSee('Switched off for the whole system')
        // Enough answers: the count alone, without the number needed.
        ->assertSee('6 answers')->assertDontSee('6 / ')
        ->assertSee(__('View EMD data'));

    expect(ClientResource::agentPinCheckBlocker($client))->toContain('Switched off for the whole system');

    app(Settings::class)->set(SettingKey::PinAgentVerificationEnabled, true);
    expect(ClientResource::agentPinCheckBlocker($client->fresh()))->toBeNull()
        ->and(ClientResource::agentPinCheckBlocker(Client::factory()->synced()->create()))->toBe(__('The client has no PIN.'));

    // Too few answers: now the number needed is worth saying.
    $few = Client::factory()->synced()->create();
    app(ClientAnswers::class)->save($few, $questions[0], 'válasz');
    $this->get(ClientResource::getUrl('view', ['record' => $few]))->assertOk()->assertSee('1 answers, 5 needed');
});

it('counts on the client list the answers the client page counts, not every stored one', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $client = Client::factory()->synced()->create();
    $questions = Question::factory()->count(3)->create();
    foreach ($questions as $question) {
        app(ClientAnswers::class)->save($client, $question, 'válasz');
    }
    // One question is switched off: its answer is stored but does not count anywhere.
    $questions[0]->forceFill(['is_active' => false])->save();

    $listed = ClientResource::getEloquentQuery()->withCount(['answers' => ClientAnswers::usableOnly(...)])->findOrFail($client->id);

    expect((int) $listed->answers_count)->toBe(2)
        ->and(app(ClientAnswers::class)->usableCount($client))->toBe(2)
        ->and($client->answers()->count())->toBe(3);

    Livewire::test(ListClients::class)->assertCanSeeTableRecords([$client])->assertTableColumnStateSet('answers_count', 2, $client);
});
