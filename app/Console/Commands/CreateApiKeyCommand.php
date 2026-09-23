<?php

namespace App\Console\Commands;

use App\Enums\ApiKeyScope;
use App\Models\ApiKey;
use Illuminate\Console\Command;

/**
 * Issues a machine key from the command line (first deployment, or when
 * nobody can reach the panel yet). The plain key is printed exactly once.
 */
class CreateApiKeyCommand extends Command
{
    protected $signature = 'hdid:api-key:create {name : Label shown in the panel} {scope : callcenter or mobile_backend}';

    protected $description = 'Create a machine API key and print it once';

    public function handle(): int
    {
        $scope = ApiKeyScope::tryFrom((string) $this->argument('scope'));

        if ($scope === null) {
            $this->error('Scope must be one of: '.implode(', ', array_map(fn (ApiKeyScope $s) => $s->value, ApiKeyScope::cases())));

            return self::FAILURE;
        }

        ['key' => $key, 'plain' => $plain] = ApiKey::generate((string) $this->argument('name'), $scope);

        $this->components->info("API key \"{$key->name}\" ({$scope->value}) created. Copy it now; it is not shown again.");
        $this->line($plain);

        return self::SUCCESS;
    }
}
