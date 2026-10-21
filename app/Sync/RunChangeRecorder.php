<?php

namespace App\Sync;

use App\Audit\Auditor;
use App\Enums\SyncChangeKind;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\SyncRun;
use App\Models\SyncRunChange;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Collects what a sync run changes, one row per directory record, in the
 * sync_run_changes table instead of the audit log. While a run is recording,
 * the model diffs of the directory record, the account and its phone numbers
 * are diverted here (Auditor::divert); the account lifecycle events
 * (account.closed, account.reopened, account.email_changed, sync.*) are not
 * on the list and stay in the audit log, where a security review looks for
 * them. The run's own summary row (sync.completed) carries the count.
 *
 * @phpstan-import-type ChangeEntry from SyncRunChange
 */
class RunChangeRecorder
{
    /** @var list<string> event prefixes kept on the run instead of the audit log */
    public const DIVERTED_PREFIXES = ['external_record.', 'client.', 'client_phone_number.', 'user.'];

    /**
     * Only the automatic model diffs (Auditable) are diverted; an explicit
     * business event under the same prefix (client.unlinked, client.linked)
     * stays in the audit log.
     *
     * @var list<string>
     */
    public const DIVERTED_ACTIONS = ['created', 'updated', 'deleted', 'force_deleted', 'restored'];

    private ?SyncRun $run = null;

    private ?ExternalRecord $record = null;

    /** @var list<ChangeEntry> */
    private array $pending = [];

    private int $written = 0;

    /** @var (Closure(): void)|null */
    private ?Closure $stop = null;

    public function begin(SyncRun $run): void
    {
        $this->run = $run;
        $this->record = null;
        $this->pending = [];
        $this->written = 0;
        $this->stop = Auditor::divert(fn (string $event, ?Model $subject, array $context): bool => $this->take($event, $subject, $context));
    }

    /**
     * The record whose changes come next; the previous record's row is written.
     */
    public function focus(ExternalRecord $record): void
    {
        $this->flush();
        $this->record = $record;
    }

    /**
     * Writes the last pending row and stops diverting. Returns the number of
     * rows written in this run.
     */
    public function end(): int
    {
        $this->flush();

        if ($this->stop !== null) {
            ($this->stop)();
            $this->stop = null;
        }

        $this->run = null;
        $this->record = null;

        return $this->written;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function take(string $event, ?Model $subject, array $context): bool
    {
        if ($this->record === null || ! Str::startsWith($event, self::DIVERTED_PREFIXES) || ! in_array(Str::afterLast($event, '.'), self::DIVERTED_ACTIONS, true)) {
            return false;
        }

        $this->pending[] = [
            'event' => $event,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey() === null ? null : (string) $subject->getKey(),
            'context' => $context,
        ];

        return true;
    }

    private function flush(): void
    {
        if ($this->run === null || $this->record === null || $this->pending === []) {
            $this->pending = [];

            return;
        }

        $account = collect($this->pending)->last(fn (array $entry): bool => in_array($entry['subject_type'], [(new Client)->getMorphClass(), (new User)->getMorphClass()], true));

        SyncRunChange::query()->create([
            'sync_run_id' => $this->run->id,
            'external_id' => $this->record->external_id,
            'kind' => $this->kindOf($this->pending),
            'name' => $this->record->name,
            'email' => $this->record->email,
            'subject_type' => $account['subject_type'] ?? null,
            'subject_id' => $account['subject_id'] ?? null,
            'changes' => $this->pending,
        ]);

        $this->written++;
        $this->pending = [];
    }

    /**
     * @param  list<ChangeEntry>  $entries
     */
    private function kindOf(array $entries): SyncChangeKind
    {
        $kind = SyncChangeKind::Updated;

        foreach ($entries as $entry) {
            if ($entry['event'] === 'external_record.created') {
                return SyncChangeKind::Created;
            }

            $to = $entry['context']['to'] ?? [];
            $from = $entry['context']['from'] ?? [];
            $accountEvent = Str::startsWith($entry['event'], ['client.', 'user.']);

            if ($accountEvent && (Str::endsWith($entry['event'], '.restored') || (array_key_exists('closed_at', $to) && $to['closed_at'] === null && ($from['closed_at'] ?? null) !== null))) {
                $kind = SyncChangeKind::Reopened;
            } elseif ($accountEvent && (Str::endsWith($entry['event'], '.deleted') || (($to['closed_at'] ?? null) !== null))) {
                $kind = SyncChangeKind::Closed;
            }
        }

        return $kind;
    }
}
