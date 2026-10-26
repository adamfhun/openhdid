<?php

use App\Auth\Role;
use App\Filament\Admin\Pages\Queues;
use App\Filament\Admin\Widgets\FailedJobsWidget;
use App\Filament\Admin\Widgets\PendingJobsWidget;
use App\Jobs\QueueHeartbeat;
use App\Jobs\SendOutboundMessage;
use App\Models\AuditLog;
use App\Models\FailedJob;
use App\Models\OutboundMessage;
use App\Models\QueuedJob;
use App\Models\User;
use App\System\CheckStatus;
use App\System\HealthChecks;
use App\System\Installation;
use App\System\QueueMaintenance;
use App\System\Retention;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');
    // The page works on the database driver: jobs and failed_jobs are tables.
    config()->set('queue.default', 'database');
});

/**
 * A message delivery job that the worker gave up on: pushed to the
 * database queue, then moved to failed_jobs the way the worker does it.
 */
function failedDelivery(string $queue = 'default', string $error = 'EWS unreachable'): FailedJob
{
    $message = OutboundMessage::factory()->sms()->create();
    SendOutboundMessage::dispatch($message->id)->onQueue($queue);

    $row = DB::table('jobs')->where('queue', $queue)->orderByDesc('id')->first();
    DB::table('jobs')->where('id', $row->id)->delete();
    app('queue.failer')->log('database', $queue, $row->payload, new RuntimeException($error));

    return FailedJob::query()->orderByDesc('id')->firstOrFail();
}

it('opens for an administrator only', function (): void {
    $this->get('/admin/system/queues')->assertOk()->assertSee(__('Queues'));

    $this->flushSession();
    $this->actingAs(User::factory()->withRole(Role::Supervisor)->create());
    expect(Queues::canAccess())->toBeFalse()
        ->and(FailedJobsWidget::canView())->toBeFalse()
        ->and(PendingJobsWidget::canView())->toBeFalse();
    $this->get('/admin/system/queues')->assertForbidden();
});

it('watches the priority queue of the sign-in messages with its own heartbeat', function (): void {
    config()->set('hdid.messages.priority_queue', 'priority');
    $maintenance = app(QueueMaintenance::class);

    expect($maintenance->configuredQueues())->toBe(['default', 'priority'])
        ->and(collect($maintenance->overview())->pluck('name')->all())->toBe(['default', 'priority']);

    // A worker still started with --queue=default never answers the priority heartbeat.
    (new QueueHeartbeat)->handle();
    $tile = app(HealthChecks::class)->queue();
    expect($tile->status)->toBe(CheckStatus::Warn)
        ->and($tile->detail)->toContain('priority: '.__('no worker seen yet'));

    (new QueueHeartbeat('priority'))->handle();
    expect(app(HealthChecks::class)->queue()->status)->toBe(CheckStatus::Ok);

    // Without the variable every message stays on the default queue, and so does the watch.
    config()->set('hdid.messages.priority_queue', null);
    expect($maintenance->configuredQueues())->toBe(['default']);
});

it('lists every configured queue with its own heartbeat and judges each worker', function (): void {
    config()->set('hdid.sync.queue', 'sync');
    $maintenance = app(QueueMaintenance::class);

    expect($maintenance->configuredQueues())->toBe(['default', 'sync'])
        ->and(collect($maintenance->overview())->pluck('name')->all())->toBe(['default', 'sync']);

    (new QueueHeartbeat)->handle();
    $tile = app(HealthChecks::class)->queue();
    expect($tile->status)->toBe(CheckStatus::Warn, 'the sync queue has no worker yet')
        ->and($tile->detail)->toContain('sync: '.__('no worker seen yet'));

    (new QueueHeartbeat('sync'))->handle();
    expect(app(HealthChecks::class)->queue()->status)->toBe(CheckStatus::Ok)
        ->and(Cache::get(QueueHeartbeat::key('sync')))->not->toBeNull();

    Cache::put(QueueHeartbeat::key('sync'), now()->subMinutes(10)->toIso8601String());
    expect(app(HealthChecks::class)->queue()->status)->toBe(CheckStatus::Warn);

    Cache::put(QueueHeartbeat::key('default'), now()->subMinutes(10)->toIso8601String());
    expect(app(HealthChecks::class)->queue()->status)->toBe(CheckStatus::Fail, 'the default worker is the one that must live');

    $this->get('/admin/system/queues')->assertOk()->assertSee('sync')->assertSee('10 minutes ago')->assertSee(__('Clear queue'));
});

it('sends one heartbeat per configured queue from the scheduler, at most one waiting per queue', function (): void {
    config()->set('hdid.sync.queue', 'sync');

    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'hdid:heartbeat');
    $event->run(app());
    $event->run(app());

    expect(DB::table('jobs')->where('queue', 'default')->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'sync')->count())->toBe(1, 'unique while waiting: a dead worker piles up no heartbeats');

    // Clearing the queue lets the next heartbeat through at once.
    app(QueueMaintenance::class)->clearQueue('sync');
    QueueHeartbeat::dispatch('sync');
    expect(DB::table('jobs')->where('queue', 'sync')->count())->toBe(1);
});

it('shows a queue that still holds jobs although no worker is configured for it', function (): void {
    failedDelivery('old-queue');

    $state = collect(app(QueueMaintenance::class)->overview())->firstWhere('name', 'old-queue');

    expect($state->configured)->toBeFalse()
        ->and($state->failed)->toBe(1)
        ->and(app(HealthChecks::class)->queue()->detail)->toContain(__('no worker configured for this queue'));
});

it('runs a failed job again or forgets it from the failed jobs table', function (): void {
    $failed = failedDelivery();
    $forgotten = failedDelivery(error: 'Ozeki refused');

    Livewire::test(FailedJobsWidget::class)
        ->assertCanSeeTableRecords([$failed, $forgotten])
        ->assertTableColumnFormattedStateSet('job', __('Message delivery'), $failed)
        ->assertSee('EWS unreachable')
        ->callTableAction('retry', $failed)
        ->assertNotified(__('The job is back on its queue'))
        ->callTableAction('delete', $forgotten)
        ->assertNotified(__('Failed job deleted'));

    expect(FailedJob::query()->count())->toBe(0)
        ->and(QueuedJob::query()->where('queue', 'default')->count())->toBe(1, 'the retried job waits for the worker again')
        ->and(QueuedJob::query()->first()->attempts)->toBe(0)
        ->and(AuditLog::query()->where('event', 'queue.job_retried')->sole()->context)->toMatchArray(['uuid' => $failed->uuid, 'queue' => 'default', 'job' => SendOutboundMessage::class])
        ->and(AuditLog::query()->where('event', 'queue.job_forgotten')->sole()->context['uuid'])->toBe($forgotten->uuid);
});

it('runs or deletes failed jobs in bulk, all at once, or only the old ones', function (): void {
    $jobs = collect([failedDelivery(), failedDelivery(), failedDelivery()]);

    Livewire::test(FailedJobsWidget::class)
        ->selectTableRecords($jobs->take(2)->all())
        ->callAction(TestAction::make('delete')->table()->bulk())
        ->assertNotified(__(':n failed job(s) deleted', ['n' => 2]));
    expect(FailedJob::query()->count())->toBe(1);

    Livewire::test(FailedJobsWidget::class)
        ->callAction(TestAction::make('retryAll')->table())
        ->assertNotified(__(':n job(s) back on their queues', ['n' => 1]));
    expect(FailedJob::query()->count())->toBe(0)
        ->and(QueuedJob::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'queue.failed_retried_all')->sole()->context['count'])->toBe(1);

    $old = failedDelivery();
    DB::table('failed_jobs')->where('id', $old->id)->update(['failed_at' => now()->subDays(10)]);
    failedDelivery();

    Livewire::test(FailedJobsWidget::class)
        ->callAction(TestAction::make('pruneOld')->table(), ['days' => 7])
        ->assertNotified(__(':n failed job(s) deleted', ['n' => 1]));
    expect(FailedJob::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'queue.failed_pruned')->sole()->context)->toMatchArray(['count' => 1, 'days' => 7]);

    Livewire::test(FailedJobsWidget::class)
        ->selectTableRecords(FailedJob::all()->all())
        ->callAction(TestAction::make('retry')->table()->bulk())
        ->assertNotified(__(':n job(s) back on their queues', ['n' => 1]));

    failedDelivery();
    Livewire::test(FailedJobsWidget::class)
        ->callAction(TestAction::make('deleteAll')->table())
        ->assertNotified(__(':n failed job(s) deleted', ['n' => 1]));
    expect(FailedJob::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'queue.failed_flushed')->sole()->context['count'])->toBe(1);
});

it('deletes a waiting job but leaves one a worker is running', function (): void {
    SendOutboundMessage::dispatch(OutboundMessage::factory()->sms()->create()->id);
    SendOutboundMessage::dispatch(OutboundMessage::factory()->sms()->create()->id);
    [$waiting, $running] = QueuedJob::query()->orderBy('id')->get()->all();
    DB::table('jobs')->where('id', $running->id)->update(['reserved_at' => now()->getTimestamp()]);
    $running->refresh();

    Livewire::test(PendingJobsWidget::class)
        ->assertCanSeeTableRecords([$waiting, $running])
        ->assertTableActionEnabled('delete', $waiting)
        ->assertTableActionDisabled('delete', $running)
        ->callTableAction('delete', $waiting)
        ->assertNotified(__('Waiting job deleted'));

    expect(QueuedJob::query()->pluck('id')->all())->toBe([$running->id])
        ->and(app(QueueMaintenance::class)->deleteWaiting($running))->toBeFalse('a reserved job belongs to its worker')
        ->and(AuditLog::query()->where('event', 'queue.job_deleted')->count())->toBe(1);

    $state = collect(app(QueueMaintenance::class)->overview())->firstWhere('name', 'default');
    expect($state->waiting)->toBe(0)->and($state->reserved)->toBe(1);
});

it('clears a whole queue from the page with confirmation and audits it', function (): void {
    SendOutboundMessage::dispatch(OutboundMessage::factory()->sms()->create()->id);
    SendOutboundMessage::dispatch(OutboundMessage::factory()->sms()->create()->id);

    Livewire::test(Queues::class)
        ->callAction('clearQueue', arguments: ['queue' => 'default'])
        ->assertNotified(__(':n job(s) removed from the ":queue" queue', ['n' => 2, 'queue' => 'default']));

    expect(QueuedJob::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'queue.cleared')->sole()->context)->toMatchArray(['queue' => 'default', 'count' => 2]);
});

it('does not clear a queue it does not know or an empty one', function (): void {
    SendOutboundMessage::dispatch(OutboundMessage::factory()->sms()->create()->id);

    // The button is disabled for an unknown (hence empty) queue, so the call is a no-op.
    Livewire::test(Queues::class)
        ->callAction('clearQueue', arguments: ['queue' => 'nope'])
        ->assertNotNotified();

    expect(QueuedJob::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'queue.cleared')->exists())->toBeFalse();
});

it('prunes failed jobs older than thirty days with the daily retention task', function (): void {
    $old = failedDelivery();
    DB::table('failed_jobs')->where('id', $old->id)->update(['failed_at' => now()->subDays(40)]);
    $recent = failedDelivery();

    $removed = app(Retention::class)->prune();

    expect($removed['failed_jobs'])->toBe(1)
        ->and(FailedJob::query()->pluck('id')->all())->toBe([$recent->id]);
});

it('warns on the queues page, too, while another installation shares the store, and calls no heartbeat alive', function (): void {
    (new QueueHeartbeat)->handle();
    $this->get('/admin/system/queues')->assertOk()->assertSee(__('Alive'))->assertDontSee('data-hdid-shared-store', false);

    // Two sightings of another installation's heartbeat jobs.
    Installation::sighted('0123456789ab', 'queue:default');
    Installation::sighted('0123456789ab', 'queue:default');

    $this->get('/admin/system/queues')->assertOk()
        ->assertSee('data-hdid-shared-store', false)
        ->assertSee('0123456789ab')
        ->assertSee(Installation::id())
        ->assertSee(__('Seen (shared store)'))
        ->assertDontSee('>'.__('Alive').'<', false);
});
