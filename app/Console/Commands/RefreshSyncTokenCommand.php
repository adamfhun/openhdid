<?php

namespace App\Console\Commands;

use App\Sync\ApiTokens;
use Illuminate\Console\Command;
use Throwable;

class RefreshSyncTokenCommand extends Command
{
    protected $signature = 'hdid:emd-sync-token';

    /**
     * @var list<string>
     */
    protected $aliases = ['hdid:sync-token'];

    protected $description = 'Refresh the Enterprise Master Data (EMD) API tokens and record authentication availability';

    public function handle(ApiTokens $tokens): int
    {
        try {
            $tokens->refresh();
            $this->components->info(__('EMD API token refreshed.'));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
