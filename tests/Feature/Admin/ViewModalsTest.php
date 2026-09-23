<?php

use App\Audit\Auditor;
use App\Auth\Role;
use App\Filament\Admin\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Clients\RelationManagers\IdSessionsRelationManager;
use App\Filament\Admin\Resources\IdSessions\Pages\ManageIdSessions;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\Question;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\Entry;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Admin Aladár']);
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
});

it('offers ending the override instead of setting one while an override is in force', function (): void {
    $client = Client::factory()->synced()->unentitled()->create();

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertActionVisible('overridePackage')
        ->assertActionHidden('endOverride')
        ->callAction('overridePackage', ['package' => 'Premium', 'reason' => 'Hibajegy 123', 'until' => now()->addDays(10)->toDateString()])
        ->assertHasNoActionErrors()
        ->assertActionHidden('overridePackage')
        ->assertActionVisible('endOverride')
        ->callAction('endOverride')
        ->assertActionVisible('overridePackage')
        ->assertActionHidden('endOverride');

    expect($client->fresh()->package_override)->toBeNull();
});

/**
 * The modal is not part of the rendered page HTML in a Livewire test, so the
 * mounted action's schema is inspected: entry name => [visible, state].
 *
 * @return array<string, array{0: bool, 1: mixed}>
 */
function modalEntries(Testable $component): array
{
    $page = $component->instance();
    $entries = [];

    foreach ($page->getSchema($page->getMountedActionSchemaName())->getFlatComponents(withHidden: true) as $entry) {
        if ($entry instanceof Entry) {
            $entries[$entry->getName()] = [$entry->isVisible(), $entry->getState()];
        }
    }

    return $entries;
}

it('shows the identification session details in the view modal, on the list and on the client tab', function (): void {
    $client = Client::factory()->create(['name' => 'Kiss Klára']);
    $session = IdSession::factory()->create(['client_id' => $client->id, 'agent_user_id' => $this->admin->id]);

    $entries = modalEntries(Livewire::test(ManageIdSessions::class)->mountAction(TestAction::make('view')->table($session)));
    expect($entries['client.name'][1])->toBe('Kiss Klára')
        ->and($entries['agent.name'][1])->toBe('Admin Aladár')
        ->and($entries)->toHaveKeys(['method', 'status', 'counts']);

    $tab = Livewire::test(IdSessionsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->mountAction(TestAction::make('view')->table($session));
    expect(modalEntries($tab)['client.name'][1])->toBe('Kiss Klára');
});

it('names the actor and the subject in the audit view modal and shows ids only where there is nothing to link to', function (): void {
    $client = Client::factory()->create(['name' => 'Kiss Klára']);
    $entry = app(Auditor::class)->record('client.closed', $client, ['reason' => 'manual']);

    $entries = modalEntries(Livewire::test(ManageAuditLogs::class)->mountAction(TestAction::make('view')->table($entry)));
    expect($entries['actor'][1])->toBe('Admin Aladár')
        ->and($entries['subject'][1])->toBe('Kiss Klára')
        ->and($entries['actor_id'][0])->toBeFalse('the actor is linked, the id is noise')
        ->and($entries['subject_id'][0])->toBeFalse()
        ->and($entries['context'][1])->toContain('"reason": "manual"');

    // A record without a page of its own cannot be linked: the id is shown.
    $question = Question::factory()->create()->fresh();
    $step = IdSession::factory()->create(['client_id' => $client->id])->steps()->create(['question_id' => $question->id, 'question_version_id' => $question->current_version_id, 'shown_at' => now()]);
    $entry = app(Auditor::class)->record('id_session.answer_judged', $step);
    $entries = modalEntries(Livewire::test(ManageAuditLogs::class)->mountAction(TestAction::make('view')->table($entry)));
    expect($entries['subject'][1])->toStartWith('IdSessionStep · ')
        ->and($entries['subject_id'][0])->toBeTrue()
        ->and($entries['subject_id'][1])->toBe($step->id);
});

it('keeps the name of a soft-deleted record and shows the raw id once it is gone for good', function (): void {
    $client = Client::factory()->create(['name' => 'Törölt Tamás']);
    $entry = app(Auditor::class)->record('client.closed', $client);

    $client->delete();
    $entries = modalEntries(Livewire::test(ManageAuditLogs::class)->mountAction(TestAction::make('view')->table($entry->fresh())));
    expect($entries['subject'][1])->toBe('Törölt Tamás')
        ->and($entries['subject_id'][0])->toBeFalse();

    $client->forceDelete();
    $entries = modalEntries(Livewire::test(ManageAuditLogs::class)->mountAction(TestAction::make('view')->table($entry->fresh())));
    expect($entries['subject'][1])->toStartWith('Client · ')
        ->and($entries['subject_id'][0])->toBeTrue()
        ->and($entries['subject_id'][1])->toBe($entry->subject_id);
});
