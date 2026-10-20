<?php

namespace App\Console\Commands;

use App\Auth\ConsoleAccountChanges;
use App\Auth\Mfa\StaffMfa;
use App\Auth\Role;
use App\Enums\PrincipalType;
use App\Models\ExternalRecord;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Bootstraps the very first super-admin. Creates a matching external record
 * so that the login rule (account + external record) is satisfied.
 *
 * While two-factor sign-in is on, an existing account (its password, role,
 * closure) changes only with --force; a new one needs its second factor at
 * the first sign-in anyway. Every run is audited and e-mailed to the other
 * super-admins (ConsoleAccountChanges).
 */
class MakeAdminCommand extends Command
{
    /** Local (non-synced) records get ids far above anything the directory uses. */
    protected $signature = 'hdid:make-admin {email} {--name=Administrator} {--password= : Omit to be prompted} {--force : With two-factor sign-in on, also change an existing account}';

    protected $description = 'Create (or promote) a super-admin user with password login';

    public function handle(StaffMfa $mfa, ConsoleAccountChanges $changes): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->firstOrNew(['email' => $email]);

        if ($user->exists && $mfa->isEnabled() && ! $this->option('force')) {
            $this->error('Two-factor sign-in is on: changing an existing account (password, role, reopening) needs --force. The change is audited and e-mailed to the other super-admins.');

            return self::FAILURE;
        }

        $password = $this->option('password') ?: $this->secret('Password (min. 12 characters)');

        if (strlen((string) $password) < 12) {
            $this->error('The password must be at least 12 characters.');

            return self::FAILURE;
        }

        $created = ! $user->exists;
        $wasClosed = $user->exists && $user->isClosed();
        $wasSuperAdmin = $user->exists && $user->hasRole(Role::SuperAdmin->value);
        $user->fill(['name' => $user->name ?? $this->option('name'), 'password' => $password]);

        if ($user->external_record_id === null) {
            $record = ExternalRecord::query()->where('email', $email)->first()
                ?? ExternalRecord::query()->create([
                    'external_id' => ExternalRecord::LOCAL_ID_BASE + (int) ExternalRecord::query()->where('external_id', '>=', ExternalRecord::LOCAL_ID_BASE)->count() + 1,
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

        $changes->record('account.admin_made_by_command', $user, $created
            ? __('The command hdid:make-admin created the super-admin account :email.', ['email' => $user->email])
            : __('The command hdid:make-admin set a new password for the account :email and made it super-admin.', ['email' => $user->email]), [
                'created' => $created,
                'password_set' => true,
                'promoted' => ! $wasSuperAdmin,
                'reopened' => $wasClosed,
                'forced' => (bool) $this->option('force'),
                'mfa_enabled' => $mfa->isEnabled(),
            ]);

        $this->info("Super-admin {$email} is ready. Sign in at ".url('/admin'));
        if ($mfa->isEnabled()) {
            $this->line('Two-factor sign-in is on: the account sets up or uses its second factor at sign-in.');
        }

        return self::SUCCESS;
    }
}
