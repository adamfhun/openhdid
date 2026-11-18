<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Hot and cold audit log. The database keeps the entries of the last
 * `retention.audit_hot_days` days, where the panel searches them; every older
 * day is written to one compressed JSON Lines file with a SHA-256 sidecar on
 * the storage disk and deleted from the table, in batches, only after the
 * file is on disk, and only the rows read back from that file (a run that
 * stopped half-way goes on from the finished file, see archiveDay()). The files live for the general retention period and go
 * into the backups with the storage volume. Days are archived oldest first,
 * a bounded number per run, so a first run on an old installation catches up
 * over a few nights without holding the scheduler.
 *
 * @phpstan-type ArchiveFile array{name: string, path: string, day: string, bytes: int, sha256: ?string}
 */
class AuditArchive
{
    public const DIRECTORY = 'audit-archive';

    public const MIN_HOT_DAYS = 30;

    public const MAX_DAYS_PER_RUN = 120;

    public const LAST_RUN_KEY = 'hdid.audit-archive.last_run';

    private const BATCH = 1000;

    private const FILE_PATTERN = '/^audit-(\d{4}-\d{2}-\d{2})\.jsonl\.gz$/';

    public function __construct(
        private readonly Settings $settings,
        private readonly Cache $cache,
        private readonly Auditor $auditor,
    ) {}

    public function hotDays(): int
    {
        return max(self::MIN_HOT_DAYS, $this->settings->int(SettingKey::RetentionAuditHotDays));
    }

    /**
     * Start of the first day that stays in the database.
     */
    public function cutoff(): CarbonImmutable
    {
        return CarbonImmutable::today()->subDays($this->hotDays());
    }

    /**
     * Archives every day before the cutoff, oldest first, at most
     * MAX_DAYS_PER_RUN days in one call.
     *
     * @return array{days: int, rows: int, files: list<string>}
     */
    public function archive(): array
    {
        $cutoff = $this->cutoff();
        $result = ['days' => 0, 'rows' => 0, 'files' => []];
        $oldest = AuditLog::query()->where('created_at', '<', $cutoff)->min('created_at');

        while ($oldest !== null && $result['days'] < self::MAX_DAYS_PER_RUN) {
            $day = Carbon::parse($oldest)->toImmutable()->startOfDay();
            $written = $this->archiveDay($day);

            // A day whose file was finished earlier and holds nothing left to delete is not a day of this run.
            if ($written['rows'] > 0) {
                $result['days']++;
                $result['rows'] += $written['rows'];
                $result['files'][] = $written['path'];
            }

            $oldest = AuditLog::query()->where('created_at', '>=', $day->addDay())->where('created_at', '<', $cutoff)->min('created_at');
        }

        $this->cache->put(self::LAST_RUN_KEY, now()->toIso8601String(), now()->addDays(30));

        if ($result['days'] > 0) {
            $this->auditor->record('audit.archived', context: [
                'days' => $result['days'],
                'rows' => $result['rows'],
                'first' => basename($result['files'][0]),
                'last' => basename($result['files'][array_key_last($result['files'])]),
                'hot_days' => $this->hotDays(),
            ]);
        }

        return $result;
    }

    /**
     * Deletes archive files whose day is before the cutoff (the general
     * retention). Returns the number of files removed.
     */
    public function pruneFiles(Carbon $cutoff): int
    {
        $removed = 0;

        foreach ($this->files() as $file) {
            if (Carbon::parse($file['day'])->lt($cutoff)) {
                $this->disk()->delete([$file['path'], $file['path'].'.sha256']);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * The archive files, newest first.
     *
     * @return list<ArchiveFile>
     */
    public function files(): array
    {
        $disk = $this->disk();
        $files = [];

        foreach ($disk->allFiles(self::DIRECTORY) as $path) {
            $name = basename($path);
            if (! preg_match(self::FILE_PATTERN, $name, $m)) {
                continue;
            }

            $sidecar = $path.'.sha256';
            $sum = $disk->exists($sidecar) ? trim((string) explode(' ', (string) $disk->get($sidecar), 2)[0]) : null;

            $files[] = ['name' => $name, 'path' => $path, 'day' => $m[1], 'bytes' => $disk->size($path), 'sha256' => $sum ?: null];
        }

        usort($files, fn (array $a, array $b): int => strcmp($b['day'], $a['day']));

        return $files;
    }

    /**
     * The first day the audit log table still holds in full: the day after
     * the newest archived day, or null while nothing is archived. Read from
     * the files, so it is right even when the nightly run is late or the hot
     * window changed meanwhile.
     */
    public function heldSince(): ?CarbonImmutable
    {
        $newest = $this->files()[0]['day'] ?? null;

        return $newest === null ? null : CarbonImmutable::parse($newest)->startOfDay()->addDay();
    }

    /**
     * @return ArchiveFile|null
     */
    public function file(string $name): ?array
    {
        foreach ($this->files() as $file) {
            if ($file['name'] === $name) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return array{files: int, bytes: int}
     */
    public function summary(): array
    {
        $files = $this->files();

        return ['files' => count($files), 'bytes' => (int) array_sum(array_column($files, 'bytes'))];
    }

    public function lastRunAt(): ?Carbon
    {
        $value = $this->cache->get(self::LAST_RUN_KEY);

        return is_string($value) ? Carbon::parse($value) : null;
    }

    public function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    /**
     * One day into one file, then the rows that are in the file out of the
     * table. The table never gives up a row on the word of anything but a
     * finished file that matches its checksum:
     *
     * - A file with a matching checksum is a finished archive of a run that
     *   stopped while deleting. It is kept as it is and the deleting goes on
     *   from it; writing it again from what is left in the table would lose
     *   the rows already deleted, under a fresh checksum.
     * - A file without a checksum is from a run that stopped while writing:
     *   the checksum is written before the first row is deleted, so every
     *   row is still in the table and the file is written again.
     * - A file that does not match its checksum stops the run: nothing is
     *   overwritten and nothing is deleted until somebody has looked at it.
     *
     * @return array{rows: int, path: string}
     */
    private function archiveDay(CarbonImmutable $day): array
    {
        $path = self::DIRECTORY.'/'.$day->format('Y').'/audit-'.$day->toDateString().'.jsonl.gz';
        $rows = AuditLog::query()->where('created_at', '>=', $day)->where('created_at', '<', $day->addDay());
        $disk = $this->disk();

        if ($disk->exists($path) && $disk->exists($path.'.sha256')) {
            $this->assertMatchesChecksum($path);
        } else {
            $this->writeDay($path, $rows);
        }

        $deleted = $this->deleteArchivedRows($path, $rows);

        // Dated into the day after its file was finished: it stays in the table,
        // where the panel still shows it, and the file stays as it is.
        if (($left = (clone $rows)->count()) > 0) {
            Log::warning('Audit archive: entries of an archived day are not in its archive file and stay in the audit log table.', ['file' => $path, 'entries' => $left]);
        }

        return ['rows' => $deleted, 'path' => $path];
    }

    /**
     * @param  Builder<AuditLog>  $rows
     */
    private function writeDay(string $path, Builder $rows): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hdid-audit-');
        if ($tmp === false || ($gz = gzopen($tmp, 'wb9')) === false) {
            throw new RuntimeException('Cannot open a temporary file for the audit archive.');
        }

        $disk = $this->disk();

        // The temporary file holds audit entries: removed on every path, a failed write too.
        try {
            try {
                foreach ((clone $rows)->orderBy('id')->lazyById(self::BATCH) as $entry) {
                    gzwrite($gz, json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
                }
            } finally {
                gzclose($gz);
            }

            // A checksum left from an earlier state must not vouch for the new file.
            $disk->delete($path.'.sha256');

            $stream = fopen($tmp, 'rb');
            try {
                if ($stream === false || ! $disk->put($path, $stream)) {
                    throw new RuntimeException('Cannot write the audit archive file '.$path.'.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $sum = hash_file('sha256', $tmp);
        } finally {
            @unlink($tmp);
        }

        // Written last, and only then may a row go: a file without it is unfinished.
        $disk->put($path.'.sha256', $sum.'  '.basename($path)."\n");
    }

    /**
     * @throws RuntimeException when the stored file is not the one its checksum was made of
     */
    private function assertMatchesChecksum(string $path): void
    {
        $disk = $this->disk();
        $expected = trim((string) explode(' ', (string) $disk->get($path.'.sha256'), 2)[0]);
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException('Cannot read the audit archive file '.$path.'.');
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $actual = hash_final($hash);
        } finally {
            fclose($stream);
        }

        if ($expected === '' || ! hash_equals($expected, $actual)) {
            throw new RuntimeException('The audit archive file '.$path.' does not match its checksum: it is left untouched and no entry of its day is deleted. Compare it with a backup, then restore the file or remove both the file and its .sha256 to archive the day again from the table.');
        }
    }

    /**
     * Deletes the rows of the day that the finished file holds, by the ids
     * read back from the file itself, in batches that keep the lock short.
     * Returns the number of rows deleted.
     *
     * @param  Builder<AuditLog>  $rows
     */
    private function deleteArchivedRows(string $path, Builder $rows): int
    {
        $stream = $this->disk()->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException('Cannot read the audit archive file '.$path.'.');
        }

        // Reads the gzip format as a stream: the day never has to fit in memory.
        stream_filter_append($stream, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 15 + 16]);

        $deleted = 0;
        $ids = [];
        $flush = function () use (&$ids, &$deleted, $rows): void {
            if ($ids !== []) {
                $deleted += (clone $rows)->whereIn('id', $ids)->delete();
                $ids = [];
            }
        };

        try {
            while (($line = fgets($stream)) !== false) {
                $id = json_decode($line, true)['id'] ?? null;

                if (! is_string($id)) {
                    throw new RuntimeException('The audit archive file '.$path.' holds a line without an entry id; no further entry of its day is deleted.');
                }

                $ids[] = $id;

                if (count($ids) === self::BATCH) {
                    $flush();
                }
            }

            $flush();
        } finally {
            fclose($stream);
        }

        return $deleted;
    }
}
