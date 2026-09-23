<?php

namespace App\Console\Commands;

use App\Sync\ReaderFactory;
use App\Sync\SyncExternalRecords;
use Illuminate\Console\Command;

class SyncExternalRecordsCommand extends Command
{
    protected $signature = 'hdid:emd-sync
        {--file= : Path to a CSV or XLSX file; omit to pull from the configured API}
        {--dry-run : Report what would change without writing anything}';

    /**
     * @var list<string>
     */
    protected $aliases = ['hdid:sync'];

    protected $description = 'Synchronise Enterprise Master Data (EMD) records and open/close local accounts accordingly';

    public function handle(ReaderFactory $readers, SyncExternalRecords $sync): int
    {
        $file = $this->option('file');
        $reader = is_string($file) && $file !== '' ? $readers->forFile($file) : $readers->forApi();

        $dryRun = (bool) $this->option('dry-run');
        $run = $sync->run($reader, dryRun: $dryRun);

        $counts = array_filter($run->stats, 'is_int');
        $this->table(array_keys($counts), [array_values($counts)]);

        if ($dryRun) {
            $this->components->info('Dry run: nothing was written.');
            foreach (['created' => 'Would create', 'closed' => 'Would close'] as $key => $label) {
                foreach ($run->stats['samples'][$key] ?? [] as $line) {
                    $this->line("  {$label}: {$line}");
                }
            }
        }

        foreach (array_slice($run->skipped_rows ?? [], 0, 10) as $row) {
            $this->line('  Skipped ('.$row['reason'].'): '.json_encode($row['sample'], JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
