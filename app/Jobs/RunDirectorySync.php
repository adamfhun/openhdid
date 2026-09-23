<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Sync\QueuedSyncs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunDirectorySync implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT = 1200;

    public int $timeout = self::TIMEOUT;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $runId)
    {
        $this->onQueue(config('hdid.sync.queue'));
    }

    public function handle(QueuedSyncs $syncs): void
    {
        $syncs->execute($this->runId);
    }

    public function failed(?Throwable $exception): void
    {
        if ($run = SyncRun::query()->find($this->runId)) {
            app(QueuedSyncs::class)->fail($run, __('EMD sync worker stopped before completion; check the worker log and timeout.'));
        }
    }
}
