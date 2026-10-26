<?php

namespace App\Console\Commands;

use App\Auth\AccountLogin;
use App\Auth\ConsoleAccountChanges;
use App\Auth\Mfa\StaffMfa;
use App\Auth\Role;
use App\Auth\RoleAssignment;
use App\Enums\PrincipalType;
use App\Models\ExternalRecord;
use App\Models\User;
use App\Support\EmailAddressRule;
use App\Support\InputLimits;
use App\Sync\AccountProvisioner;
use App\System\DemoData;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Bootstraps the very first super-admin. Creates a matching external record
 * so that the login rule (account + external record) is satisfied. It is
 * also the rescue path: the SuperAdmin role is recreated if it is missing
 * and gets every permission back (RoleAssignment::restoreSuperAdminRole).
 *
 * While two-factor sign-in is on, an existing account (its password, role,
 * closure) changes only with --force; a new one needs its second factor at
 * the first sign-in anyway. Every run is audited and e-mailed to the other
 * super-admins (ConsoleAccountChanges).
 *
 * In production it warns while the database holds the demo data, and it
 * refuses a demo (.test) address (App\System\DemoData): such an account
 * cannot sign in there, and the demo data must not go live. Addresses are
 * matched exactly, as at sign-in: an account or record whose address only
 * the database collation equates with the given one is never taken over.
 */
class MakeAdminCommand extends Command
{
    /** Local (non-synced) records get ids far above anything the directory uses. */
    protected $signature = 'hdid:make-admin {email} {--name=Administrator} {--password= : Omit to be prompted} {--force : With two-factor sign-in on, also change an existing account}';

    protected $description = 'Create (or promote) a super-admin user with password login';

    public function handle(StaffMfa $mfa, ConsoleAccountChanges $changes, RoleAssignment $roles): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        // Refused before anything is written: the columns hold no more.
        $problem = EmailAddressRule::problem($email)
            ?? (mb_strlen((string) $this->option('name')) > InputLimits::NAME ? __('The name may be at most :max characters long.', ['max' => InputLimits::NAME]) : null);

        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        // Production refuses a demo address: such an account could never sign in there,
        // and one more .test address would make the database look like the demo data.
        if (DemoData::refusedHere() && DemoData::isDemoAddress($email)) {
            $this->error("{$email} is under the reserved .test domain of the demo accounts: with APP_ENV=production it cannot sign in, and the database would look like it holds the demo data. Use the administrator's real address.");

            return self::FAILURE;
        }

        // Exact match, like the sign-in (AccountLogin::findByEmail): the MariaDB collation
        // equates look-alike addresses ("róot@…" and "root@…"), and the command must not
        // promote, or set the password of, somebody else's account.
        /** @var User|null $user */
        $user = app(AccountLogin::class)->findByEmail(PrincipalType::User, $email);
        $lookAlike = $user === null ? User::query()->where('email', $email)->first() : null;

        if ($lookAlike !== null) {
            $this->error("An account with a look-alike address already exists: {$lookAlike->email}. The database treats it as the same address, so a second account cannot be created, and that account is not changed. Use that address exactly if it is the one meant, or change it on the panel first.");

            return self::FAILURE;
        }

        $user ??= new User(['email' => $email]);

        if ($user->exists && $mfa->isEnabled() && ! $this->option('force')) {
            $this->error('Two-factor sign-in is on: changing an existing account (password, role, reopening) needs --force. The change is audited and e-mailed to the other super-admins.');

            return self::FAILURE;
        }

        $this->warnAboutDemoData($email);

        $password = $this->option('password') ?: $this->secret('Password (min. 12 characters)');

        if (strlen((string) $password) < 12) {
            $this->error('The password must be at least 12 characters.');

            return self::FAILURE;
        }

        // The role first: a refused rescue must leave no half-made account or record behind.
        try {
            $role = $roles->restoreSuperAdminRole();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $created = ! $user->exists;
        $wasClosed = $user->exists && $user->isClosed();
        $wasSuperAdmin = $user->exists && $user->hasRole(Role::SuperAdmin->value);
        $user->fill(['name' => $user->name ?? $this->option('name'), 'password' => $password]);

        if ($user->external_record_id === null) {
            $record = $this->exactStaffRecord($email) ?? ExternalRecord::query()->create([
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

        if (! $roles->isSuperAdmin($user->refresh())) {
            $this->error('The account did not get the SuperAdmin role; check the roles table.');

            return self::FAILURE;
        }

        $changes->record('account.admin_made_by_command', $user, $created
            ? __('The command hdid:make-admin created the super-admin account :email.', ['email' => $user->email])
            : __('The command hdid:make-admin set a new password for the account :email and made it super-admin.', ['email' => $user->email]), [
                'created' => $created,
                'password_set' => true,
                'promoted' => ! $wasSuperAdmin,
                'reopened' => $wasClosed,
                'forced' => (bool) $this->option('force'),
                'mfa_enabled' => $mfa->isEnabled(),
                'role_recreated' => $role['created'],
                'role_permissions_restored' => $role['restored'],
            ]);

        if ($role['created']) {
            $this->warn('The super-admin role was missing (renamed or deleted) and has been recreated with every permission.');
        } elseif ($role['restored'] !== []) {
            $this->warn('The super-admin role had lost permissions; restored: '.implode(', ', $role['restored']).'.');
        }

        $this->info("Super-admin {$user->email} is ready. Sign in at ".url('/admin'));
        if ($mfa->isEnabled()) {
            $this->line('Two-factor sign-in is on: the account sets up or uses its second factor at sign-in.');
        }

        return self::SUCCESS;
    }

    /**
     * The staff record that carries exactly this address. A record whose
     * address only the database collation equates with it belongs to
     * somebody else: the account gets a local record of its own instead
     * (the sync then reports the look-alike row as an e-mail conflict),
     * and the operator is told which record was passed over.
     */
    private function exactStaffRecord(string $email): ?ExternalRecord
    {
        $found = ExternalRecord::query()->where('email', $email)->get();
        $exact = $found->first(fn (ExternalRecord $record): bool => $record->kind === PrincipalType::User && AccountProvisioner::emailKey($record->email) === $email);

        if ($exact === null) {
            foreach ($found as $record) {
                $this->warn("EMD record {$record->external_id} carries the look-alike address {$record->email}; it is not linked to this account, which gets a local record of its own. Check in EMD which address is right.");
            }
        }

        return $exact;
    }

    /**
     * Production only: a database that holds the demo data must be
     * replaced by an empty one.
     */
    private function warnAboutDemoData(string $email): void
    {
        if (! DemoData::refusedHere()) {
            return;
        }

        if (app(DemoData::class)->present()) {
            $this->warn('This database holds the demo data (published passwords, PIN and answers) while APP_ENV=production: the demo accounts cannot sign in and the status page shows the "Demo data" tile red. Start production with an empty database (in the container: docker compose down -v, then docker compose up -d) and run this command there.');
        }
    }
}
