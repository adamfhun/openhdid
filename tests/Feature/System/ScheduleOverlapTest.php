<?php

use App\System\HealthChecks;
use App\System\InstallationEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;

/*
 * The overlap guards of the scheduled tasks (withoutOverlapping): Laravel keeps
 * such a lock for 1440 minutes by default, so a scheduler stopped during a run
 * (an upgrade, a restart) skipped the task for up to a day; the 15-minute EMD
 * token refresh was the live case. Every lock now lives about as long as its
 * task may run, and schedule:clear-cache releases a stuck one at once.
 */

/**
 * The scheduled events of routes/console.php, read into a fresh schedule with
 * the EMD sync switched on (it is only scheduled with EMD_SYNC_CRON).
 *
 * @return array<string, Event>
 */
function overlapEvents(): array
{
    config()->set('hdid.sync.cron', '0 */3 * * *');
    $schedule = new Schedule;
    app()->instance(Schedule::class, $schedule);
    Facade::clearResolvedInstance(Schedule::class);
    require base_path('routes/console.php');

    $events = [];
    foreach ($schedule->events() as $event) {
        // A command without a name of its own goes by its artisan command (hdid:prune).
        $events[$event->description ?: (string) preg_replace('/^.*artisan\'?\s+/', '', (string) $event->command)] = $event;
    }

    return $events;
}

it('gives every overlapping scheduled task a lock lifetime that fits its run time', function (): void {
    $events = overlapEvents();

    $lifetimes = [];
    foreach ($events as $name => $event) {
        if ($event->withoutOverlapping) {
            $lifetimes[$name] = $event->expiresAt;
        }
    }
    ksort($lifetimes);

    expect($lifetimes)->toBe([
        'hdid:emd-sync' => 60,
        'hdid:emd-sync-token' => 10,
        'hdid:end-stale-links' => 30,
        'hdid:expire-id-sessions' => 5,
        'hdid:expire-stale-calls' => 30,
        'hdid:prune' => 240,
        'hdid:requeue-messages' => 5,
        'hdid:sms-delivery-checks' => 5,
    ]);
    foreach ($lifetimes as $name => $minutes) {
        expect($minutes)->toBeLessThan(1440, $name);
    }
});

it('names the EMD sync so the scheduler list and install.sh check can tell it', function (): void {
    $events = overlapEvents();

    expect($events)->toHaveKey('hdid:emd-sync')
        ->and((string) $events['hdid:emd-sync']->command)->toContain('hdid:emd-sync')
        ->and($events['hdid:emd-sync']->runInBackground)->toBeTrue();
});

it('releases a held overlap lock with schedule:clear-cache', function (): void {
    $event = collect(app(Schedule::class)->events())->sole(fn (Event $event): bool => $event->description === 'hdid:emd-sync-token');

    expect($event->mutex)->toBeInstanceOf(InstallationEventMutex::class)
        ->and($event->mutex->create($event))->toBeTrue()
        ->and($event->mutex->exists($event))->toBeTrue()
        ->and($event->shouldSkipDueToOverlapping())->toBeTrue();

    $this->artisan('schedule:clear-cache')->assertSuccessful();

    expect($event->mutex->exists($event))->toBeFalse()
        ->and($event->shouldSkipDueToOverlapping())->toBeFalse('the next run may start');
});

it('names schedule:clear-cache in the scheduler, sync, sync_auth and retention advice', function (): void {
    foreach (['scheduler', 'sync', 'retention'] as $key) {
        expect(str_contains((string) HealthChecks::hint($key), 'docker compose exec scheduler php artisan schedule:clear-cache'))->toBeTrue($key);
    }
    foreach ([true, false] as $keepAlive) {
        config()->set('hdid.sync.token_keep_alive', $keepAlive);
        expect(HealthChecks::hint('sync_auth'))->toContain('schedule:clear-cache');
    }

    app()->setLocale('hu');
    expect(HealthChecks::hint('scheduler'))->toContain('az ütemező egy újraindítása után', 'schedule:clear-cache');
});
