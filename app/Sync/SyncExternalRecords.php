<?php

namespace App\Sync;

use App\Audit\Auditor;
use App\Enums\PrincipalType;
use App\Enums\SyncRunStatus;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Support\PhoneNormalizer;
use App\Sync\Contracts\SourceReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Full synchronisation: upsert every row of the source, provision or link
 * accounts, then mark everything not seen in this run as missing and close
 * the linked accounts once the configured number of missed runs is reached.
 *
 * Safety nets before anything is closed: an empty source is refused, and
 * so is a source that shrank below the configured share of the previous
 * successful run (a truncated export). A dry run does all the work inside
 * a transaction that is rolled back, and keeps only the report.
 */
class SyncExternalRecords
{
    public const SKIPPED_SAMPLE_SIZE = 50;

    public const CHANGE_SAMPLE_SIZE = 20;

    public function __construct(
        private readonly RecordClassifier $classifier,
        private readonly AccountProvisioner $provisioner,
        private readonly Settings $settings,
        private readonly Auditor $auditor,
        private readonly PhoneNormalizer $phones,
    ) {}

    public function run(SourceReader $reader, ?User $triggeredBy = null, bool $dryRun = false, ?SyncRun $run = null): SyncRun
    {
        // Two overlapping runs would mark each other's records as missing.
        $lock = Cache::lock('hdid:sync', 3600);

        if (! $lock->get()) {
            throw new \RuntimeException(__('Another EMD sync is already running; try again when it has finished.'));
        }

        try {
            return $this->execute($reader, $triggeredBy, $dryRun, $run);
        } finally {
            $lock->release();
        }
    }

    private function execute(SourceReader $reader, ?User $triggeredBy, bool $dryRun, ?SyncRun $run): SyncRun
    {
        $run ??= new SyncRun;
        $run->fill([
            'source' => $reader->source(),
            'status' => SyncRunStatus::Running,
            'file_name' => $reader->label(),
            'started_at' => now(),
            'triggered_by_user_id' => $triggeredBy?->id,
            'dry_run' => $dryRun,
        ])->save();

        $stats = ['read' => 0, 'skipped' => 0, 'created' => 0, 'updated' => 0, 'missing' => 0, 'closed' => 0, 'provisioned' => 0];
        $stats['current'] = ['users' => User::query()->open()->count(), 'clients' => Client::query()->open()->count()];
        $stats['incoming'] = ['users' => 0, 'clients' => 0, 'unclassified' => 0, 'invalid_row' => 0];
        $stats['preview'] = [];
        $stats['invalid_phones'] = 0;
        $stats['phone_warnings'] = [];
        $skipped = [];
        $samples = ['created' => [], 'closed' => []];

        try {
            $transactionStarted = false;
            try {
                foreach ($reader->read() as $item) {
                    // Download and token rotation happen before the first yielded row.
                    // Their persisted state must survive a trial run's rollback.
                    if ($dryRun && ! $transactionStarted) {
                        DB::beginTransaction();
                        $transactionStarted = true;
                    }
                    $stats['read']++;

                    if ($item instanceof SkippedRow) {
                        $this->noteSkipped($stats, $skipped, $item);

                        continue;
                    }

                    $kind = $this->classifier->classify($item);

                    if ($kind === null) {
                        $this->noteSkipped($stats, $skipped, SkippedRow::fromDto($this->classifier->skipReason($item), $item));

                        continue;
                    }

                    $stats['incoming'][$kind === PrincipalType::User ? 'users' : 'clients']++;
                    if (count($stats['preview']) < 10) {
                        $stats['preview'][] = [
                            'external_id' => $item->externalId, 'name' => $item->name,
                            'email' => $item->email, 'kind' => $kind->value,
                        ];
                    }

                    $record = ExternalRecord::query()->withTrashed()->firstOrNew(['external_id' => $item->externalId]);
                    $isNew = ! $record->exists;

                    $record->fill([
                        'kind' => $kind,
                        'company' => $item->company,
                        'title' => $item->title,
                        'department' => $item->department,
                        'login_name' => $item->loginName,
                        'room' => $item->room,
                        'employment_status' => $item->employmentStatus,
                        'name' => $item->name,
                        'email' => $item->email,
                        'phones' => $this->normalizePhones($item, $stats),
                        'attributes' => $item->attributes,
                        'implicit_package' => $item->implicitPackage,
                        'explicit_package' => $item->explicitPackage,
                        'last_seen_run_id' => $run->id,
                        'last_seen_at' => now(),
                        'missed_runs' => 0,
                        'missing_since' => null,
                    ]);

                    if ($isNew) {
                        $record->first_seen_run_id = $run->id;
                    }
                    if ($record->trashed()) {
                        $record->restore();
                    }

                    $record->save();
                    $stats[$isNew ? 'created' : 'updated']++;
                    if ($isNew && count($samples['created']) < self::CHANGE_SAMPLE_SIZE) {
                        $samples['created'][] = $item->name.' <'.$item->email.'>';
                    }

                    $principal = $this->provisioner->syncAccount($record);
                    if ($principal instanceof Client && $principal->wasRecentlyCreated) {
                        $stats['provisioned']++;
                    }
                }

                if ($stats['read'] === 0) {
                    throw new EmptySourceException('The source returned no rows; refusing to close every account.');
                }

                if ($stats['incoming']['users'] + $stats['incoming']['clients'] === 0 && ExternalRecord::query()->exists()) {
                    throw new EmptySourceException(__('No importable records remain after validation and domain filtering; accounts were not closed.'));
                }

                $this->assertNotShrunk($run, $stats);

                [$stats['missing'], $stats['closed'], $samples['closed']] = $this->handleMissing($run);
            } finally {
                if ($transactionStarted) {
                    DB::rollBack();
                }
            }

            $stats['samples'] = $samples;
            $run->forceFill(['status' => SyncRunStatus::Completed, 'finished_at' => now(), 'stats' => $stats, 'skipped_rows' => $skipped])->save();
            $this->auditor->record($dryRun ? 'sync.dry_run' : 'sync.completed', $run, $stats, $triggeredBy);
        } catch (Throwable $e) {
            $run->forceFill(['status' => SyncRunStatus::Failed, 'finished_at' => now(), 'stats' => $stats, 'skipped_rows' => $skipped, 'error' => $e->getMessage()])->save();
            $this->auditor->record('sync.failed', $run, ['error' => $e->getMessage(), 'dry_run' => $dryRun] + $stats, $triggeredBy);

            throw $e;
        }

        return $run;
    }

    /** @param array<string, mixed> $stats
     * @return list<string>
     */
    private function normalizePhones(ExternalRecordDto $item, array &$stats): array
    {
        $numbers = [];
        foreach ($item->phones as $raw) {
            $number = $this->phones->normalize($raw);
            if ($number !== null) {
                $numbers[$number] = true;
            } else {
                $stats['invalid_phones']++;
                if (count($stats['phone_warnings']) < self::SKIPPED_SAMPLE_SIZE) {
                    $stats['phone_warnings'][] = ['external_id' => $item->externalId, 'number' => $raw];
                }
            }
        }

        return array_keys($numbers);
    }

    /**
     * @param  array<string, int|array<string, mixed>>  $stats
     * @param  list<array{reason: string, sample: array<string, mixed>}>  $skipped
     */
    private function noteSkipped(array &$stats, array &$skipped, SkippedRow $row): void
    {
        $stats['skipped']++;
        $stats['incoming'][$row->reason]++;
        if (count($skipped) < self::SKIPPED_SAMPLE_SIZE) {
            $skipped[] = ['reason' => $row->reason, 'sample' => $row->sample];
        }
    }

    /**
     * Refuse a run that read far fewer rows than the last successful one.
     */
    /**
     * @param  array<string, mixed>  $stats
     */
    private function assertNotShrunk(SyncRun $run, array $stats): void
    {
        $ratio = max(0, min(100, $this->settings->int(SettingKey::SyncMinRowsRatioPercent)));
        if ($ratio === 0) {
            return;
        }

        $previous = SyncRun::query()
            ->where('status', SyncRunStatus::Completed)
            ->where('dry_run', false)
            ->whereKeyNot($run->id)
            ->latest('started_at')
            ->value('stats');
        $previousUsable = self::usableRows(is_array($previous) ? $previous : null);
        $usable = self::usableRows($stats);

        if ($previousUsable > 0 && $usable * 100 < $previousUsable * $ratio) {
            throw new SuspiciousSourceException(sprintf(
                'The source yielded %d usable rows, below %d%% of the previous successful run (%d rows); refusing to close accounts. Raise "min rows ratio" in the settings if this is expected.',
                $usable, $ratio, $previousUsable,
            ));
        }
    }

    /**
     * Rows that actually became a user or a client. Skipped and unclassified
     * rows must not count: a directory-wide e-mail domain change leaves the
     * raw row count untouched while every row falls out of the import, and
     * the accounts behind them would then be closed as missing.
     *
     * @param  array<string, mixed>|null  $stats
     */
    private static function usableRows(?array $stats): int
    {
        if ($stats === null) {
            return 0;
        }

        if (is_array($stats['incoming'] ?? null)) {
            return (int) ($stats['incoming']['users'] ?? 0) + (int) ($stats['incoming']['clients'] ?? 0);
        }

        // Runs recorded before the guard counted classified rows.
        return (int) ($stats['read'] ?? 0);
    }

    /**
     * @return array{0: int, 1: int, 2: list<string>} [newly missing, accounts closed, sample of closed names]
     */
    private function handleMissing(SyncRun $run): array
    {
        $threshold = max(1, $this->settings->int(SettingKey::SyncMissedRunsBeforeClose));
        $missing = 0;
        $closed = 0;
        $sample = [];

        ExternalRecord::query()
            ->where(fn ($q) => $q->where('last_seen_run_id', '!=', $run->id)->orWhereNull('last_seen_run_id'))
            ->with(['user', 'client'])
            ->chunkById(200, function ($records) use ($threshold, &$missing, &$closed, &$sample): void {
                foreach ($records as $record) {
                    $record->missed_runs++;

                    if ($record->missing_since === null && $record->missed_runs >= $threshold) {
                        $record->missing_since = now();
                        $missing++;
                        $closedNow = $this->provisioner->closeAccounts($record);
                        $closed += $closedNow;
                        if ($closedNow > 0 && count($sample) < self::CHANGE_SAMPLE_SIZE) {
                            $sample[] = $record->name.' <'.$record->email.'>';
                        }
                    }

                    $record->save();
                }
            });

        return [$missing, $closed, $sample];
    }
}
