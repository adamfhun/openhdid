<?php

namespace App\Console\Commands;

use App\Auth\Role;
use App\Enums\PrincipalType;
use App\Models\ExternalRecord;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Bootstraps the very first super-admin. Creates a matching external record
 * so that the login rule (account + external record) is satisfied.
 */
class MakeAdminCommand extends Command
{
    /** Local (non-synced) records get ids far above anything the directory uses. */
    private const LOCAL_ID_BASE = 9_000_000_000;

    protected $signature = 'hdid:make-admin {email} {--name=Administrator} {--password= : Omit to be prompted}';

    protected $description = 'Create (or promote) a super-admin user with password login';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $password = $this->option('password') ?: $this->secret('Password (min. 12 characters)');

        if (strlen((string) $password) < 12) {
            $this->error('The password must be at least 12 characters.');

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->fill(['name' => $user->name ?? $this->option('name'), 'password' => $password]);

        if ($user->external_record_id === null) {
            $record = ExternalRecord::query()->where('email', $email)->first()
                ?? ExternalRecord::query()->create([
                    'external_id' => self::LOCAL_ID_BASE + (int) ExternalRecord::query()->where('external_id', '>=', self::LOCAL_ID_BASE)->count() + 1,
                    'kind' => PrincipalType::User,
                    'company' => 'local',
                    'name' => $user->name,
                    'email' => $email,
                ]);
            $user->external_record_id = $record->id;
        }

        $user->save();
        $user->reopen();
        $user->syncRoles([Role::SuperAdmin->value]);

        $this->info("Super-admin {$email} is ready. Sign in at ".url('/admin'));

        return self::SUCCESS;
    }
}
