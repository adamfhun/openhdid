<?php

namespace App\Sync;

use App\Audit\Auditor;
use App\Auth\Permission;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSource;
use App\Jobs\RunDirectorySync;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * EMD syncs started from the panel run in the worker, whether they read
 * the API or a file uploaded on the panel: a web request has a time limit
 * that a large import would hit, leaving the run "running" and the sync
 * lock held. The upload stays on the local disk until the worker has read
 * it (the storage volume is shared), then it is removed.
 */
class QueuedSyncs
{
    /**
     * @param  string|null  $upload  path of an uploaded file on the local disk; null reads the API
     */
    public function start(User $user, bool $dryRun, ?string $upload = null): SyncRun
    {
        $this->authorize($user);
        $connection = config('queue.default');
        $driver = config('queue.connections.'.$connection.'.driver');
        if ($driver === 'sync' || $driver === 'null') {
            throw new RuntimeException(__('EMD sync needs an asynchronous queue connection and a running worker; a large import cannot run inside a web request.'));
        }
        $retryAfter = config('queue.connections.'.$connection.'.retry_after');
        if ($retryAfter !== null && (int) $retryAfter <= RunDirectorySync::TIMEOUT) {
            throw new RuntimeException(__('The queue retry_after must exceed :seconds seconds for EMD sync.', ['seconds' => RunDirectorySync::TIMEOUT]));
        }

        return Cache::lock('hdid:sync-dispatch', 10)->block(1, function () use ($user, $dryRun, $upload): SyncRun {
            if (SyncRun::query()->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])->where('started_at', '>', now()->subHour())->exists()) {
                throw new RuntimeException(__('Another EMD sync is already queued or running.'));
            }

            $run = SyncRun::query()->create([
                'source' => $upload === null ? SyncSource::Api : (str_ends_with(mb_strtolower($upload), '.csv') ? SyncSource::Csv : SyncSource::Xlsx),
                'status' => SyncRunStatus::Queued,
                'started_at' => now(), 'dry_run' => $dryRun,
                'file_name' => $upload === null ? config('hdid.sync.api_url') : basename($upload),
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
        $run = SyncRun::query()->findOrFail($runId);
        if ($run->status !== SyncRunStatus::Queued) {
            return;
        }

        try {
            $user = User::query()->find($run->triggered_by_user_id);
            abort_unless($user !== null, 403);
            $this->authorize($user);
            $readers = app(ReaderFactory::class);
            $reader = $upload === null ? $readers->forApi() : $readers->forFile(Storage::disk('local')->path($upload), basename($upload));
            app(SyncExternalRecords::class)->run($reader, $user, $run->dry_run, $run);
        } catch (Throwable $exception) {
            $this->fail($run->fresh(), $exception->getMessage());
            throw $exception;
        } finally {
            // The upload has served its purpose; it must not linger on disk.
            if ($upload !== null) {
                Storage::disk('local')->delete($upload);
            }
        }
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
}
