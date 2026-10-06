<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Sync\QueuedSyncs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One EMD sync in the worker: from the API, or from a file uploaded on the
 * panel (kept on the local disk until this job has read it). The worker's
 * time limit ends a runaway run and marks it failed; the request that
 * queued it never waits.
 */
class RunDirectorySync implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT = 1200;

    public int $timeout = self::TIMEOUT;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $runId, public readonly ?string $upload = null)
    {
        $this->onQueue(config('hdid.sync.queue'));
    }

    public function handle(QueuedSyncs $syncs): void
    {
        $syncs->execute($this->runId, $this->upload);
    }

    public function failed(?Throwable $exception): void
    {
        if ($run = SyncRun::query()->find($this->runId)) {
            app(QueuedSyncs::class)->fail($run, __('EMD sync worker stopped before completion (time limit of :seconds seconds, or a crash); check the worker log.', ['seconds' => self::TIMEOUT]));
        }

        if ($this->upload !== null) {
            Storage::disk('local')->delete($this->upload);
        }
    }
}
