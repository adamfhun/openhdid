<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Hot and cold audit log. The database keeps the entries of the last
 * `retention.audit_hot_days` days, where the panel searches them; every older
 * day is written to one compressed JSON Lines file with a SHA-256 sidecar on
 * the storage disk and deleted from the table, in batches, only after the
 * file is on disk. The files live for the general retention period and go
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

            $result['days']++;
            $result['rows'] += $written['rows'];
            $result['files'][] = $written['path'];

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
     * One day into one file, then the day's rows out of the table. A file
     * left behind by an interrupted run is overwritten: its rows are still in
     * the table, so the new file is complete.
     *
     * @return array{rows: int, path: string}
     */
    private function archiveDay(CarbonImmutable $day): array
    {
        $path = self::DIRECTORY.'/'.$day->format('Y').'/audit-'.$day->toDateString().'.jsonl.gz';
        $rows = AuditLog::query()->where('created_at', '>=', $day)->where('created_at', '<', $day->addDay());

        $tmp = tempnam(sys_get_temp_dir(), 'hdid-audit-');
        if ($tmp === false || ($gz = gzopen($tmp, 'wb9')) === false) {
            throw new RuntimeException('Cannot open a temporary file for the audit archive.');
        }

        $count = 0;
        try {
            foreach ((clone $rows)->orderBy('id')->lazyById(self::BATCH) as $entry) {
                gzwrite($gz, json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
                $count++;
            }
        } finally {
            gzclose($gz);
        }

        $disk = $this->disk();
        $stream = fopen($tmp, 'rb');
        if ($stream === false || ! $disk->put($path, $stream)) {
            throw new RuntimeException('Cannot write the audit archive file '.$path.'.');
        }
        fclose($stream);

        $sum = hash_file('sha256', $tmp);
        @unlink($tmp);
        $disk->put($path.'.sha256', $sum.'  '.basename($path)."\n");

        // The file is on disk: the rows may go. Batches keep the lock short.
        do {
            $deleted = (clone $rows)->limit(self::BATCH)->delete();
        } while ($deleted === self::BATCH);

        return ['rows' => $count, 'path' => $path];
    }
}
