<?php

namespace App\Console\Commands;

use App\System\CheckStatus;
use App\System\HealthChecks;
use Illuminate\Console\Command;

/**
 * The status page for cron and monitoring: prints every check and exits
 * non-zero when any of them fails (2) or, with --strict, warns (1).
 */
class HealthCommand extends Command
{
    protected $signature = 'hdid:health {--strict : Exit non-zero on warnings too} {--json : Machine-readable output}';

    protected $description = 'Run the system health checks; exit code 0 ok, 1 warning (strict), 2 failure';

    public function handle(HealthChecks $checks): int
    {
        $results = $checks->all();

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(fn ($c) => ['key' => $c->key, 'status' => $c->status->value, 'detail' => $c->detail], $results), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['check', 'status', 'detail'], array_map(fn ($c) => [$c->label, $c->status->value, $c->detail], $results));
        }

        $statuses = array_map(fn ($c) => $c->status, $results);

        if (in_array(CheckStatus::Fail, $statuses, true)) {
            return 2;
        }

        return $this->option('strict') && in_array(CheckStatus::Warn, $statuses, true) ? 1 : self::SUCCESS;
    }
}
