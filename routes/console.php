<?php

use App\CallCenter\CallCenterService;
use App\Clients\ClientLinks;
use App\Clients\PackageOverrides;
use App\Identification\QaSessionEngine;
use App\Jobs\QueueHeartbeat;
use App\Messaging\Messenger;
use App\Messaging\SmsDeliveryChecks;
use App\Models\OutboundMessage;
use App\System\HealthChecks;
use App\System\Installation;
use App\System\QueueMaintenance;
use Illuminate\Support\Facades\Schedule;

/*
 * Every overlap guard (withoutOverlapping) carries a lifetime that fits the
 * task's run time, in minutes. Laravel's default is 1440: a scheduler that
 * stopped during a run (an upgrade, a restart) left the lock behind, and the
 * task was skipped for up to a day (the EMD token refresh every 15 minutes
 * among them). "php artisan schedule:clear-cache" releases a stuck lock at once.
 */

if ($cron = config('hdid.sync.cron')) {
    // The sync job may run up to 1200 s and holds its own hdid:sync lock; an hour covers both.
    Schedule::command('hdid:emd-sync')->cron($cron)->name('hdid:emd-sync')->withoutOverlapping(60)->onOneServer()->runInBackground();
}

Schedule::command('hdid:emd-sync-token')->everyFifteenMinutes()
    ->name('hdid:emd-sync-token')->withoutOverlapping(10)->onOneServer()->runInBackground()
    ->when(fn (): bool => config('hdid.sync.driver') === 'spreadsheet' && config('hdid.sync.token_keep_alive'));

Schedule::call(fn () => app(QaSessionEngine::class)->expireStale())
    ->everyMinute()
    ->name('hdid:expire-id-sessions')
    ->withoutOverlapping(5);

Schedule::call(fn () => app(PackageOverrides::class)->endExpired())
    ->hourly()
    ->name('hdid:expire-package-overrides');

// A sponsor whose package left the premium list (settings change) stops sponsoring.
Schedule::call(fn () => app(ClientLinks::class)->endLinksOfIneligibleSponsors())
    ->hourly()
    ->name('hdid:end-stale-links')
    ->withoutOverlapping(30);

Schedule::call(fn () => app(CallCenterService::class)->pruneEnded())
    ->hourly()
    ->name('hdid:prune-calls');

// Calls whose end event never arrived are closed as missed after a while.
Schedule::call(fn () => app(CallCenterService::class)->expireStale())
    ->hourly()
    ->name('hdid:expire-stale-calls')
    ->withoutOverlapping(30);

// A command, not a closure: the audit archiving inside may take minutes on a
// large installation, and the scheduler must not wait for it.
Schedule::command('hdid:prune')
    ->dailyAt('03:30')
    ->withoutOverlapping(240)
    ->onOneServer()
    ->runInBackground();

// One heartbeat per configured queue: the default worker and, when the sign-in
// messages (HDID_PRIORITY_QUEUE) or the EMD sync run on their own queue, those
// queues too (unique per queue while waiting).
// The scheduler heartbeat names this installation: another one writing the
// same key means a shared Valkey/Redis and key prefix (see Installation).
Schedule::call(function (): void {
    Installation::beat(HealthChecks::SCHEDULER_HEARTBEAT_KEY, 'scheduler');

    foreach (app(QueueMaintenance::class)->configuredQueues() as $queue) {
        QueueHeartbeat::dispatch($queue);
    }
})->everyMinute()->name('hdid:heartbeat');

// Fallback for messages no worker picked up: re-dispatch them, a sign-in
// message on the priority queue again.
Schedule::call(function (): void {
    $messenger = app(Messenger::class);

    OutboundMessage::query()->stale()->orderBy('id')->limit(200)->get(['id', 'meta'])
        ->each(function (OutboundMessage $message) use ($messenger): void {
            OutboundMessage::query()->whereKey($message->id)->update(['status' => 'queued', 'updated_at' => now()]);
            $messenger->dispatch($message);
        });
})->everyMinute()->name('hdid:requeue-messages')->withoutOverlapping(5);

// Delivery reports of sent SMS: ask the gateway for every message whose next query is due.
Schedule::call(fn () => app(SmsDeliveryChecks::class)->run())
    ->everyMinute()->name('hdid:sms-delivery-checks')->withoutOverlapping(5)->onOneServer();
