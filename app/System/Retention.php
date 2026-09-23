<?php

namespace App\System;

use App\Audit\Auditor;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\IdSession;
use App\Models\OneTimeCode;
use App\Models\OutboundMessage;
use App\Models\SyncRun;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes for good what the retention settings no longer allow us to keep:
 * business records after the general period (default 5 years), throwaway
 * artefacts such as expired one-time codes and uploaded directory files
 * after the short-lived period (default 2 years). Runs daily; the status
 * page warns when it has not run for a few days.
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
    ) {}

    public function generalCutoff(): Carbon
    {
        return now()->subYears(max(1, $this->settings->int(SettingKey::RetentionGeneralYears)));
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

        $removed = [
            'id_sessions' => $this->deleteInBatches(fn () => IdSession::query()->withTrashed()->where('started_at', '<', $general)->limit(self::BATCH)->forceDelete()),
            'calls' => $this->deleteInBatches(fn () => Call::query()->withTrashed()->where('arrived_at', '<', $general)->limit(self::BATCH)->forceDelete()),
            'outbound_messages' => $this->deleteInBatches(fn () => OutboundMessage::query()->where('created_at', '<', $general)->limit(self::BATCH)->delete()),
            'sync_runs' => $this->deleteInBatches(fn () => SyncRun::query()->withTrashed()->where('started_at', '<', $general)->limit(self::BATCH)->forceDelete()),
            'audit_log' => $this->deleteInBatches(fn () => AuditLog::query()->where('created_at', '<', $general)->limit(self::BATCH)->delete()),
            'one_time_codes' => $this->deleteInBatches(fn () => OneTimeCode::query()->where('expires_at', '<', $short)->limit(self::BATCH)->delete()),
            'sync_uploads' => $this->deleteOldUploads($short),
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

    private function deleteOldUploads(Carbon $cutoff): int
    {
        $disk = Storage::disk('local');
        $removed = 0;

        foreach ($disk->files(self::UPLOAD_DIRECTORY) as $file) {
            if ($disk->lastModified($file) < $cutoff->getTimestamp()) {
                $disk->delete($file);
                $removed++;
            }
        }

        return $removed;
    }
}
