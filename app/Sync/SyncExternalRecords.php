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
use App\System\Installation;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Full synchronisation: upsert every row of the source, provision or link
 * accounts, then mark everything not seen in this run as missing and close
 * the linked accounts once the configured number of missed runs is reached.
 *
 * Safety nets before anything is closed: an empty source is refused, and
 * so is a source that shrank below the configured share of the previous
 * successful run (a truncated export). The share is measured on distinct
 * classified identifiers: a repeated external id counts once, the first
 * occurrence wins and the repeats are reported as skipped rows. A dry run
 * does all the work inside a transaction that is rolled back, and keeps
 * only the report.
 *
 * A run takes minutes on a large directory, so it refuses to start under a
 * PHP time limit below MIN_TIME_LIMIT_SECONDS, and an abort guard turns a
 * run that PHP cut short anyway (time limit, memory, crash) into a failed
 * run with the sync lock released, instead of a run stuck "running" that
 * blocks every later sync for an hour.
 */
class SyncExternalRecords
{
    public const SKIPPED_SAMPLE_SIZE = 50;

    public const CHANGE_SAMPLE_SIZE = 20;

    /** Below this PHP time limit (seconds) a run is refused; 0 means unlimited. */
    public const MIN_TIME_LIMIT_SECONDS = 120;

    public const RECOMMENDED_TIME_LIMIT_SECONDS = 300;

    private static ?Closure $abortGuard = null;

    private static bool $shutdownRegistered = false;

    public function __construct(
        private readonly RecordClassifier $classifier,
        private readonly AccountProvisioner $provisioner,
        private readonly Settings $settings,
        private readonly Auditor $auditor,
        private readonly PhoneNormalizer $phones,
        private readonly RunChangeRecorder $changes,
    ) {}

    public function run(SourceReader $reader, ?User $triggeredBy = null, bool $dryRun = false, ?SyncRun $run = null): SyncRun
    {
        if (($limit = self::insufficientTimeLimit()) !== null) {
            throw new RuntimeException(__('PHP max_execution_time is :limit s; EMD sync needs at least :min s (:recommended recommended) for both PHP-FPM and the CLI.', ['limit' => $limit, 'min' => self::MIN_TIME_LIMIT_SECONDS, 'recommended' => self::RECOMMENDED_TIME_LIMIT_SECONDS]));
        }

        // Two overlapping runs would mark each other's records as missing.
        $lock = Cache::lock(Installation::lockName('hdid:sync'), 3600);

        if (! $lock->get()) {
            throw new RuntimeException(__('Another EMD sync is already running; try again when it has finished.'));
        }

        try {
            $run = $this->startRun($reader, $triggeredBy, $dryRun, $run);
            self::armAbortGuard($run, $lock, $this->auditor);

            return $this->execute($reader, $triggeredBy, $dryRun, $run);
        } finally {
            self::$abortGuard = null;
            $lock->release();
        }
    }

    /**
     * The current PHP time limit when it is too short for a run; null when
     * it is unlimited (0, the CLI default) or long enough.
     */
    public static function insufficientTimeLimit(): ?int
    {
        $limit = (int) ini_get('max_execution_time');

        return $limit > 0 && $limit < self::MIN_TIME_LIMIT_SECONDS ? $limit : null;
    }

    /**
     * What PHP runs at shutdown while a run is in progress: a run that was
     * cut short (time limit, memory, crash) never reaches its finally
     * blocks, so this records the failure and frees the lock. A completed
     * run disarms it. Registered once per process; the worker reuses it.
     */
    public static function armAbortGuard(SyncRun $run, Lock $lock, Auditor $auditor): void
    {
        $baseTransactionLevel = DB::transactionLevel();

        self::$abortGuard = function () use ($run, $lock, $auditor, $baseTransactionLevel): void {
            try {
                // A dry run's open transaction would swallow the status change.
                while (DB::transactionLevel() > $baseTransactionLevel) {
                    DB::rollBack();
                }

                $fresh = $run->fresh();
                if ($fresh !== null && $fresh->status === SyncRunStatus::Running) {
                    $error = __('The run was cut short by PHP (time limit, memory or crash): the rows after the last written record were not processed and no account was closed as missing. Check max_execution_time (at least :min s, :recommended recommended) and the worker log.', ['min' => self::MIN_TIME_LIMIT_SECONDS, 'recommended' => self::RECOMMENDED_TIME_LIMIT_SECONDS]);
                    $fresh->forceFill(['status' => SyncRunStatus::Failed, 'finished_at' => now(), 'error' => $error])->save();
                    $auditor->record('sync.failed', $fresh, ['error' => $error, 'dry_run' => $fresh->dry_run, 'aborted' => true]);
                }
            } catch (Throwable) {
                // Shutdown: nothing left to report to.
            }

            try {
                $lock->release();
            } catch (Throwable) {
            }
        };

        if (! self::$shutdownRegistered) {
            register_shutdown_function(static fn () => self::$abortGuard?->__invoke());
            self::$shutdownRegistered = true;
        }
    }

    /**
     * Runs the armed abort guard as PHP would at shutdown (tests).
     */
    public static function simulateAbort(): void
    {
        self::$abortGuard?->__invoke();
    }

    private function startRun(SourceReader $reader, ?User $triggeredBy, bool $dryRun, ?SyncRun $run): SyncRun
    {
        $run ??= new SyncRun;
        $run->fill([
            'source' => $reader->source(),
            'status' => SyncRunStatus::Running,
            'file_name' => SourceLabel::of($reader->label()),
            'started_at' => now(),
            'triggered_by_user_id' => $triggeredBy?->id,
            'dry_run' => $dryRun,
        ])->save();

        return $run;
    }

    private function execute(SourceReader $reader, ?User $triggeredBy, bool $dryRun, SyncRun $run): SyncRun
    {
        $stats = ['read' => 0, 'skipped' => 0, 'created' => 0, 'updated' => 0, 'missing' => 0, 'closed' => 0, 'provisioned' => 0];
        $stats['current'] = ['users' => User::query()->open()->count(), 'clients' => Client::query()->open()->count()];
        $stats['incoming'] = ['users' => 0, 'clients' => 0, 'unclassified' => 0, 'invalid_row' => 0, 'invalid_email' => 0, 'duplicate' => 0, 'duplicate_email' => 0];
        $stats['domains'] = $this->classifier->domainLists();
        $stats['preview'] = [];
        $stats['invalid_phones'] = 0;
        $stats['phone_warnings'] = [];
        $stats['truncated'] = 0;
        $stats['truncated_samples'] = [];
        $stats['invalid_email_kept'] = 0;
        $stats['invalid_email_kept_samples'] = [];
        $stats['email_conflicts'] = 0;
        $stats['email_conflict_samples'] = [];
        $stats['holder_data_removed'] = 0;
        $stats['holder_data_removed_samples'] = [];
        $stats['reissued'] = 0;
        $stats['reissued_samples'] = [];
        $stats['demo_conflicts'] = 0;
        $stats['kind_pending'] = 0;
        $stats['kind_changed'] = 0;
        $stats['changes'] = 0;
        $skipped = [];
        $samples = ['created' => [], 'closed' => [], 'kind_pending' => [], 'kind_changed' => []];
        $kindThreshold = max(1, $this->settings->int(SettingKey::SyncMissedRunsBeforeClose));
        /** @var array<int, true> $seen external identifiers already imported in this run */
        $seen = [];
        /** @var array<string, true> $seenEmails e-mail addresses already imported in this run */
        $seenEmails = [];

        // The per-record diffs go to sync_run_changes, not the audit log.
        $this->changes->begin($run);

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
                        $this->keepRecordWithUnusableAddress($item, $run, $stats, $seen);

                        continue;
                    }

                    $kind = $this->classifier->classify($item);

                    if ($kind === null) {
                        $this->noteSkipped($stats, $skipped, SkippedRow::fromDto($this->classifier->skipReason($item), $item));

                        continue;
                    }

                    // A repeated identifier is one person, not one more usable row:
                    // counting the repeats would let a broken export that echoes a
                    // single record pass the shrink guard. The first occurrence wins
                    // and the repeats are reported, never written or previewed.
                    if (isset($seen[$item->externalId])) {
                        $this->noteSkipped($stats, $skipped, SkippedRow::fromDto(SkippedRow::REASON_DUPLICATE, $item));

                        continue;
                    }
                    $seen[$item->externalId] = true;

                    // The e-mail is the account's key: two rows with one address
                    // would bind the same client to each of them in turn, run after
                    // run. The first row wins here too; the repeat is reported.
                    // The key is the provisioner's exact comparison: an address the
                    // database collation merely equates with another (accents, width)
                    // passes on and is refused there as a conflict, so a look-alike row
                    // placed first can neither take the account nor push the owner's
                    // row out of the run (its record would go missing and close).
                    $email = AccountProvisioner::emailKey($item->email);
                    if (isset($seenEmails[$email])) {
                        $this->noteSkipped($stats, $skipped, SkippedRow::fromDto(SkippedRow::REASON_DUPLICATE_EMAIL, $item));

                        continue;
                    }
                    $seenEmails[$email] = true;

                    $record = ExternalRecord::query()->withTrashed()->firstOrNew(['external_id' => $item->externalId]);
                    $isNew = ! $record->exists;

                    // In production a record the demo data created (published
                    // password, PIN and answers) is never handed to the real
                    // person who has its id: the row is reported as an e-mail
                    // conflict and not written, so the demo record goes missing
                    // and its account closes like any absent record's.
                    if (! $isNew && $this->provisioner->refusesDemoTakeover($record, $item->email)) {
                        $stats['incoming'][$kind === PrincipalType::User ? 'users' : 'clients']++;
                        $stats['demo_conflicts']++;
                        $this->collectEmailConflicts($stats);

                        continue;
                    }

                    $this->changes->focus($record);
                    [$kind, $oldKind] = $this->settleKind($record, $kind, $kindThreshold, $stats, $samples, $item);

                    $stats['incoming'][$kind === PrincipalType::User ? 'users' : 'clients']++;
                    if ($item->truncated !== []) {
                        $stats['truncated']++;
                        if (count($stats['truncated_samples']) < self::CHANGE_SAMPLE_SIZE) {
                            $stats['truncated_samples'][] = ['external_id' => $item->externalId, 'fields' => implode(', ', $item->truncated)];
                        }
                    }
                    if (count($stats['preview']) < 10) {
                        $stats['preview'][] = [
                            'external_id' => $item->externalId, 'name' => $item->name,
                            'email' => $item->email, 'kind' => $kind->value,
                        ];
                    }

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

                    if ($oldKind !== null) {
                        $this->provisioner->closeAccountOfKind($record, $oldKind);
                    }

                    $principal = $this->provisioner->syncAccount($record);
                    if ($principal instanceof Client && $principal->wasRecentlyCreated) {
                        $stats['provisioned']++;
                    }

                    $this->collectEmailConflicts($stats);
                }

                // A client moved between two records: hand-over or reissue, now that
                // it is known whether the old record is still in the source.
                $this->provisioner->settleRebounds($run);
                $this->collectEmailConflicts($stats);

                if ($stats['read'] === 0) {
                    throw new EmptySourceException('The source returned no rows; refusing to close every account.');
                }

                if ($stats['incoming']['users'] + $stats['incoming']['clients'] === 0 && ExternalRecord::query()->exists()) {
                    throw new EmptySourceException(__('No importable records remain after validation and domain filtering; accounts were not closed.'));
                }

                $this->assertNotShrunk($run, $stats);

                [$stats['missing'], $stats['closed'], $samples['closed']] = $this->handleMissing($run);
                [$stats['closing_next'], $samples['closing_next']] = $this->closingAtNextRun();
            } finally {
                $this->provisioner->discardPendingRebounds();
                $stats['changes'] = $this->changes->end();
                if ($transactionStarted) {
                    DB::rollBack();
                }
            }

            $stats['samples'] = $samples;
            $stats = $this->withRequestDetails($stats, $reader);
            $run->forceFill(['status' => SyncRunStatus::Completed, 'finished_at' => now(), 'stats' => $stats, 'skipped_rows' => $skipped])->save();
            $this->auditor->record($dryRun ? 'sync.dry_run' : 'sync.completed', $run, $stats, $triggeredBy);
        } catch (Throwable $e) {
            $stats = $this->withRequestDetails($stats, $reader);
            $run->forceFill(['status' => SyncRunStatus::Failed, 'finished_at' => now(), 'stats' => $stats, 'skipped_rows' => $skipped, 'error' => $e->getMessage()])->save();
            $this->auditor->record('sync.failed', $run, ['error' => $e->getMessage(), 'dry_run' => $dryRun] + $stats, $triggeredBy);

            throw $e;
        }

        return $run;
    }

    /**
     * The e-mail conflicts, the hand-overs and the reissues the provisioner
     * noted so far, into the run's counts and samples.
     *
     * @param  array<string, mixed>  $stats
     */
    private function collectEmailConflicts(array &$stats): void
    {
        foreach ($this->provisioner->takeEmailConflicts() as $conflict) {
            $stats['email_conflicts']++;
            if (count($stats['email_conflict_samples']) < self::SKIPPED_SAMPLE_SIZE) {
                $stats['email_conflict_samples'][] = $conflict;
            }
        }

        // Accounts handed to another person's record, cleared of the previous holder's data.
        foreach ($this->provisioner->takeHolderDataRemovals() as $removal) {
            $stats['holder_data_removed']++;
            if (count($stats['holder_data_removed_samples']) < self::CHANGE_SAMPLE_SIZE) {
                $stats['holder_data_removed_samples'][] = $removal;
            }
        }

        // Records reissued to the same person under a new external id: the account moved over with its data.
        foreach ($this->provisioner->takeReissues() as $reissue) {
            $stats['reissued']++;
            if (count($stats['reissued_samples']) < self::CHANGE_SAMPLE_SIZE) {
                $stats['reissued_samples'][] = $reissue;
            }
        }
    }

    /** @param array<string, mixed> $stats
     * @return list<string>
     */
    /**
     * The reader's request report (status codes, IDs sent), kept on failed
     * runs too.
     *
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    private function withRequestDetails(array $stats, SourceReader $reader): array
    {
        $details = $reader->details();

        return $details === [] ? $stats : $stats + ['requests' => $details];
    }

    /**
     * A record whose e-mail domain moved it to the other list (staff and
     * client) keeps its old kind for the configured number of consecutive
     * runs, so a passing directory error does not close an account at once;
     * the switch is final once the new kind was seen that many runs in a
     * row (the same threshold as for a missing record).
     *
     * @param  array<string, mixed>  $stats
     * @param  array<string, list<string>>  $samples
     * @return array{0: PrincipalType, 1: ?PrincipalType} [kind to store, the old kind when the switch happens in this run]
     */
    private function settleKind(ExternalRecord $record, PrincipalType $classified, int $threshold, array &$stats, array &$samples, ExternalRecordDto $item): array
    {
        if (! $record->exists || $record->kind === $classified) {
            $record->pending_kind = null;
            $record->pending_kind_runs = 0;

            return [$classified, null];
        }

        $record->pending_kind_runs = $record->pending_kind === $classified ? $record->pending_kind_runs + 1 : 1;
        $record->pending_kind = $classified;

        if ($record->pending_kind_runs < $threshold) {
            $stats['kind_pending']++;
            if (count($samples['kind_pending']) < self::CHANGE_SAMPLE_SIZE) {
                $samples['kind_pending'][] = $item->name.' <'.$item->email.'> ('.$record->pending_kind_runs.'/'.$threshold.')';
            }

            return [$record->kind, null];
        }

        $old = $record->kind;
        $record->pending_kind = null;
        $record->pending_kind_runs = 0;
        $stats['kind_changed']++;
        if (count($samples['kind_changed']) < self::CHANGE_SAMPLE_SIZE) {
            $samples['kind_changed'][] = $item->name.' <'.$item->email.'>';
        }

        return [$classified, $old];
    }

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
     * Records that one more absent run would make missing (closing their
     * accounts), after this run's bookkeeping: the count and a sample of
     * names, so the operator sees the next run's closures coming.
     *
     * @return array{0: int, 1: list<string>}
     */
    private function closingAtNextRun(): array
    {
        $threshold = max(1, $this->settings->int(SettingKey::SyncMissedRunsBeforeClose));
        $query = ExternalRecord::query()->fromDirectory()->closingAtNextRun($threshold);

        return [
            $query->count(),
            $query->clone()->orderBy('name')->limit(self::CHANGE_SAMPLE_SIZE)->get()->map(fn (ExternalRecord $record): string => $record->name.' <'.$record->email.'>')->all(),
        ];
    }

    /**
     * @return array{0: int, 1: int, 2: list<string>} [newly missing, accounts closed, sample of closed names]
     */
    /**
     * A row of a record already known whose address the import cannot use
     * (malformed or too long, EmailAddressRule): earlier imports took such
     * addresses without checking, so a record could have one. The row is not
     * imported, but the record counts as seen, keeping its data, its old
     * address and its account, instead of going missing and closing the
     * account a few runs later. The run reports it separately. A new record
     * with such an address is skipped as before.
     *
     * @param  array<string, mixed>  $stats
     * @param  array<int, true>  $seen
     */
    private function keepRecordWithUnusableAddress(SkippedRow $row, SyncRun $run, array &$stats, array &$seen): void
    {
        $externalId = (string) ($row->sample['external_id'] ?? '');

        if ($row->reason !== SkippedRow::REASON_INVALID_EMAIL || preg_match(ExternalRecordDto::EXTERNAL_ID_PATTERN, $externalId) !== 1 || isset($seen[(int) $externalId])) {
            return;
        }

        $record = ExternalRecord::query()->fromDirectory()->where('external_id', (int) $externalId)->whereNull('missing_since')->first();

        if ($record === null) {
            return;
        }

        $seen[(int) $externalId] = true;
        $record->forceFill(['last_seen_run_id' => $run->id, 'last_seen_at' => now(), 'missed_runs' => 0])->save();
        $stats['invalid_email_kept']++;

        if (count($stats['invalid_email_kept_samples']) < self::CHANGE_SAMPLE_SIZE) {
            $stats['invalid_email_kept_samples'][] = ['external_id' => (int) $externalId, 'email' => (string) ($row->sample['email'] ?? ''), 'kept' => $record->email];
        }
    }

    private function handleMissing(SyncRun $run): array
    {
        $threshold = max(1, $this->settings->int(SettingKey::SyncMissedRunsBeforeClose));
        $missing = 0;
        $closed = 0;
        $sample = [];

        // Local bootstrap rows (hdid:make-admin) are not the directory's: a
        // run never sees them, and must not close the first administrator.
        ExternalRecord::query()
            ->fromDirectory()
            ->where(fn ($q) => $q->where('last_seen_run_id', '!=', $run->id)->orWhereNull('last_seen_run_id'))
            ->with(['user', 'client'])
            ->chunkById(200, function ($records) use ($threshold, &$missing, &$closed, &$sample): void {
                foreach ($records as $record) {
                    $this->changes->focus($record);
                    $record->missed_runs++;

                    if ($record->missing_since === null && $record->missed_runs >= $threshold) {
                        $record->missing_since = now();
                        $missing++;
                        // The accounts as stored now, not as the chunk loaded them: an
                        // earlier record of this chunk may have closed one meanwhile (a
                        // sponsor's closure closes its linked clients), and that reason stays.
                        $record->load(['user', 'client']);
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
