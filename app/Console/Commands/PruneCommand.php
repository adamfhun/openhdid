<?php

namespace App\Console\Commands;

use App\System\Retention;
use Illuminate\Console\Command;

/**
 * Applies the retention settings: deletes records older than the general
 * period and throwaway artefacts older than the short-lived period.
 */
class PruneCommand extends Command
{
    protected $signature = 'hdid:prune';

    protected $description = 'Delete records that are past their retention period (settings: retention.*)';

    public function handle(Retention $retention): int
    {
        $this->line('General cut-off: '.$retention->generalCutoff()->toDateString().' · short-lived cut-off: '.$retention->shortLivedCutoff()->toDateString());

        $removed = $retention->prune();

        $this->table(['table', 'removed'], collect($removed)->map(fn (int $n, string $table) => [$table, $n])->values()->all());

        return self::SUCCESS;
    }
}
