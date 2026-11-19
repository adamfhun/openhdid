<?php

use App\Auth\Role;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Clients\RelationManagers\AnswersRelationManager;
use App\Identification\ClientAnswers;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientAnswer;
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

    expect(ClientAnswer::withTrashed()->find($this->answer->id))->toBeNull('removed for good, the encrypted answer and its hash too')
        ->and(AuditLog::query()->where('event', 'client_answer.removed')->sole()->context)->toMatchArray(['via' => 'staff']);
});

it('deletes for good the answers removed before the upgrade', function (): void {
    $this->answer->delete();
    expect(ClientAnswer::withTrashed()->find($this->answer->id))->not->toBeNull();

    (require database_path('migrations/2026_11_02_100004_purge_removed_client_answers.php'))->up();

    expect(ClientAnswer::withTrashed()->find($this->answer->id))->toBeNull();
});

/**
 * A question deleted after the client answered it: the answer stays (the
 * question can be restored), cannot be used, and the tab says why instead
 * of failing on the missing question (500 on 1.15.5).
 */
it('shows an answer to a deleted question as unusable with the reason, and lets a manager remove it', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $this->answer->question->delete();

    Livewire::test(AnswersRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$this->answer])
        ->assertTableColumnStateSet('usable', false, $this->answer)
        ->assertSee(__('The question was deleted.'))
        ->callAction(TestAction::make('delete')->table($this->answer))
        ->assertHasNoActionErrors();

    expect(ClientAnswer::withTrashed()->whereKey($this->answer->getKey())->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'client_answer.removed')->exists())->toBeTrue();
});

it('says why an answer cannot be used: switched-off question, earlier wording', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $question = $this->answer->question;

    $question->update(['is_active' => false]);
    Livewire::test(AnswersRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
        ->assertTableColumnStateSet('usable', false, $this->answer)
        ->assertSee(__('The question is switched off.'));

    $question->update(['is_active' => true]);
    expect(ClientAnswers::unusableReason($this->answer->fresh()))->toBeNull();
});
