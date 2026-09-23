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
use RuntimeException;
use Throwable;

class QueuedSyncs
{
    public function start(User $user, bool $dryRun): SyncRun
    {
        $this->authorize($user);
        $connection = config('queue.default');
        $driver = config('queue.connections.'.$connection.'.driver');
        if ($driver === 'sync' || $driver === 'null') {
            throw new RuntimeException(__('EMD API sync needs an asynchronous queue connection and a running worker.'));
        }
        $retryAfter = config('queue.connections.'.$connection.'.retry_after');
        if ($retryAfter !== null && (int) $retryAfter <= RunDirectorySync::TIMEOUT) {
            throw new RuntimeException(__('The queue retry_after must exceed :seconds seconds for EMD sync.', ['seconds' => RunDirectorySync::TIMEOUT]));
        }

        return Cache::lock('hdid:sync-dispatch', 10)->block(1, function () use ($user, $dryRun): SyncRun {
            if (SyncRun::query()->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])->where('started_at', '>', now()->subHour())->exists()) {
                throw new RuntimeException(__('Another EMD sync is already queued or running.'));
            }

            $run = SyncRun::query()->create([
                'source' => SyncSource::Api, 'status' => SyncRunStatus::Queued,
                'started_at' => now(), 'dry_run' => $dryRun,
                'file_name' => config('hdid.sync.api_url'), 'triggered_by_user_id' => $user->id,
            ]);

            try {
                RunDirectorySync::dispatch($run->id);
            } catch (Throwable $exception) {
                $this->fail($run, __('EMD sync could not be queued.'));
                throw $exception;
            }

            return $run;
        });
    }

    public function execute(string $runId): void
    {
        $run = SyncRun::query()->findOrFail($runId);
        if ($run->status !== SyncRunStatus::Queued) {
            return;
        }

        try {
            $user = User::query()->find($run->triggered_by_user_id);
            abort_unless($user !== null, 403);
            $this->authorize($user);
            app(SyncExternalRecords::class)->run(app(ReaderFactory::class)->forApi(), $user, $run->dry_run, $run);
        } catch (Throwable $exception) {
            $this->fail($run->fresh(), $exception->getMessage());
            throw $exception;
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
