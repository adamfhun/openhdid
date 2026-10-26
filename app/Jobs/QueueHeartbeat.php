<?php

namespace App\Jobs;

use App\System\Installation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Proves a queue worker is alive: dispatched by the scheduler every minute
 * for every configured queue, it stamps the cache for its queue when it
 * actually runs. Unique per queue (and installation) while it waits, so a
 * queue nobody listens to collects at most one heartbeat per ten minutes
 * instead of one a minute.
 *
 * The job carries the installation that sent it. A worker of another
 * installation running it means two installations share one Valkey/Redis
 * and key prefix, and with it their queues: the worker records the
 * sighting, and the status pages of both turn red (see Installation).
 */
class QueueHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;

    /** The installation that sent the heartbeat; null in a job queued by an older release. */
    public ?string $origin = null;

    public function __construct(public readonly string $queueName = 'default')
    {
        $this->origin = Installation::id();
        $this->onQueue($queueName);
    }

    public function uniqueId(): string
    {
        return $this->origin === null ? $this->queueName : $this->queueName.':'.$this->origin;
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
        if ($this->origin !== null && $this->origin !== Installation::id()) {
            Installation::sighted($this->origin, 'queue:'.$this->queueName);
        }

        Cache::put(self::key($this->queueName), now()->toIso8601String(), now()->addHours(6));
    }
}
