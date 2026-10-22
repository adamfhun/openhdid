<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Proves a queue worker is alive: dispatched by the scheduler every minute
 * for every configured queue, it stamps the cache for its queue when it
 * actually runs. Unique per queue while it waits, so a queue nobody listens
 * to collects at most one heartbeat per ten minutes instead of one a minute.
 */
class QueueHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $queueName = 'default')
    {
        $this->onQueue($queueName);
    }

    public function uniqueId(): string
    {
        return $this->queueName;
    }

    /**
     * The cache key holding the last heartbeat of a queue. The default
     * queue keeps the historical key the message job stamps as well.
     */
    public static function key(string $queue): string
    {
        return $queue === 'default' ? SendOutboundMessage::HEARTBEAT_KEY : SendOutboundMessage::HEARTBEAT_KEY.'.'.$queue;
    }

    public function handle(): void
    {
        Cache::put(self::key($this->queueName), now()->toIso8601String(), now()->addHours(6));
    }
}
