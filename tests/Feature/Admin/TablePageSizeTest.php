<?php

use App\Audit\Auditor;
use App\Auth\Role;
use App\Enums\CallStatus;
use App\Filament\Admin\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Filament\Admin\Resources\Calls\Pages\ManageCalls;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Clients\RelationManagers\IdSessionsRelationManager;
use App\Filament\Admin\Widgets\MissedCallsWidget;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * The rows the table rendered on its current page.
 */
function renderedTableRows(mixed $component): int
{
    return $component->instance()->getTableRecords()->count();
}

it('keeps a list on one of the offered page sizes whatever page size the browser sends', function (mixed $requested): void {
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    Call::factory()->count(60)->create();

    $page = Livewire::test(ManageCalls::class);
    expect(renderedTableRows($page))->toBe(10);

    $page->set('tableRecordsPerPage', $requested);

    expect($page->instance()->getTableRecordsPerPage())->toBe(5, 'an unknown page size falls back to the first offered one')
        ->and(renderedTableRows($page))->toBe(5);
})->with([
    'all rows' => ['all'],
    'a huge number' => [100000],
    'a huge number as text' => ['100000'],
    'a page size between the offered ones' => [30],
    'a nested value' => [['all']],
    'a boolean' => [true],
]);

it('keeps an offered page size the browser sends as text', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    Call::factory()->count(60)->create();

    $page = Livewire::test(ManageCalls::class)->set('tableRecordsPerPage', '25');

    expect($page->instance()->getTableRecordsPerPage())->toBe(25)
        ->and(renderedTableRows($page))->toBe(25);
});

it('does not render the whole client list for an agent on request', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    Client::factory()->count(60)->premium()->synced()->create();

    $page = Livewire::test(ListClients::class);
    expect(renderedTableRows($page))->toBe(10);

    $page->set('tableRecordsPerPage', 'all');

    expect(renderedTableRows($page))->toBeLessThanOrEqual(50);
});

it('does not render the whole audit log for a supervisor on request', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Supervisor)->create());
    foreach (range(1, 60) as $index) {
        app(Auditor::class)->record('test.event', null, ['index' => $index]);
    }

    $page = Livewire::test(ManageAuditLogs::class)->set('tableRecordsPerPage', 'all');

    expect(renderedTableRows($page))->toBeLessThanOrEqual(50);
});

it('keeps a tab of the client page on one of the offered page sizes', function (): void {
    $agent = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($agent);
    $client = Client::factory()->premium()->synced()->create();
    IdSession::factory()->count(60)->create(['client_id' => $client->id, 'agent_user_id' => $agent->id]);

    $tab = Livewire::test(IdSessionsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->set('tableRecordsPerPage', 'all');

    expect($tab->instance()->getTableRecordsPerPage())->toBe(5)
        ->and(renderedTableRows($tab))->toBe(5);
});

it('keeps a dashboard table widget on its own offered page sizes', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    Call::factory()->count(60)->create(['status' => CallStatus::Missed, 'ended_at' => now()]);

    $widget = Livewire::test(MissedCallsWidget::class)->set('tableRecordsPerPage', 'all');

    expect($widget->instance()->getTableRecordsPerPage())->toBe(10, 'the widget offers 10 and 25')
        ->and(renderedTableRows($widget))->toBe(10);

    $widget->set('tableRecordsPerPage', '25');
    expect(renderedTableRows($widget))->toBe(25);
});
