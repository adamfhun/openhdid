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
    /** As long as the name field of the API keys page allows. */
    private const NAME_MAX_LENGTH = 100;

    protected $signature = 'hdid:api-key:create {name : Label shown in the panel} {scope : callcenter or mobile_backend}';

    protected $description = 'Create a machine API key and print it once';

    public function handle(): int
    {
        $scope = ApiKeyScope::tryFrom((string) $this->argument('scope'));

        if ($scope === null) {
            $this->error('Scope must be one of: '.implode(', ', array_map(fn (ApiKeyScope $s) => $s->value, ApiKeyScope::cases())));

            return self::FAILURE;
        }

        $name = (string) $this->argument('name');

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            $this->error(__('The name may be at most :max characters long.', ['max' => self::NAME_MAX_LENGTH]));

            return self::FAILURE;
        }

        ['key' => $key, 'plain' => $plain, 'secret' => $secret] = ApiKey::generate($name, $scope);

        $this->components->info("API key \"{$key->name}\" ({$scope->value}) created. Copy it now; it is not shown again.");
        $this->line($plain);

        if ($secret !== null) {
            $this->components->info('Signing secret: every request with this key must be signed (see /docs/mobile). Copy it now; it is not shown again.');
            $this->line($secret);
        }

        return self::SUCCESS;
    }
}
