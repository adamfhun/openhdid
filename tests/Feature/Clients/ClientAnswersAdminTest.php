<?php

use App\Auth\Role;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Clients\RelationManagers\AnswersRelationManager;
use App\Identification\ClientAnswers;
use App\Models\Client;
use App\Models\Question;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->client = Client::factory()->synced()->create();
    $this->answer = app(ClientAnswers::class)->save($this->client, Question::factory()->create(), 'kék');
});

/**
 * Removing an answer takes away one of the client's means of identification,
 * so it needs the client management permission: an agent only reads the
 * client page. Without a policy of its own the tab would allow everyone.
 */
it('lets only client managers remove a security answer', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());

    Livewire::test(AnswersRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
        ->assertActionHidden(TestAction::make('delete')->table($this->answer));

    expect($this->answer->fresh()->trashed())->toBeFalse();

    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    Livewire::test(AnswersRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
        ->assertActionVisible(TestAction::make('delete')->table($this->answer))
        ->callAction(TestAction::make('delete')->table($this->answer));

    expect($this->answer->fresh()->trashed())->toBeTrue();
});
