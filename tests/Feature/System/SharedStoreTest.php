<?php

use App\Jobs\QueueHeartbeat;
use App\System\CheckStatus;
use App\System\HealthChecks;
use App\System\Installation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Two installations on one Valkey with the same key prefix: the test cache
 * store plays the shared store, the APP_KEY tells the installations apart.
 */
function sharedStoreProductionKey(): string
{
    return 'base64:'.base64_encode(str_repeat('p', 32));
}

function sharedStoreStagingKey(): string
{
    return 'base64:'.base64_encode(str_repeat('s', 32));
}

function actAsInstallation(string $appKey): void
{
    config()->set('app.key', $appKey);
}

function runSchedulerHeartbeat(): void
{
    collect(app(Schedule::class)->events())->sole(fn ($event): bool => $event->description === 'hdid:heartbeat')->run(app());
}

beforeEach(function (): void {
    // A database queue keeps the dispatched heartbeat jobs waiting, as on a real worker queue.
    config()->set('queue.default', 'database');
    actAsInstallation(sharedStoreProductionKey());
});

it('keeps the scheduler tile green for the scheduler of this installation', function (): void {
    runSchedulerHeartbeat();
    runSchedulerHeartbeat();

    expect(app(HealthChecks::class)->scheduler()->status)->toBe(CheckStatus::Ok);
});

it('turns the scheduler tile red when the last heartbeat came from the scheduler of another installation', function (): void {
    // Only the staging scheduler runs; the production one is stopped.
    actAsInstallation(sharedStoreStagingKey());
    runSchedulerHeartbeat();

    actAsInstallation(sharedStoreProductionKey());
    $check = app(HealthChecks::class)->scheduler();

    expect($check->status)->toBe(CheckStatus::Fail)
        ->and(strtolower($check->detail))->toContain('another installation');
});

it('turns the scheduler and queue tiles red while two installations write the same heartbeats', function (): void {
    (new QueueHeartbeat)->handle();

    // Both schedulers run, each one minute after the other wrote.
    actAsInstallation(sharedStoreProductionKey());
    runSchedulerHeartbeat();
    actAsInstallation(sharedStoreStagingKey());
    runSchedulerHeartbeat();
    actAsInstallation(sharedStoreProductionKey());
    runSchedulerHeartbeat();

    // The last heartbeat is this installation's own, yet the tiles stay red.
    $checks = app(HealthChecks::class);
    expect($checks->scheduler()->status)->toBe(CheckStatus::Fail)
        ->and(strtolower($checks->scheduler()->detail))->toContain('another installation')
        ->and($checks->queue()->status)->toBe(CheckStatus::Fail)
        ->and(strtolower($checks->queue()->detail))->toContain('another installation');
});

it('turns the queue tile red when a worker of another installation runs the heartbeat jobs of this one', function (): void {
    // The production worker is stopped; the staging worker takes the production jobs from the shared queue.
    $first = new QueueHeartbeat;
    $second = new QueueHeartbeat;
    runSchedulerHeartbeat();

    actAsInstallation(sharedStoreStagingKey());
    $first->handle();
    $second->handle();

    actAsInstallation(sharedStoreProductionKey());
    $check = app(HealthChecks::class)->queue();

    expect($check->status)->toBe(CheckStatus::Fail, 'the worker heartbeat is fresh, but it is not this installation\'s worker')
        ->and(strtolower($check->detail))->toContain('another installation');
});

it('does not raise the alarm for one heartbeat left from before an APP_KEY change', function (): void {
    actAsInstallation(sharedStoreStagingKey());
    runSchedulerHeartbeat();
    $leftOver = new QueueHeartbeat;

    // The key was replaced: the next minute's heartbeat and the last waiting job carry the old one.
    actAsInstallation(sharedStoreProductionKey());
    runSchedulerHeartbeat();
    $leftOver->handle();

    $checks = app(HealthChecks::class);
    expect($checks->scheduler()->status)->toBe(CheckStatus::Ok)
        ->and($checks->queue()->status)->toBe(CheckStatus::Ok);
});

it('still reads the heartbeats older releases and the message job write', function (): void {
    Cache::put(HealthChecks::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String());
    Cache::put(QueueHeartbeat::key('default'), now()->toIso8601String());

    $checks = app(HealthChecks::class);
    expect($checks->scheduler()->status)->toBe(CheckStatus::Ok)
        ->and($checks->queue()->status)->toBe(CheckStatus::Ok);
});

it('keeps the heartbeat jobs of two installations from blocking each other while they wait', function (): void {
    QueueHeartbeat::dispatch('default');
    actAsInstallation(sharedStoreStagingKey());
    QueueHeartbeat::dispatch('default');
    QueueHeartbeat::dispatch('default');

    expect(DB::table('jobs')->where('queue', 'default')->count())->toBe(2, 'one waiting heartbeat per installation');
});

it('warns while the keys on Valkey carry the prefix every installation of the same APP_NAME gets', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('command')->with('info')->andReturn(['used_memory' => 1_048_576, 'maxmemory' => 512 * 1_048_576, 'maxmemory_policy' => 'noeviction', 'evicted_keys' => 0]);
    Redis::shouldReceive('connection')->andReturn($connection);
    config()->set(['queue.default' => 'redis', 'app.name' => 'OpenHDID', 'database.redis.options.prefix' => 'openhdid-database-']);

    $check = app(HealthChecks::class)->valkey();
    expect($check->status)->toBe(CheckStatus::Warn)
        ->and($check->detail)->toContain('REDIS_PREFIX', 'openhdid-database-');

    config()->set('database.redis.options.prefix', 'openhdid-prod-');
    expect(app(HealthChecks::class)->valkey()->status)->toBe(CheckStatus::Ok);
});

it('gives the servers of one installation one id whatever way their APP_URL writes the default port or capitals', function (): void {
    config()->set('app.url', 'https://hdid.example.org');
    $id = Installation::id();

    foreach (['https://HDID.example.org/', 'https://hdid.example.org:443', 'https://hdid.example.org:443/'] as $url) {
        config()->set('app.url', $url);
        expect(Installation::id())->toBe($id, $url);
    }

    config()->set('app.url', 'https://hdid.example.org:8443');
    expect(Installation::id())->not->toBe($id);
});
