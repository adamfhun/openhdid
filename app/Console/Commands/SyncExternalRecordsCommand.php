<?php

namespace App\Console\Commands;

use App\Sync\AccountProvisioner;
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

        foreach (['closing_next' => 'Closes at the next sync', 'kind_pending' => 'Classification changing', 'kind_changed' => 'Classification changed'] as $key => $label) {
            foreach ($run->stats['samples'][$key] ?? [] as $line) {
                $this->line("  {$label}: {$line}");
            }
        }

        foreach ($run->stats['email_conflict_samples'] ?? [] as $conflict) {
            $case = match (true) {
                (bool) ($conflict['demo'] ?? false) => 'a demo record, not handed over in production',
                (bool) ($conflict['look_alike'] ?? false) => 'another account holds this address, possibly written differently',
                default => 'another account holds the new address',
            };
            $this->components->warn('E-mail conflict: external id '.$conflict['external_id'].' wants '.$conflict['email'].', kept '.$conflict['kept'].' ('.$case.')');
        }

        foreach ($run->stats['holder_data_removed_samples'] ?? [] as $removal) {
            $why = $removal['reason'] === AccountProvisioner::REASON_SYNC_REBOUND
                ? 'the address moved over from another record still in the source'
                : 'first link after an e-mail change by hand';
            $this->components->warn('Handed to another person: external id '.$removal['external_id'].' ('.$removal['email'].'); removed: '.(implode(', ', $removal['removed']) ?: 'nothing was set up').' ('.$why.')');
        }

        foreach ($run->stats['reissued_samples'] ?? [] as $reissue) {
            $this->line('  Reissued to the same person: external id '.($reissue['from_external_id'] ?? '?').' -> '.$reissue['external_id'].' ('.$reissue['email'].'); PIN, answers and numbers kept, sign-ins ended');
        }

        foreach (array_slice($run->skipped_rows ?? [], 0, 10) as $row) {
            $this->line('  Skipped ('.$row['reason'].'): '.json_encode($row['sample'], JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
