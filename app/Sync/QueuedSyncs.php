<?php

namespace App\Sync;

use App\Audit\Auditor;
use App\Auth\Permission;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSource;
use App\Jobs\RunDirectorySync;
use App\Models\SyncRun;
use App\Models\User;
use App\System\Installation;
use App\System\Retention;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * EMD syncs started from the panel run in the worker, whether they read
 * the API or a file uploaded on the panel: a web request has a time limit
 * that a large import would hit, leaving the run "running" and the sync
 * lock held. The upload stays on the local disk until the worker has read
 * it (the storage volume is shared), then it is removed, whatever happened:
 * also when the run is no longer waiting, and when its job is deleted from
 * the queue (abandon()). The worker claims the run with a conditional
 * update (queued → running), so a run is either taken by the worker or
 * abandoned, never both.
 */
class QueuedSyncs
{
    /**
     * The only shape of path the import reads and deletes: the name the
     * import field gives a fresh upload in the upload directory (40 random
     * characters and .csv or .xlsx, by the extension the user chose). The path travels in the browser's
     * form state and in the queued job, so anything else (the audit archive
     * and its checksums, any other file of the disk) is refused, never read
     * and never deleted. That the path is the requester's own fresh upload
     * is the form's check (preventFilePathTampering on the import field).
     */
    public static function isUpload(?string $path): bool
    {
        return $path !== null && preg_match('/\A'.preg_quote(Retention::UPLOAD_DIRECTORY, '/').'\/[A-Za-z0-9]{40}\.(csv|xlsx)\z/', $path) === 1;
    }

    /**
     * Removes an upload of the import; a path of any other shape stays untouched.
     */
    public function discardUpload(?string $upload): void
    {
        if (self::isUpload($upload)) {
            Storage::disk('local')->delete($upload);
        }
    }

    /**
     * @param  string|null  $upload  path of an uploaded file on the local disk; null reads the API
     */
    public function start(User $user, bool $dryRun, ?string $upload = null): SyncRun
    {
        $this->authorize($user);
        $this->assertUpload($upload);
        $connection = config('queue.default');
        $driver = config('queue.connections.'.$connection.'.driver');
        if ($driver === 'sync' || $driver === 'null') {
            throw new RuntimeException(__('EMD sync needs an asynchronous queue connection and a running worker; a large import cannot run inside a web request.'));
        }
        $retryAfter = config('queue.connections.'.$connection.'.retry_after');
        if ($retryAfter !== null && (int) $retryAfter <= RunDirectorySync::TIMEOUT) {
            throw new RuntimeException(__('The queue retry_after must exceed :seconds seconds for EMD sync.', ['seconds' => RunDirectorySync::TIMEOUT]));
        }

        return Cache::lock(Installation::lockName('hdid:sync-dispatch'), 10)->block(1, function () use ($user, $dryRun, $upload): SyncRun {
            if (SyncRun::query()->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])->where('started_at', '>', now()->subHour())->exists()) {
                throw new RuntimeException(__('Another EMD sync is already queued or running.'));
            }

            $run = SyncRun::query()->create([
                'source' => $upload === null ? SyncSource::Api : (str_ends_with(mb_strtolower($upload), '.csv') ? SyncSource::Csv : SyncSource::Xlsx),
                'status' => SyncRunStatus::Queued,
                'started_at' => now(), 'dry_run' => $dryRun,
                'file_name' => SourceLabel::of($upload === null ? config('hdid.sync.api_url') : basename($upload)),
                'triggered_by_user_id' => $user->id,
            ]);

            try {
                RunDirectorySync::dispatch($run->id, $upload);
            } catch (Throwable $exception) {
                $this->fail($run, __('EMD sync could not be queued.'));
                throw $exception;
            }

            return $run;
        });
    }

    public function execute(string $runId, ?string $upload = null): void
    {
        try {
            // Failed, completed, abandoned or gone meanwhile: nothing to run.
            if (! $this->claim($runId)) {
                return;
            }

            $run = SyncRun::query()->findOrFail($runId);

            try {
                $user = User::query()->find($run->triggered_by_user_id);
                abort_unless($user !== null, 403);
                $this->authorize($user);
                $this->assertUpload($upload);
                $readers = app(ReaderFactory::class);
                $reader = $upload === null ? $readers->forApi() : $readers->forFile(Storage::disk('local')->path($upload), basename($upload));
                app(SyncExternalRecords::class)->run($reader, $user, $run->dry_run, $run);
            } catch (Throwable $exception) {
                $this->fail($run->fresh() ?? $run, $exception->getMessage());
                throw $exception;
            }
        } finally {
            // The upload has served its purpose; it must not linger on disk.
            $this->discardUpload($upload);
        }
    }

    /**
     * The worker takes a waiting run: only one of the worker and abandon()
     * can move it out of the queued state.
     */
    private function claim(string $runId): bool
    {
        return SyncRun::query()->whereKey($runId)->where('status', SyncRunStatus::Queued)
            ->update(['status' => SyncRunStatus::Running, 'updated_at' => now()]) === 1;
    }

    /**
     * A waiting run whose job left the queue without a worker (deleted or
     * cleared on the Queues page, or never picked up): failed with the
     * reason, audited, and its upload deleted. A run the worker already
     * claimed is left to the worker. Returns whether this call abandoned it.
     */
    public function abandon(string $runId, string $reason): bool
    {
        $message = __('EMD sync abandoned before a worker started it (:reason); the uploaded file was deleted. Start the sync again.', ['reason' => $reason]);
        $abandoned = SyncRun::query()->whereKey($runId)->where('status', SyncRunStatus::Queued)
            ->update(['status' => SyncRunStatus::Failed, 'finished_at' => now(), 'error' => $message, 'updated_at' => now()]) === 1;

        if (! $abandoned) {
            return false;
        }

        $run = SyncRun::query()->findOrFail($runId);
        app(Auditor::class)->record('sync.failed', $run, ['error' => $message, 'dry_run' => $run->dry_run, 'abandoned' => true, 'reason' => $reason]);
        $this->discardUpload($run->file_name === null ? null : Retention::UPLOAD_DIRECTORY.'/'.$run->file_name);

        return true;
    }

    public function fail(SyncRun $run, string $message): void
    {
        if (! in_array($run->status, [SyncRunStatus::Queued, SyncRunStatus::Running], true)) {
            return;
        }
        $run->forceFill(['status' => SyncRunStatus::Failed, 'finished_at' => now(), 'error' => $message])->save();
        app(Auditor::class)->record('sync.failed', $run, ['error' => $message, 'dry_run' => $run->dry_run]);
    }

    private function authorize(User $user): void
    {
        abort_unless(! $user->isClosed() && $user->can(Permission::SyncManage->value), 403);
    }

    private function assertUpload(?string $upload): void
    {
        if ($upload !== null && ! self::isUpload($upload)) {
            throw new InvalidArgumentException(__('The import accepts only a file uploaded on the EMD sync runs page.'));
        }
    }
}
