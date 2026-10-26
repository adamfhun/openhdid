<?php

namespace App\System;

use App\Audit\Auditor;
use App\Jobs\QueueHeartbeat;
use App\Jobs\RunDirectorySync;
use App\Jobs\SendOutboundMessage;
use App\Messaging\Messenger;
use App\Models\FailedJob;
use App\Models\QueuedJob;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * What the server console would otherwise do with the queue: list every
 * worker queue with its heartbeat and load, run failed jobs again or forget
 * them, drop waiting ones, clear a queue. The framework's own commands do
 * the work (queue:retry, queue:clear), so the outcome is exactly what the
 * console gives; every write is audited.
 */
class QueueMaintenance
{
    /** Failed jobs older than this are deleted by the daily prune task. */
    public const FAILED_JOB_DAYS = 30;

    public function __construct(
        private readonly Auditor $auditor,
        private readonly Cache $cache,
        private readonly QueueFactory $queue,
        private readonly FailedJobProviderInterface $failer,
    ) {}

    public function connection(): string
    {
        return (string) config('queue.default');
    }

    /**
     * Only the database driver can list waiting jobs one by one; the others
     * know their length.
     */
    public function usesDatabase(): bool
    {
        return $this->connection() === 'database';
    }

    public function isClearable(): bool
    {
        return $this->connection() !== 'sync';
    }

    /**
     * Queues a worker is expected to listen on: the default one, the priority
     * queue of the sign-in messages (HDID_PRIORITY_QUEUE) and the EMD sync
     * queue, each when it is a separate one. The scheduler sends a heartbeat
     * to each of these, so a worker that does not listen on one shows.
     *
     * @return list<string>
     */
    public function configuredQueues(): array
    {
        return array_values(array_unique(array_filter(['default', Messenger::priorityQueue(), (string) config('hdid.sync.queue', 'default')])));
    }

    /**
     * The configured queues plus any queue that still holds waiting or
     * failed jobs: a renamed queue leaves its jobs behind, and they must
     * stay visible and removable.
     *
     * @return list<string>
     */
    public function queues(): array
    {
        $names = $this->configuredQueues();

        try {
            if ($this->usesDatabase()) {
                $names = [...$names, ...QueuedJob::query()->distinct()->pluck('queue')->all()];
            }

            $names = [...$names, ...FailedJob::query()->distinct()->pluck('queue')->all()];
        } catch (Throwable) {
            // The tables may not exist yet; the configured list is all we know.
        }

        return array_values(array_unique(array_map('strval', $names)));
    }

    public function heartbeat(string $queue): ?Carbon
    {
        $value = $this->cache->get(QueueHeartbeat::key($queue));

        return is_string($value) ? Carbon::parse($value) : null;
    }

    /**
     * @return list<QueueState>
     */
    public function overview(): array
    {
        $configured = $this->configuredQueues();
        $failed = [];

        try {
            $failed = FailedJob::query()->selectRaw('queue, count(*) as n')->groupBy('queue')->pluck('n', 'queue')->all();
        } catch (Throwable) {
            // No failed_jobs table yet.
        }

        $states = [];

        foreach ($this->queues() as $name) {
            $waiting = null;
            $reserved = null;
            $oldest = null;

            try {
                if ($this->usesDatabase()) {
                    $waiting = QueuedJob::query()->where('queue', $name)->whereNull('reserved_at')->count();
                    $reserved = QueuedJob::query()->where('queue', $name)->whereNotNull('reserved_at')->count();
                    $oldestTs = QueuedJob::query()->where('queue', $name)->whereNull('reserved_at')->min('created_at');
                    $oldest = $oldestTs ? Carbon::createFromTimestamp((int) $oldestTs) : null;
                } elseif ($this->connection() !== 'sync') {
                    $waiting = $this->queue->connection($this->connection())->size($name);
                }
            } catch (Throwable) {
                // Unreachable store: the heartbeat and the failed count still tell the story.
            }

            $states[] = new QueueState($name, in_array($name, $configured, true), $this->heartbeat($name), $waiting, $reserved, $oldest, (int) ($failed[$name] ?? 0));
        }

        return $states;
    }

    /**
     * Push a failed job back onto its queue (queue:retry) and forget the
     * failed copy. False when it was already gone.
     */
    public function retryFailed(FailedJob $job): bool
    {
        if ($this->failer->find($job->uuid) === null) {
            return false;
        }

        Artisan::call('queue:retry', ['id' => [$job->uuid]]);
        $this->auditor->record('queue.job_retried', context: $this->jobContext($job));

        return $this->failer->find($job->uuid) === null;
    }

    public function retryAllFailed(): int
    {
        $count = FailedJob::query()->count();

        if ($count === 0) {
            return 0;
        }

        Artisan::call('queue:retry', ['id' => ['all']]);
        $this->auditor->record('queue.failed_retried_all', context: ['count' => $count]);

        return $count;
    }

    public function forgetFailed(FailedJob $job): bool
    {
        $forgotten = (bool) $this->failer->forget($job->uuid);

        if ($forgotten) {
            $this->auditor->record('queue.job_forgotten', context: $this->jobContext($job));
        }

        return $forgotten;
    }

    public function flushFailed(): int
    {
        $count = FailedJob::query()->count();

        if ($count > 0) {
            $this->failer->flush();
            $this->auditor->record('queue.failed_flushed', context: ['count' => $count]);
        }

        return $count;
    }

    /**
     * Delete failed jobs older than the given number of days. The caller
     * audits (the daily prune task reports it with its other counts).
     */
    public function pruneFailed(int $days): int
    {
        if (! $this->failer instanceof PrunableFailedJobProvider) {
            return 0;
        }

        return $this->failer->prune(now()->subDays(max(1, $days)));
    }

    /**
     * Drop one waiting job. A reserved job belongs to a worker right now
     * and is left alone: the queue releases it by itself after retry_after.
     */
    public function deleteWaiting(QueuedJob $job): bool
    {
        $deleted = QueuedJob::query()->whereKey($job->getKey())->whereNull('reserved_at')->delete() === 1;

        if ($deleted) {
            $this->auditor->record('queue.job_deleted', context: $this->jobContext($job));
        }

        return $deleted;
    }

    /**
     * Remove every job of one queue, waiting and reserved alike (queue:clear).
     */
    public function clearQueue(string $queue): int
    {
        if (! $this->isClearable()) {
            return 0;
        }

        $connection = $this->connection();
        $count = $this->usesDatabase()
            ? QueuedJob::query()->where('queue', $queue)->count()
            : $this->queue->connection($connection)->size($queue);

        Artisan::call('queue:clear', ['connection' => $connection, '--queue' => $queue, '--force' => true]);

        // A heartbeat deleted with the queue would keep its unique lock for ten
        // minutes and leave the queue without a pulse; let the next one through.
        (new UniqueLock($this->cache))->release(new QueueHeartbeat($queue));

        $this->auditor->record('queue.cleared', context: ['queue' => $queue, 'connection' => $connection, 'count' => $count]);

        return $count;
    }

    /**
     * What a queue payload is, in words: a label for the job class and, for
     * a message delivery, the message it carries.
     *
     * @param  array<string, mixed>  $payload
     * @return array{class: string, label: string, message_id: ?string}
     */
    public function describe(array $payload): array
    {
        $class = (string) ($payload['displayName'] ?? $payload['job'] ?? '');

        $label = match ($class) {
            SendOutboundMessage::class => __('Message delivery'),
            RunDirectorySync::class => __('EMD sync'),
            QueueHeartbeat::class => __('Worker heartbeat'),
            default => $class === '' ? __('Unknown job') : class_basename($class),
        };

        $messageId = null;

        if ($class === SendOutboundMessage::class && preg_match('/"messageId";s:\d+:"([0-9a-f-]{36})"/', (string) ($payload['data']['command'] ?? ''), $match)) {
            $messageId = $match[1];
        }

        return ['class' => $class, 'label' => $label, 'message_id' => $messageId];
    }

    /**
     * @return array<string, mixed>
     */
    private function jobContext(FailedJob|QueuedJob $job): array
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $described = $this->describe($payload);

        return array_filter([
            'uuid' => $job instanceof FailedJob ? $job->uuid : ($payload['uuid'] ?? null),
            'queue' => $job->queue,
            'job' => $described['class'],
            'message_id' => $described['message_id'],
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
