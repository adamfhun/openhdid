<?php

use App\Auth\Permission;
use App\Auth\Role;
use App\CallCenter\CallCenterService;
use App\Enums\CallStatus;
use App\Enums\IdSessionStatus;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Widgets\MissedCallsWidget;
use App\Filament\Admin\Widgets\OngoingCallsWidget;
use App\Models\Call;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\IdSession;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('shows the caller title and department from the directory on the name and links to the client', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    Filament::setCurrentPanel('admin');

    $record = ExternalRecord::factory()->client()->create(['attributes' => ['Title' => 'Pénzügyi vezető', 'department' => 'Pénzügy', 'other' => 'x']]);
    $client = Client::factory()->create(['name' => 'Kiss Klára', 'external_record_id' => $record->id, 'email' => $record->email]);
    $bare = Client::factory()->create(['name' => 'Nagy Nóra']);

    expect($record->jobLabel())->toBe('Pénzügyi vezető · Pénzügy')
        ->and(ExternalRecord::factory()->client()->create(['attributes' => ['department' => ' HR ']])->jobLabel())->toBe('HR');

    Call::factory()->create(['client_id' => $client->id]);
    Call::factory()->create(['client_id' => $bare->id]);

    Livewire::test(OngoingCallsWidget::class)
        ->assertSee('Pénzügyi vezető · Pénzügy')
        ->assertSee(__('No title or department in EMD'))
        ->assertSee(ClientResource::getUrl('view', ['record' => $client]));
});

it('does not let an identified call go back to the queue', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $agent = User::factory()->withRole(Role::Agent)->create();
    $this->actingAs($agent);
    Filament::setCurrentPanel('admin');

    $client = Client::factory()->create();
    $identified = Call::factory()->create(['client_id' => $client->id, 'agent_user_id' => $agent->id, 'status' => CallStatus::Active]);
    $fresh = Call::factory()->create(['client_id' => $client->id, 'agent_user_id' => $agent->id, 'status' => CallStatus::Active]);
    IdSession::factory()->create(['client_id' => $client->id, 'call_id' => $identified->id, 'agent_user_id' => $agent->id, 'status' => IdSessionStatus::Passed, 'decided_at' => now()]);

    expect(fn () => app(CallCenterService::class)->release($identified->fresh(), $agent))->toThrow(ValidationException::class)
        ->and($identified->fresh()->agent_user_id)->toBe($agent->id);

    Livewire::test(OngoingCallsWidget::class)
        ->assertTableActionDisabled('release', $identified)
        ->assertTableActionEnabled('release', $fresh)
        ->callTableAction('release', $fresh);

    expect($fresh->fresh()->agent_user_id)->toBeNull();
});

it('shows the raw caller number on the dashboard when it could not be normalised', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    app(CallCenterService::class)->upsertCall('ivr-raw', '3630123456789', CallStatus::Ringing, '1413');

    Livewire::test(OngoingCallsWidget::class)->assertSee('3630123456789');
});

it('lets a user who may only watch calls neither take, release nor handle them', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    // A custom role that may watch calls but not work them.
    Spatie\Permission\Models\Role::create(['name' => 'watcher'])->givePermissionTo([Permission::AdminAccess->value, Permission::CallsView->value]);
    $watcher = User::factory()->create();
    $watcher->assignRole('watcher');
    $holder = User::factory()->withRole(Role::Agent)->create();
    $this->actingAs($watcher);
    Filament::setCurrentPanel('admin');

    $ringing = Call::factory()->create(['status' => CallStatus::Ringing]);
    $held = Call::factory()->create(['agent_user_id' => $holder->id, 'status' => CallStatus::Active]);
    $missed = Call::factory()->create(['status' => CallStatus::Missed, 'ended_at' => now(), 'agent_user_id' => null]);

    Livewire::test(OngoingCallsWidget::class)
        ->assertTableActionHidden('take', $ringing)
        ->assertTableActionHidden('take', $held)
        ->assertTableActionHidden('release', $held);
    Livewire::test(MissedCallsWidget::class)
        ->assertTableActionHidden('handled', $missed);

    expect($ringing->fresh()->agent_user_id)->toBeNull()->and($held->fresh()->agent_user_id)->toBe($holder->id);
});
