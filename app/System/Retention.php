<?php

namespace App\System;

use App\Audit\AuditArchive;
use App\Audit\Auditor;
use App\Enums\PrincipalType;
use App\Enums\SyncRunStatus;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\ClientPhoneNumber;
use App\Models\ExternalRecord;
use App\Models\IdSession;
use App\Models\OneTimeCode;
use App\Models\OutboundMessage;
use App\Models\SsoIdentity;
use App\Models\SyncRun;
use App\Models\SyncRunChange;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\QueuedSyncs;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * Deletes for good what the retention settings no longer allow us to keep:
 * business records after the general period (default 5 years), deleted
 * accounts the general period after their deletion (until then they can be
 * restored; then they go with what they carried: answers, numbers, codes,
 * links, company sign-in binding, tokens, roles), throwaway
 * artefacts such as expired one-time codes and the EMD change records after
 * the short-lived period (default 2 years), failed queue jobs after a fixed
 * thirty days (QueueMaintenance). The audit log is archived first: entries
 * past the hot window leave the database for daily archive files
 * (AuditArchive), and the files themselves fall under the general period.
 *
 * What has no use once its moment passed goes at the next run, whatever the
 * periods: an uploaded directory file a day after upload unless a waiting
 * or running sync still names it (a sync nobody picked up within a day is
 * failed first), the decryptable copy and the lookup of a one-time code that
 * can no longer be used (expired or out of attempts; the row stays for the
 * short-lived period), API tokens past their expiry or lifetime, company
 * sign-in bindings whose account no longer exists (a closed or deleted
 * account keeps its binding: it may be reopened or restored, decision Q7),
 * and expired rows of a database cache store. Runs daily; the status page
 * warns when it has not run for a few days.
 */
class Retention
{
    public const LAST_RUN_KEY = 'hdid.retention.last_run';

    public const UPLOAD_DIRECTORY = 'sync-uploads';

    private const BATCH = 1000;

    public function __construct(
        private readonly Settings $settings,
        private readonly Cache $cache,
        private readonly Auditor $auditor,
        private readonly AuditArchive $archive,
        private readonly QueueMaintenance $queue,
    ) {}

    public function generalCutoff(): Carbon
    {
        return now()->subYears(max(1, $this->settings->int(SettingKey::RetentionGeneralYears)));
    }

    /**
     * The day the daily prune deletes for good an account deleted at the
     * given time (now: one being deleted); until then it can be restored.
     */
    public function purgeDateOf(?CarbonInterface $deletedAt = null): Carbon
    {
        return Carbon::instance($deletedAt ?? now())->addYears(max(1, $this->settings->int(SettingKey::RetentionGeneralYears)))->startOfDay()->addDay();
    }

    public function shortLivedCutoff(): Carbon
    {
        return now()->subYears(max(1, $this->settings->int(SettingKey::RetentionShortLivedYears)));
    }

    /**
     * @return array<string, int> rows (or files) removed per table
     */
    public function prune(): array
    {
        $general = $this->generalCutoff();
        $short = $this->shortLivedCutoff();

        // Archive before deleting: whatever the general period would drop from
        // the audit log has long been archived, the table only holds the hot days.
        $archived = $this->archive->archive();

        $removed = [
            'audit_archived' => $archived['rows'],
            'id_sessions' => $this->deleteInBatches(fn () => IdSession::query()->withTrashed()->where('started_at', '<', $general)->limit(self::BATCH)->forceDelete()),
            'calls' => $this->deleteInBatches(fn () => Call::query()->withTrashed()->where('arrived_at', '<', $general)->limit(self::BATCH)->forceDelete()),
            'outbound_messages' => $this->deleteInBatches(fn () => OutboundMessage::query()->where('created_at', '<', $general)->limit(self::BATCH)->delete()),
            'sync_runs' => $this->deleteInBatches(fn () => SyncRun::query()->withTrashed()->where('started_at', '<', $general)->limit(self::BATCH)->forceDelete()),
            ...$this->purgeDeletedAccounts($general),
            // Query-level: a model-level delete of a number would clear a live PIN (ClientPhones::afterRemoval).
            'deleted_phone_numbers' => $this->deleteInBatches(fn () => ClientPhoneNumber::onlyTrashed()->where('deleted_at', '<', $general)->limit(self::BATCH)->forceDelete()),
            // A deleted record an account still points to stays: deleting it would unbind the account.
            'deleted_records' => $this->deleteInBatches(fn () => ExternalRecord::onlyTrashed()->where('deleted_at', '<', $general)
                ->whereDoesntHave('client', fn ($query) => $query->withTrashed())
                ->whereDoesntHave('user', fn ($query) => $query->withTrashed())
                ->limit(self::BATCH)->forceDelete()),
            'audit_log' => $this->deleteInBatches(fn () => AuditLog::query()->where('created_at', '<', $general)->limit(self::BATCH)->delete()),
            'audit_archive_files' => $this->archive->pruneFiles($general),
            'sync_run_changes' => $this->deleteInBatches(fn () => SyncRunChange::query()->where('created_at', '<', $short)->limit(self::BATCH)->delete()),
            'one_time_codes' => $this->deleteInBatches(fn () => OneTimeCode::query()->where('expires_at', '<', $short)->limit(self::BATCH)->delete()),
            'one_time_code_copies' => $this->deleteInBatches(fn () => OneTimeCode::query()
                ->whereNull('consumed_at')
                ->where(fn ($query) => $query->whereInstant('expires_at', '<=', now())->orWhereColumn('attempts', '>=', 'max_attempts'))
                ->where(fn ($query) => $query->whereNotNull('secret_encrypted')->orWhereNotNull('lookup'))
                ->limit(self::BATCH)->update(['secret_encrypted' => null, 'lookup' => null])),
            'api_tokens' => $this->deleteInBatches(fn () => User::whereTokenLive(Sanctum::$personalAccessTokenModel::query(), live: false)->limit(self::BATCH)->delete()),
            'sso_bindings' => $this->deleteOrphanBindings(),
            'cache_rows' => $this->deleteExpiredCacheRows(),
            // Abandon first, so the upload of a sync nobody picked up goes in the same run.
            'sync_runs_abandoned' => $this->abandonStaleRuns(),
            'sync_uploads' => $this->deleteOldUploads(),
            // Failed queue jobs are evidence for a few weeks, not records: a fixed window.
            'failed_jobs' => $this->queue->pruneFailed(QueueMaintenance::FAILED_JOB_DAYS),
        ];

        $this->cache->put(self::LAST_RUN_KEY, now()->toIso8601String(), now()->addDays(30));

        if (array_sum($removed) > 0) {
            $this->auditor->record('retention.pruned', context: $removed + ['general_cutoff' => $general->toDateString(), 'short_lived_cutoff' => $short->toDateString()]);
        }

        return $removed;
    }

    public function lastRunAt(): ?Carbon
    {
        $value = $this->cache->get(self::LAST_RUN_KEY);

        return is_string($value) ? Carbon::parse($value) : null;
    }

    /**
     * @param  callable(): int  $deleteBatch  returns the number of rows deleted
     */
    private function deleteInBatches(callable $deleteBatch): int
    {
        $total = 0;

        do {
            $deleted = (int) $deleteBatch();
            $total += $deleted;
        } while ($deleted === self::BATCH);

        return $total;
    }

    /**
     * Clients and staff deleted longer ago than the general period, for
     * good: the company sign-in binding and the API tokens first (no foreign
     * key carries them), then the row at model level, audited as
     * *.force_deleted; the database cascades the rest. Their names drop
     * from older records (calls, audit) that outlived them.
     *
     * @return array{deleted_clients: int, deleted_users: int}
     */
    private function purgeDeletedAccounts(Carbon $general): array
    {
        $removed = [];

        foreach ([PrincipalType::Client, PrincipalType::User] as $type) {
            $count = 0;
            $type->modelClass()::onlyTrashed()->where('deleted_at', '<', $general)->chunkById(200, function ($accounts) use ($type, &$count): void {
                foreach ($accounts as $account) {
                    SsoIdentity::query()->where('principal_type', $type->value)->where('principal_id', $account->getKey())->delete();
                    $account->tokens()->delete();
                    $account->forceDelete();
                    $count++;
                }
            });
            $removed[$type === PrincipalType::Client ? 'deleted_clients' : 'deleted_users'] = $count;
        }

        return $removed;
    }

    /**
     * A sync waiting for a day was never picked up (no worker on its queue,
     * or its job was lost): failed, and its upload deleted.
     */
    private function abandonStaleRuns(): int
    {
        return SyncRun::query()->where('status', SyncRunStatus::Queued)->where('started_at', '<', now()->subDay())->pluck('id')
            ->filter(fn (string $runId): bool => app(QueuedSyncs::class)->abandon($runId, __('no worker picked it up within a day')))
            ->count();
    }

    /**
     * Uploads older than a day that no waiting or running sync names: the
     * worker deletes the file once it has read it, so whatever is left was
     * abandoned (a deleted job, a crash).
     */
    private function deleteOldUploads(): int
    {
        $disk = Storage::disk('local');
        $inUse = SyncRun::query()->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])->pluck('file_name')
            ->filter()->map(fn (string $name): string => self::UPLOAD_DIRECTORY.'/'.$name)->all();
        $cutoff = now()->subDay()->getTimestamp();
        $removed = 0;

        foreach ($disk->files(self::UPLOAD_DIRECTORY) as $file) {
            if ($disk->lastModified($file) < $cutoff && ! in_array($file, $inUse, true)) {
                $disk->delete($file);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Company sign-in bindings of accounts that are gone for good (no row,
     * not even a soft-deleted one).
     */
    private function deleteOrphanBindings(): int
    {
        $removed = 0;

        foreach (PrincipalType::cases() as $type) {
            $table = (new ($type->modelClass()))->getTable();
            $removed += $this->deleteInBatches(fn () => SsoIdentity::query()
                ->where('principal_type', $type->value)
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from($table)->whereColumn($table.'.id', 'sso_identities.principal_id'))
                ->limit(self::BATCH)->delete());
        }

        return $removed;
    }

    /**
     * The database cache store removes an expired row only when that key is
     * read again; the rest would stay for good. Rows of the cache and its
     * lock table past their expiration go here, in every database store.
     */
    private function deleteExpiredCacheRows(): int
    {
        $removed = 0;

        foreach (collect(config('cache.stores'))->where('driver', 'database') as $store) {
            foreach (array_unique(array_filter([$store['table'] ?? 'cache', $store['lock_table'] ?? 'cache_locks'])) as $table) {
                $connection = $store['connection'] ?? null;

                if (! Schema::connection($connection)->hasTable($table)) {
                    continue;
                }

                $removed += $this->deleteInBatches(fn () => DB::connection($connection)->table($table)
                    ->where('expiration', '<=', now()->getTimestamp())->limit(self::BATCH)->delete());
            }
        }

        return $removed;
    }
}
