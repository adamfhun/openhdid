<?php

namespace App\Console\Commands;

use App\Auth\AccountLogin;
use App\Auth\ConsoleAccountChanges;
use App\Auth\Mfa\StaffMfa;
use App\Enums\PrincipalType;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Console\Command;

/**
 * The last resort when nobody can pass the second factor: clears the factors
 * of one staff member (who sets up a new one at the next sign-in), and with
 * --off switches two-factor sign-in off for everyone. Both are audited and
 * e-mailed to the other super-admins (ConsoleAccountChanges).
 */
class MfaResetCommand extends Command
{
    protected $signature = 'hdid:mfa-reset {email? : The staff member whose second factors are cleared} {--off : Switch two-factor sign-in off for every staff member}';

    protected $description = 'Clear the second factors of a staff member, or switch staff two-factor sign-in off';

    public function handle(StaffMfa $mfa, Settings $settings, AccountLogin $login, ConsoleAccountChanges $changes): int
    {
        $email = $this->argument('email');

        if (! is_string($email) && ! $this->option('off')) {
            $this->error('Give a staff e-mail address, --off, or both.');

            return self::FAILURE;
        }

        if (is_string($email)) {
            $user = $login->findByEmail(PrincipalType::User, $email);

            if (! $user instanceof User) {
                $this->error("No staff account with the e-mail {$email}.");

                return self::FAILURE;
            }

            $mfa->reset($user);
            $changes->record('account.mfa_reset_by_command', $user, __('The command hdid:mfa-reset cleared the second factors of :email.', ['email' => $user->email]));
            $this->info("The second factors of {$user->email} are cleared; they set up a new one at the next sign-in.");
        }

        if ($this->option('off')) {
            $settings->set(SettingKey::UserLoginMfaEnabled, false);
            $changes->record('mfa.switched_off_by_command', null, __('The command hdid:mfa-reset --off switched two-factor sign-in off for every staff member.'));
            $this->info('Two-factor sign-in is off. Switch it on again under Settings › User login.');
        }

        return self::SUCCESS;
    }
}
