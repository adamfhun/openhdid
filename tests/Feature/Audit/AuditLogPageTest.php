<?php

use App\Audit\Auditor;
use App\Auth\Role;
use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use App\Filament\Admin\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('names the actor and the subject, links to the record and still finds rows by the hidden ids', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Admin Aladár']);
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    $client = Client::factory()->create(['name' => 'Kiss Klára']);
    $session = IdSession::factory()->create(['client_id' => $client->id]);
    $ghost = Client::factory()->create(['name' => 'Törölt Tamás']);

    $entry = app(Auditor::class)->record('client.identified', $session);
    app(Auditor::class)->record('client.closed', $ghost);
    $ghost->forceDelete();

    expect(AuditLogResource::actorLabel($entry))->toBe('Admin Aladár')
        ->and(AuditLogResource::subjectLabel($entry))->toStartWith(__('Identification').' · Kiss Klára')
        ->and(AuditLogResource::describe($entry->subject)['url'])->toBe(ClientResource::getUrl('view', ['record' => $client]))
        ->and(AuditLogResource::subjectLabel(AuditLog::query()->where('event', 'client.closed')->first()))->toStartWith('Client · ');

    Livewire::test(ManageAuditLogs::class)
        ->assertCanSeeTableRecords(AuditLog::query()->get())
        ->assertSee('Admin Aladár')
        ->assertSee('Kiss Klára')
        ->assertSee(ClientResource::getUrl('view', ['record' => $client]))
        ->searchTable($session->id)
        ->assertCanSeeTableRecords([$entry])
        ->assertCanNotSeeTableRecords(AuditLog::query()->where('event', 'client.closed')->get());
});

it('stamps every entry of one request with the same id and echoes it in the response', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->synced()->create();

    $response = $this->actingAs($admin)->get(ClientResource::getUrl('view', ['record' => $client], panel: 'admin'));

    $ids = AuditLog::query()->where('event', 'client.viewed')->pluck('request_id');
    expect($ids)->toHaveCount(1)
        ->and($ids->first())->not->toBeNull()
        ->and($response->headers->get('X-Request-Id'))->toBe($ids->first());

    $this->artisan('hdid:prune')->assertSuccessful();
    expect(AuditLog::query()->where('event', 'retention.pruned')->value('request_id'))->toBeNull('console entries carry no request id');
});
