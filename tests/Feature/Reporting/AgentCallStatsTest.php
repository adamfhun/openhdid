<?php

use App\Auth\Role;
use App\CallCenter\CallCenterService;
use App\Enums\CallStatus;
use App\Enums\ClientTier;
use App\Enums\IdSessionStatus;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Resources\Calls\Pages\ManageCalls;
use App\Filament\Admin\Widgets\AgentCallsChartWidget;
use App\Filament\Admin\Widgets\AgentCallsTableWidget;
use App\Filament\Admin\Widgets\IdentificationsChartWidget;
use App\Models\Call;
use App\Models\IdSession;
use App\Models\User;
use App\Reporting\AgentCallStats;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('counts calls per agent, queue and day, busiest agent first, pruned calls included', function (): void {
    $anna = User::factory()->create(['name' => 'Anna']);
    $bela = User::factory()->create(['name' => 'Béla']);

    Call::factory()->count(3)->create(['agent_user_id' => $anna->id, 'queue' => 'Ügyfélszolgálat', 'status' => CallStatus::Ended, 'arrived_at' => today()->setTime(9, 0)]);
    Call::factory()->create(['agent_user_id' => $anna->id, 'queue' => 'Prémium', 'status' => CallStatus::Ended, 'arrived_at' => today()->subDay()->setTime(9, 0)]);
    Call::factory()->count(2)->create(['agent_user_id' => $bela->id, 'queue' => 'Ügyfélszolgálat', 'status' => CallStatus::Ended, 'arrived_at' => today()->setTime(9, 0)]);
    // Missed and unclaimed calls belong to nobody.
    Call::factory()->create(['status' => CallStatus::Missed, 'ended_at' => now(), 'arrived_at' => today()->setTime(9, 0)]);
    // Older than the window.
    Call::factory()->create(['agent_user_id' => $bela->id, 'queue' => 'Ügyfélszolgálat', 'status' => CallStatus::Ended, 'arrived_at' => today()->subDays(20)]);

    // The short call retention soft-deletes ended calls: they still count.
    Call::query()->where('agent_user_id', $bela->id)->delete();

    $summary = app(AgentCallStats::class)->summarize(today()->subDays(13), today());

    expect($summary->days)->toHaveCount(14)
        ->and($summary->queues)->toBe(['Ügyfélszolgálat', 'Prémium'])
        ->and(array_column($summary->agents, 'name'))->toBe(['Anna', 'Béla'])
        ->and($summary->agents[0]['total'])->toBe(4)
        ->and($summary->agents[0]['by_queue'])->toBe(['Ügyfélszolgálat' => 3, 'Prémium' => 1])
        ->and($summary->count($summary->agents[0], today()->subDay()->toDateString(), 'Prémium'))->toBe(1)
        ->and($summary->dayCount($summary->agents[1], today()->toDateString()))->toBe(2)
        ->and($summary->dayTotals[today()->toDateString()])->toBe(5)
        ->and($summary->queueTotals)->toBe(['Ügyfélszolgálat' => 5, 'Prémium' => 1])
        ->and($summary->total)->toBe(6);
});

it('follows the call view switch on the dashboard and caches per view', function (): void {
    app(Settings::class)->set(SettingKey::PackagesStandard, ['Basic']);
    $agent = User::factory()->withRole(Role::Agent)->create(['name' => 'Anna', 'handles_tiers' => ['premium', 'standard']]);

    Call::factory()->create(['agent_user_id' => $agent->id, 'tier' => ClientTier::Premium, 'status' => CallStatus::Ended, 'queue' => 'Prémium']);
    Call::factory()->create(['agent_user_id' => $agent->id, 'tier' => ClientTier::Standard, 'status' => CallStatus::Ended, 'queue' => 'Normál']);

    expect(app(AgentCallStats::class)->dashboard($agent)->total)->toBe(2);

    $agent->switchTier(ClientTier::Standard);
    $standardOnly = app(AgentCallStats::class)->dashboard($agent->fresh());

    expect($standardOnly->total)->toBe(1)
        ->and($standardOnly->queues)->toBe(['Normál']);

    // Cached as a plain array (the cache stores refuse foreign classes on unserialize).
    expect(Cache::get('hdid.dashboard.agent-calls.'.today()->toDateString().'.14.standard'))->toBeArray()->toHaveKey('agents');

    // Cached for a minute: a new call does not show until the cache expires.
    Call::factory()->create(['agent_user_id' => $agent->id, 'tier' => ClientTier::Standard, 'status' => CallStatus::Ended, 'queue' => 'Normál']);
    expect(app(AgentCallStats::class)->dashboard($agent->fresh())->total)->toBe(1);
    Cache::flush();
    expect(app(AgentCallStats::class)->dashboard($agent->fresh())->total)->toBe(2);
});

it('renders the agent chart and table for everyone who sees calls', function (): void {
    $agent = User::factory()->withRole(Role::Agent)->create(['name' => 'Kovács Anna']);
    $this->actingAs($agent);

    Call::factory()->create(['agent_user_id' => $agent->id, 'queue' => 'Ügyfélszolgálat', 'status' => CallStatus::Ended]);
    Call::factory()->create(['agent_user_id' => $agent->id, 'queue' => 'Prémium', 'status' => CallStatus::Ended]);

    expect(AgentCallsChartWidget::canView())->toBeTrue()
        ->and(AgentCallsTableWidget::canView())->toBeTrue();

    Livewire::test(AgentCallsTableWidget::class)
        ->assertSee('Kovács Anna')
        ->assertSee('Ügyfélszolgálat')
        ->assertSee('Prémium');

    $data = (new ReflectionMethod(AgentCallsChartWidget::class, 'getData'))->invoke(Livewire::test(AgentCallsChartWidget::class)->instance());
    expect($data['labels'])->toBe(['Kovács Anna'])
        ->and(array_column($data['datasets'], 'label'))->toBe(['Prémium', 'Ügyfélszolgálat'])
        ->and($data['datasets'][0]['data'])->toBe([1])
        ->and($data['datasets'][1]['data'])->toBe([1]);

    // Widgets load lazily: the dashboard carries both components.
    $this->get('/admin')->assertOk()->assertSee('AgentCallsChartWidget')->assertSee('AgentCallsTableWidget');
});

it('keeps pruned calls on the Calls list so the list matches the reports', function (): void {
    $agent = User::factory()->withRole(Role::Agent)->create(['name' => 'Kovács Anna']);
    $this->actingAs($agent);

    $call = Call::factory()->create(['agent_user_id' => $agent->id, 'status' => CallStatus::Ended, 'external_call_id' => 'pruned-call-1', 'ended_at' => now()->subDays(2), 'arrived_at' => now()->subDays(2)]);
    app(CallCenterService::class)->pruneEnded();
    expect($call->fresh()->trashed())->toBeTrue();

    Livewire::test(ManageCalls::class)->assertCanSeeTableRecords([$call]);
    expect(app(AgentCallStats::class)->summarize(today()->subDays(13), today())->total)->toBe(1);
});

it('offers agents with only archived calls in the historical call filter', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $agent = User::factory()->withRole(Role::Agent)->create();
    $this->actingAs($agent);
    $call = Call::factory()->create(['agent_user_id' => $agent->id, 'status' => CallStatus::Ended, 'arrived_at' => now()->subDays(2), 'ended_at' => now()->subDays(2)]);
    app(CallCenterService::class)->pruneEnded();

    $page = Livewire::test(ManageCalls::class)->assertCanSeeTableRecords([$call]);

    expect($page->instance()->getTable()->getFilter('agent_user_id')->getOptions())->toHaveKey($agent->id);
});

it('defaults the shared dashboard report period to fourteen days', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());

    Livewire::test(Dashboard::class)
        ->assertSet('filters.days', 14)
        ->set('filters.days', '7')
        ->assertSet('filters.days', '7');
});

it('uses the selected period in all dashboard reports with separate caches', function (int $days): void {
    $this->freezeTime();
    $agent = User::factory()->withRole(Role::Agent)->create();
    $this->actingAs($agent);
    $start = today()->subDays($days - 1);
    Call::factory()->create(['agent_user_id' => $agent->id, 'arrived_at' => $start, 'queue' => 'Included']);
    Call::factory()->create(['agent_user_id' => $agent->id, 'arrived_at' => $start->copy()->subSecond(), 'queue' => 'Excluded']);
    Call::factory()->create(['agent_user_id' => $agent->id, 'arrived_at' => today()->addDay(), 'queue' => 'Future']);
    IdSession::factory()->create(['started_at' => $start, 'status' => IdSessionStatus::Passed]);
    IdSession::factory()->create(['started_at' => $start->copy()->subSecond(), 'status' => IdSessionStatus::Passed]);
    IdSession::factory()->create(['started_at' => today()->addDay(), 'status' => IdSessionStatus::Passed]);

    $otherPeriod = $days === 1 ? 30 : 1;
    app(AgentCallStats::class)->dashboard($agent, $otherPeriod);
    Livewire::test(IdentificationsChartWidget::class, ['pageFilters' => ['days' => $otherPeriod]]);
    $filters = ['pageFilters' => ['days' => (string) $days]];
    $table = Livewire::test(AgentCallsTableWidget::class, $filters);
    $summary = $table->instance()->getSummary();
    $chart = Livewire::test(AgentCallsChartWidget::class, $filters);
    $calls = (new ReflectionMethod(AgentCallsChartWidget::class, 'getData'))->invoke($chart->instance());
    $ids = (new ReflectionMethod(IdentificationsChartWidget::class, 'getData'))->invoke(Livewire::test(IdentificationsChartWidget::class, $filters)->instance());

    expect($summary->days)->toHaveCount($days)->and($summary->total)->toBe(1)
        ->and($summary->queues)->toBe(['Included'])
        ->and($calls['datasets'][0]['data'])->toBe([1])
        ->and($ids['labels'])->toHaveCount($days)
        ->and(array_sum($ids['datasets'][0]['data']))->toBe(1);
})->with([1, 7, 14, 28, 30]);

it('falls back to fourteen days for unsupported dashboard periods', function (mixed $value): void {
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    $widget = Livewire::test(AgentCallsTableWidget::class, ['pageFilters' => ['days' => $value]]);

    expect($widget->instance()->getSummary()->days)->toHaveCount(14);
})->with([0, -7, 365, 'invalid', 'nested' => [['bad']]]);

it('defaults Calls to thirty days and switches the visible historical period', function (int $days): void {
    $this->freezeTime();
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    $start = today()->subDays($days - 1);
    $included = Call::factory()->create(['arrived_at' => $start]);
    $excluded = Call::factory()->create(['arrived_at' => $start->copy()->subSecond()]);
    $future = Call::factory()->create(['arrived_at' => today()->addDay()]);
    $included->delete();

    Livewire::test(ManageCalls::class)
        ->assertSet('activeTab', '30')
        ->set('activeTab', (string) $days)
        ->assertCanSeeTableRecords([$included])
        ->assertCanNotSeeTableRecords([$excluded, $future]);
})->with([15, 30, 60, 90, 120]);

it('keeps a thirty day limit when the Calls period is missing or invalid', function (?string $tab): void {
    $this->freezeTime();
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    $recent = Call::factory()->create(['arrived_at' => today()->subDays(29)]);
    $old = Call::factory()->create(['arrived_at' => today()->subDays(30)]);

    Livewire::test(ManageCalls::class)->set('activeTab', $tab)
        ->assertSet('activeTab', '30')
        ->assertCanSeeTableRecords([$recent])->assertCanNotSeeTableRecords([$old]);
})->with([null, 'nonsense', '999']);
