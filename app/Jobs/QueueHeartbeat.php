<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Proves a queue worker is alive: dispatched by the scheduler every minute,
 * it stamps the cache when it actually runs.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::put(SendOutboundMessage::HEARTBEAT_KEY, now()->toIso8601String(), now()->addHours(6));
    }
}
