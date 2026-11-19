<?php

use App\Auth\Role;
use App\Clients\ClientRemoval;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Clients\RelationManagers\AnswersRelationManager;
use App\Filament\Admin\Resources\Clients\RelationManagers\IdSessionsRelationManager;
use App\Filament\Admin\Resources\Clients\RelationManagers\LinkedClientsRelationManager;
use App\Filament\Admin\Resources\Clients\RelationManagers\PhoneNumbersRelationManager;
use App\Identification\ClientAnswers;
use App\Models\Client;
use App\Models\ClientAnswer;
use App\Models\IdSession;
use App\Models\Question;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * Switching the tabs under the client page (phone numbers → answers →
 * identification sessions) renders the page again with the other tab.
 */
it('switches between every tab of the client page', function (Role $role): void {
    $this->actingAs(User::factory()->withRole($role)->create());
    $client = Client::factory()->synced()->premium()->withPhone()->withPin()->create();
    app(ClientAnswers::class)->save($client, Question::factory()->create(), 'kék');

    $page = Livewire::test(ViewClient::class, ['record' => $client->getKey()])->assertSuccessful();

    foreach (array_keys(ViewClient::getResource()::getRelations()) as $key) {
        $page->set('activeRelationManager', (string) $key)->assertSuccessful();
    }
})->with([Role::Admin, Role::Supervisor, Role::Agent]);

it('loads every lazy tab of the client page, after the phone numbers tab was sorted and searched', function (Role $role, string $tab): void {
    $this->actingAs(User::factory()->withRole($role)->create());
    $client = Client::factory()->synced()->premium()->withPhone()->withPin()->create();
    app(ClientAnswers::class)->save($client, Question::factory()->create(), 'kék');

    // The table state of a tab is kept in the session (persistSortInSession and the others).
    Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->sortTable('number_e164')->searchTable('3630')->assertSuccessful();

    $test = Livewire::test($tab, ['ownerRecord' => $client, 'pageClass' => ViewClient::class, 'lazy' => true]);
    preg_match('/__lazyLoad\((?:\'|&#039;)([^\'&]+)(?:\'|&#039;)\)/', $test->html(), $match);
    expect($match[1] ?? null)->not->toBeNull();

    $test->call('__lazyLoad', $match[1])->assertSuccessful();
})->with([
    // The identification sessions tab is not the agent's (403 by design).
    'admin, phone numbers' => [Role::Admin, PhoneNumbersRelationManager::class],
    'admin, answers' => [Role::Admin, AnswersRelationManager::class],
    'admin, identification sessions' => [Role::Admin, IdSessionsRelationManager::class],
    'agent, phone numbers' => [Role::Agent, PhoneNumbersRelationManager::class],
    'agent, answers' => [Role::Agent, AnswersRelationManager::class],
]);

/**
 * A deleted client's page still opens (the "Deleted" filter), with its
 * numbers and answers kept: the tabs read the client from the page, as
 * a deleted client is not loaded through a number or an answer.
 */
it('opens the tabs of a deleted client and lets a manager remove a number and an answer there', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $client = Client::factory()->synced()->premium()->withPhone('+36301234567')->withPin()->create();
    $client->phoneNumbers()->create(['number_e164' => '+36309876543', 'source' => 'admin']);
    $answer = app(ClientAnswers::class)->save($client, Question::factory()->create(), 'kék');
    app(ClientRemoval::class)->delete($client);
    $client = Client::withTrashed()->findOrFail($client->getKey());
    $phone = $client->phoneNumbers()->where('number_e164', '+36309876543')->sole();

    Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$phone])
        ->callAction(TestAction::make('delete')->table($phone))
        ->assertHasNoActionErrors();

    expect($phone->fresh()?->trashed() ?? true)->toBeTrue();

    Livewire::test(AnswersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->assertSuccessful()
        ->callAction(TestAction::make('delete')->table($answer))
        ->assertHasNoActionErrors();

    expect(ClientAnswer::withTrashed()->whereKey($answer->getKey())->exists())->toBeFalse();
});

it('opens the view and edit pages and every tab of a deleted client', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $sponsor = Client::factory()->synced()->premium()->withPhone()->withPin()->create();
    $linked = Client::factory()->synced()->create();
    $sponsor->sponsoredLinks()->create(['linked_client_id' => $linked->id]);
    app(ClientAnswers::class)->save($sponsor, Question::factory()->create(), 'kék');
    IdSession::factory()->create(['client_id' => $sponsor->id]);
    app(ClientRemoval::class)->delete($sponsor);
    $sponsor = Client::withTrashed()->findOrFail($sponsor->getKey());

    Livewire::test(ViewClient::class, ['record' => $sponsor->getKey()])->assertSuccessful();
    Livewire::test(EditClient::class, ['record' => $sponsor->getKey()])->assertSuccessful();

    foreach ([PhoneNumbersRelationManager::class, AnswersRelationManager::class, IdSessionsRelationManager::class, LinkedClientsRelationManager::class] as $tab) {
        Livewire::test($tab, ['ownerRecord' => $sponsor, 'pageClass' => ViewClient::class])->assertSuccessful();
    }

    // The linked client's page, whose sponsor is the deleted client (the link ended with it, so the
    // linked clients tab is not offered there).
    Livewire::test(ViewClient::class, ['record' => $linked->getKey()])->assertSuccessful();
});
