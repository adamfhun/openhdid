<?php

use App\CallCenter\CallCenterService;
use App\Clients\PackageOverrides;
use App\Identification\QaSessionEngine;
use App\Jobs\QueueHeartbeat;
use App\Jobs\SendOutboundMessage;
use App\Models\OutboundMessage;
use App\System\HealthChecks;
use App\System\Retention;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

if ($cron = config('hdid.sync.cron')) {
    Schedule::command('hdid:emd-sync')->cron($cron)->withoutOverlapping()->onOneServer()->runInBackground();
}

Schedule::command('hdid:emd-sync-token')->everyFifteenMinutes()
    ->name('hdid:emd-sync-token')->withoutOverlapping()->onOneServer()->runInBackground()
    ->when(fn (): bool => config('hdid.sync.driver') === 'spreadsheet' && config('hdid.sync.token_keep_alive'));

Schedule::call(fn () => app(QaSessionEngine::class)->expireStale())
    ->everyMinute()
    ->name('hdid:expire-id-sessions')
    ->withoutOverlapping();

Schedule::call(fn () => app(PackageOverrides::class)->endExpired())
    ->hourly()
    ->name('hdid:expire-package-overrides');

Schedule::call(fn () => app(CallCenterService::class)->pruneEnded())
    ->hourly()
    ->name('hdid:prune-calls');

// Calls whose end event never arrived are closed as missed after a while.
Schedule::call(fn () => app(CallCenterService::class)->expireStale())
    ->hourly()
    ->name('hdid:expire-stale-calls')
    ->withoutOverlapping();

Schedule::call(fn () => app(Retention::class)->prune())
    ->dailyAt('03:30')
    ->name('hdid:prune')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(function (): void {
    Cache::put(HealthChecks::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String(), now()->addHours(6));
    QueueHeartbeat::dispatch();
})->everyMinute()->name('hdid:heartbeat');

// Fallback for messages no worker picked up: re-dispatch them.
Schedule::call(function (): void {
    OutboundMessage::query()->stale()->orderBy('id')->limit(200)->pluck('id')
        ->each(function (string $id): void {
            OutboundMessage::query()->whereKey($id)->update(['status' => 'queued', 'updated_at' => now()]);
            SendOutboundMessage::dispatch($id);
        });
})->everyMinute()->name('hdid:requeue-messages')->withoutOverlapping();
