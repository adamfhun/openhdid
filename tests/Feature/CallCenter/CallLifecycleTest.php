<?php

use App\Auth\Role;
use App\CallCenter\CallAlreadyClaimedException;
use App\CallCenter\CallCenterService;
use App\Enums\CallStatus;
use App\Enums\PhoneNumberSource;
use App\Filament\Admin\Resources\Calls\Pages\ManageCalls;
use App\Filament\Admin\Widgets\OngoingCallsWidget;
use App\Filament\Admin\Widgets\OverviewStatsWidget;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('hdid.callcenter.api_key', 'key-123');
    config()->set('hdid.callcenter.hmac_secret', null);
    $this->withHeader('X-Api-Key', 'key-123');
});

it('does not match a number shared by several clients and says so', function (): void {
    $a = Client::factory()->synced()->create();
    $b = Client::factory()->synced()->create();
    $a->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::ClientSelf]);
    $b->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::ClientSelf]);

    $this->getJson('/api/v1/callcenter/lookup?caller_number=06301234567')->assertOk()
        ->assertJsonPath('client', null)
        ->assertJsonPath('ambiguous', true);

    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'amb-1', 'caller_number' => '+36301234567', 'status' => 'ringing'])
        ->assertCreated()
        ->assertJsonPath('data.client', null)
        ->assertJsonPath('data.ambiguous', true);

    expect(AuditLog::query()->where('event', 'call.ambiguous_number')->count())->toBe(1);

    // A verified number outranks an unverified one for the same number, so the tie is broken.
    $a->phoneNumbers()->first()->forceFill(['verified_at' => now()])->save();
    $this->getJson('/api/v1/callcenter/lookup?caller_number=06301234567')->assertOk()
        ->assertJsonPath('client.id', $a->id)
        ->assertJsonPath('ambiguous', false);

    // Both verified: primary wins; both primary: ambiguous again.
    $b->phoneNumbers()->first()->forceFill(['verified_at' => now(), 'is_primary' => true])->save();
    $this->getJson('/api/v1/callcenter/lookup?caller_number=06301234567')->assertOk()->assertJsonPath('client.id', $b->id);
    $a->phoneNumbers()->first()->forceFill(['is_primary' => true])->save();
    $this->getJson('/api/v1/callcenter/lookup?caller_number=06301234567')->assertOk()->assertJsonPath('ambiguous', true);
});

it('still matches an unverified self-added number when it is the only one', function (): void {
    $client = Client::factory()->synced()->create();
    $client->phoneNumbers()->create(['number_e164' => '+36309999999', 'source' => PhoneNumberSource::ClientSelf]);

    $this->getJson('/api/v1/callcenter/lookup?caller_number=+36309999999')->assertOk()->assertJsonPath('client.id', $client->id);
});

it('never resurrects an ended call from a late event', function (): void {
    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'late-1', 'caller_number' => '+36301111111', 'status' => 'ringing'])->assertCreated();
    $this->postJson('/api/v1/callcenter/calls/late-1/end')->assertOk();

    $ended = Call::query()->where('external_call_id', 'late-1')->first();
    expect($ended->status)->toBe(CallStatus::Missed)->and($ended->ended_at)->not->toBeNull();

    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'late-1', 'caller_number' => '+36301111111', 'status' => 'active'])->assertOk();

    $fresh = $ended->fresh();
    expect($fresh->status)->toBe(CallStatus::Missed)
        ->and($fresh->ended_at?->toIso8601String())->toBe($ended->ended_at->toIso8601String())
        ->and(Call::query()->ongoing()->count())->toBe(0);
});

it('survives two simultaneous first events for the same call', function (): void {
    $service = app(CallCenterService::class);

    // Simulate the race: the row appears between firstOrNew and save.
    Call::creating(function (Call $call): void {
        static $injected = false;
        if (! $injected) {
            $injected = true;
            Call::withoutEvents(fn () => Call::query()->forceCreate([
                'external_call_id' => $call->external_call_id,
                'status' => CallStatus::Ringing,
                'tier' => 'premium',
                'arrived_at' => now(),
            ]));
        }
    });

    $call = $service->upsertCall('race-1', '+36301111111', CallStatus::Active);

    expect(Call::query()->where('external_call_id', 'race-1')->count())->toBe(1)
        ->and($call->status)->toBe(CallStatus::Active);
});

it('lets an agent take, hand over and release a call', function (): void {
    $service = app(CallCenterService::class);
    $first = User::factory()->create();
    $second = User::factory()->create();
    $call = Call::factory()->create();

    $service->claim($call, $first);
    expect($call->fresh()->agent_user_id)->toBe($first->id)->and($call->fresh()->status)->toBe(CallStatus::Active);

    // Re-claiming your own call is a no-op, someone else's needs an explicit take-over.
    $service->claim($call->fresh(), $first);
    expect(fn () => $service->claim($call->fresh(), $second))->toThrow(CallAlreadyClaimedException::class);
    expect($call->fresh()->agent_user_id)->toBe($first->id);

    $service->claim($call->fresh(), $second, takeOver: true);
    expect($call->fresh()->agent_user_id)->toBe($second->id)
        ->and(AuditLog::query()->where('event', 'call.taken_over')->where('subject_id', $call->id)->exists())->toBeTrue();

    $service->release($call->fresh(), $second);
    $released = $call->fresh();
    expect($released->agent_user_id)->toBeNull()
        ->and($released->status)->toBe(CallStatus::Ringing)
        ->and(AuditLog::query()->where('event', 'call.released')->where('subject_id', $call->id)->exists())->toBeTrue();
});

it('refuses to release a call on behalf of an agent who no longer holds it', function (): void {
    $service = app(CallCenterService::class);
    $first = User::factory()->create();
    $second = User::factory()->create();
    $call = Call::factory()->create();

    $service->claim($call, $first);
    $service->claim($call->fresh(), $second, takeOver: true);
    expect($call->fresh()->agent_user_id)->toBe($second->id);

    // The first agent's stale "release" request arrives after the take-over.
    expect(fn () => $service->release($call->fresh(), $first))->toThrow(ValidationException::class);

    $held = $call->fresh();
    expect($held->agent_user_id)->toBe($second->id, 'the take-over must survive the previous holder\'s release')
        ->and($held->status)->toBe(CallStatus::Active)
        ->and(AuditLog::query()->where('event', 'call.released')->where('subject_id', $call->id)->exists())->toBeFalse();
});

it('refuses to release a call the agent never held', function (): void {
    $service = app(CallCenterService::class);
    $holder = User::factory()->create();
    $stranger = User::factory()->create();
    $call = Call::factory()->create();

    $service->claim($call, $holder);

    expect(fn () => $service->release($call->fresh(), $stranger))->toThrow(ValidationException::class);
    expect($call->fresh()->agent_user_id)->toBe($holder->id);
});

it('asks for confirmation on the dashboard before taking over a colleague\'s call', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $agent = User::factory()->withRole(Role::Agent)->create(['handles_tiers' => ['premium']]);
    $colleague = User::factory()->withRole(Role::Agent)->create(['name' => 'Kiss Béla', 'handles_tiers' => ['premium']]);
    $this->actingAs($agent);

    $theirs = Call::factory()->create(['agent_user_id' => $colleague->id, 'status' => CallStatus::Active]);
    $mine = Call::factory()->create(['agent_user_id' => $agent->id, 'status' => CallStatus::Active]);
    $free = Call::factory()->create();

    Livewire::test(OngoingCallsWidget::class)
        ->assertTableActionHasLabel('take', 'Take over', $theirs)
        ->assertTableActionHasLabel('take', 'Identify', $free)
        ->assertTableActionVisible('release', $mine)
        ->assertTableActionHidden('release', $theirs)
        ->callTableAction('release', $mine);

    expect($mine->fresh()->agent_user_id)->toBeNull();

    Livewire::test(OngoingCallsWidget::class)->callTableAction('take', $theirs);
    expect($theirs->fresh()->agent_user_id)->toBe($agent->id);
});

it('closes calls whose end event never came', function (): void {
    app(Settings::class)->set(SettingKey::CallsStaleAfterHours, 6);
    $old = Call::factory()->create(['arrived_at' => now()->subHours(7)]);
    $recent = Call::factory()->create(['arrived_at' => now()->subHours(2)]);
    $ended = Call::factory()->create(['arrived_at' => now()->subHours(9), 'status' => CallStatus::Ended, 'ended_at' => now()->subHours(8)]);

    expect(app(CallCenterService::class)->expireStale())->toBe(1);

    expect($old->fresh()->status)->toBe(CallStatus::Missed)
        ->and($old->fresh()->ended_at)->not->toBeNull()
        ->and($recent->fresh()->status)->toBe(CallStatus::Ringing)
        ->and($ended->fresh()->ended_at->toIso8601String())->toBe($ended->ended_at->toIso8601String())
        ->and(AuditLog::query()->where('event', 'call.expired')->count())->toBe(1);
});

it('scopes the dashboard counters to the levels the agent is viewing', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $agent = User::factory()->withRole(Role::Agent)->create(['handles_tiers' => ['premium', 'standard'], 'active_tier' => 'standard']);
    $this->actingAs($agent);

    Call::factory()->count(3)->create(['tier' => 'premium']);
    Call::factory()->create(['tier' => 'standard']);

    Livewire::test(OverviewStatsWidget::class)->assertSee('Ongoing calls')->assertSeeInOrder(['Ongoing calls', '1']);
});

it('opens identification directly for own and unclaimed calls', function (bool $held): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $agent = User::factory()->withRole(Role::Agent)->create();
    $this->actingAs($agent);
    $call = Call::factory()->create(['agent_user_id' => $held ? $agent->id : null]);

    Livewire::test(OngoingCallsWidget::class)
        ->mountTableAction('take', $call)
        ->assertRedirect();

    expect($call->fresh()->agent_user_id)->toBe($agent->id)
        ->and(AuditLog::query()->where('event', 'call.taken_over')->exists())->toBeFalse();
})->with([true, false]);

it('does not take a colleagues call until the confirmation is submitted', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $agent = User::factory()->withRole(Role::Agent)->create();
    $colleague = User::factory()->withRole(Role::Agent)->create(['name' => 'Other agent']);
    $this->actingAs($agent);
    $call = Call::factory()->create(['agent_user_id' => $colleague->id]);

    $page = Livewire::test(OngoingCallsWidget::class)->mountTableAction('take', $call)
        ->assertActionMounted(TestAction::make('take')->table($call))
        ->assertNoRedirect();
    expect($call->fresh()->agent_user_id)->toBe($colleague->id);

    $page->callMountedTableAction()->assertRedirect();
    expect($call->fresh()->agent_user_id)->toBe($agent->id)
        ->and(AuditLog::query()->where('event', 'call.taken_over')->where('subject_id', $call->id)->exists())->toBeTrue();
});

it('writes the end, the claim and the release of a call inside the call lock', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $locked = [];
    Event::listen('eloquent.updated: '.Call::class, function (Call $call) use (&$locked): void {
        // A second lock instance can only take the key when nobody holds it.
        $probe = Cache::lock('hdid:call:'.$call->external_call_id, 1);
        $free = $probe->get();
        if ($free) {
            $probe->release();
        }
        $locked[] = ! $free;
    });

    $service = app(CallCenterService::class);
    $agent = User::factory()->withRole(Role::Agent)->create();
    $call = Call::factory()->create(['external_call_id' => 'lock-1', 'status' => CallStatus::Ringing]);

    $service->claim($call, $agent);
    $service->release($call, $agent);
    $service->endCall('lock-1');

    // Answered and then released: the call ends as ended, not missed.
    expect($call->fresh()->status)->toBe(CallStatus::Ended)
        ->and($locked)->not->toBeEmpty()
        ->and(array_unique($locked))->toBe([true], 'every write to a call happens while its lock is held');
});

it('shows identification and client counters on the overview only with the matching permissions', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    Livewire::test(OverviewStatsWidget::class)
        ->assertSee(__('Ongoing calls'))
        ->assertDontSee(__('Identifications today'));

    $this->actingAs(User::factory()->withRole(Role::Supervisor)->create());
    Livewire::test(OverviewStatsWidget::class)
        ->assertSee(__('Identifications today'))
        ->assertSee(__('Clients'));
});

it('revives a pruned call id instead of failing on the unique index, and keeps the call on its sessions', function (): void {
    $service = app(CallCenterService::class);
    $call = $service->upsertCall('revive-1', '+36301234567', CallStatus::Ringing);
    $session = IdSession::factory()->create(['call_id' => $call->id]);

    $service->endCall('revive-1');
    app(Settings::class)->set(SettingKey::CallsRetentionHours, 1);
    $this->travel(2)->hours();
    expect($service->pruneEnded())->toBe(1);

    // The identification keeps pointing at the pruned call.
    expect($session->fresh()->call)->not->toBeNull()
        ->and($session->fresh()->call->external_call_id)->toBe('revive-1');

    // A later event for the same id must not hit the unique index.
    $again = $service->upsertCall('revive-1', '+36301234567', CallStatus::Active);
    expect($again->trashed())->toBeFalse()
        ->and(Call::withTrashed()->where('external_call_id', 'revive-1')->count())->toBe(1);
});

it('offers a tab without a time window on the calls list', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->withRole(Role::Supervisor)->create());

    $old = Call::factory()->create(['arrived_at' => today()->subDays(200), 'status' => CallStatus::Ended, 'ended_at' => today()->subDays(200)]);

    Livewire::test(ManageCalls::class)->assertCanNotSeeTableRecords([$old]);
    Livewire::test(ManageCalls::class)->set('activeTab', 'all')->assertCanSeeTableRecords([$old]);
});
