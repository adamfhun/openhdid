<?php

namespace App\Console\Commands;

use App\Auth\AccountLogin;
use App\Auth\ConsoleAccountChanges;
use App\Auth\Contracts\Principal;
use App\Auth\Oidc\OidcProvider;
use App\Auth\Oidc\SsoSubjectBinding;
use App\Enums\PrincipalType;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Clears the directory identity an account's company sign-in is bound to
 * (SsoSubjectBinding), so that the next eligible sign-in binds it anew; the
 * console counterpart of the panel's "Clear sign-in binding". With --all it
 * clears every binding of a provider (and kind), which is what a replaced
 * ADFS client registration needs: its pairwise subjects can never match
 * again. Every run is audited and e-mailed to the other super-admins
 * (ConsoleAccountChanges).
 */
class SsoUnbindCommand extends Command
{
    protected $signature = 'hdid:sso-unbind
        {email? : The account whose sign-in binding is cleared (staff and client alike, unless --principal narrows it)}
        {--principal= : Only this kind of account: user or client}
        {--provider= : Only this provider\'s binding: entra or adfs}
        {--all : Clear every binding (of the given provider and kind) instead of one account\'s}
        {--force : Do not ask for confirmation with --all}';

    protected $description = 'Clear the directory identity an account\'s company sign-in is bound to, so the next sign-in binds it anew';

    public function handle(SsoSubjectBinding $binding, AccountLogin $login, ConsoleAccountChanges $changes): int
    {
        $email = $this->argument('email');
        $principal = $this->enumOption('principal', PrincipalType::class);
        $provider = $this->enumOption('provider', OidcProvider::class);

        if ($principal === false || $provider === false) {
            return self::FAILURE;
        }

        if ($this->option('all')) {
            return $this->unbindAll($binding, $changes, $principal, $provider);
        }

        if (! is_string($email)) {
            $this->error('Give an e-mail address, or --all with --provider and/or --principal.');

            return self::FAILURE;
        }

        $accounts = [];
        foreach (($principal !== null ? [$principal] : PrincipalType::cases()) as $type) {
            $account = $login->findByEmail($type, $email);
            if ($account instanceof Model) {
                $accounts[] = $account;
            }
        }

        if ($accounts === []) {
            $this->error("No account with the e-mail {$email}.");

            return self::FAILURE;
        }

        foreach ($accounts as $account) {
            /** @var Principal&Model $account */
            $removed = $binding->unbind($account, $provider, null, ['via' => 'console']);
            $changes->record('account.sso_unbound_by_command', $account, __('The command hdid:sso-unbind cleared :n sign-in binding(s) of :email.', ['n' => $removed, 'email' => $account->getEmail()]), ['removed' => $removed, 'provider' => $provider?->value]);
            $this->info("{$account->principalType()->value} {$account->getEmail()}: {$removed} binding(s) cleared; the next company sign-in binds the account anew.");
        }

        return self::SUCCESS;
    }

    private function unbindAll(SsoSubjectBinding $binding, ConsoleAccountChanges $changes, ?PrincipalType $principal, ?OidcProvider $provider): int
    {
        $scope = ($principal?->value ?? 'every kind of account').' / '.($provider?->value ?? 'every provider');

        if (! $this->option('force') && ! $this->confirm("Clear every sign-in binding of {$scope}? Each of these accounts is bound anew by its next company sign-in.")) {
            $this->warn('Nothing changed.');

            return self::FAILURE;
        }

        $removed = $binding->unbindAll($principal, $provider, null, ['via' => 'console']);
        $changes->record('account.sso_unbound_by_command', null, __('The command hdid:sso-unbind --all cleared :n sign-in binding(s) (:scope).', ['n' => $removed, 'scope' => $scope]), ['removed' => $removed, 'principal' => $principal?->value, 'provider' => $provider?->value]);
        $this->info("{$removed} binding(s) cleared ({$scope}); each account is bound anew by its next company sign-in.");

        return self::SUCCESS;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null|false null when not given, false (after an error line) when unknown
     */
    private function enumOption(string $name, string $enum): mixed
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $case = $enum::tryFrom(strtolower(trim($value)));

        if ($case === null) {
            $this->error("Unknown --{$name} value: {$value}. Allowed: ".implode(', ', array_map(fn ($c) => $c->value, $enum::cases())).'.');

            return false;
        }

        return $case;
    }
}
