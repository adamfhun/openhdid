<?php

namespace App\Auth;

use App\Audit\Auditor;
use App\Auth\Contracts\Principal;
use App\Enums\PrincipalType;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Account lockout for the guessable login paths (staff password, client SMS
 * code): the configured number of wrong attempts in a row locks that path
 * of the account for the configured time. Every failure, lock, refusal and
 * unlock is audited. Single sign-on and the e-mail link stay usable, so a
 * stranger guessing passwords cannot lock a colleague out of SSO.
 */
class LoginLockout
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Auditor $auditor,
        private readonly AccountLogin $login,
    ) {}

    /**
     * The audit always names the lock; the answer can stay neutral where
     * the path must not tell whether the account exists (client SMS code).
     *
     * @throws LoginRejectedException
     */
    public function assertNotLocked(Principal&Model $account, string $method, LoginRejection $answer = LoginRejection::AccountLocked): void
    {
        if (! $account->isLoginLocked()) {
            return;
        }

        $this->login->recordRejection($account->principalType(), $method, LoginRejection::AccountLocked, [
            'email' => $account->getEmail(),
            'locked_until' => $account->locked_until?->toIso8601String(),
        ], $account);

        throw new LoginRejectedException($answer);
    }

    /**
     * A wrong password or code: audited, and the configured number in a row
     * locks the account. Parallel failures count one by one.
     */
    public function recordFailure(Principal&Model $account, string $method): void
    {
        Cache::lock('login-lockout:'.$account->getMorphClass().':'.$account->getKey(), 10)->block(5, function () use ($account, $method): void {
            $account->refresh();
            $type = $account->principalType();
            $attempts = (int) $account->failed_login_attempts + 1;
            $locks = ! $account->isLoginLocked() && $attempts >= $this->settings->int($this->key($type, 'attempts'));

            $this->login->recordRejection($type, $method, LoginRejection::InvalidCredentials, [
                'email' => $account->getEmail(),
                'failed_attempts' => $attempts,
            ], $account);

            if (! $locks) {
                $account->forceFill(['failed_login_attempts' => $attempts])->saveQuietly();

                return;
            }

            $until = now()->addMinutes($this->settings->int($this->key($type, 'minutes')));
            $account->forceFill(['failed_login_attempts' => 0, 'locked_until' => $until])->saveQuietly();
            $this->auditor->record('account.locked', $account, [
                'principal' => $type->value,
                'method' => $method,
                'failed_attempts' => $attempts,
                'locked_until' => $until->toIso8601String(),
            ]);
        });
    }

    public function recordSuccess(Principal&Model $account): void
    {
        if ((int) $account->failed_login_attempts !== 0) {
            $account->forceFill(['failed_login_attempts' => 0])->saveQuietly();
        }
    }

    /**
     * A colleague lifts the lock before it runs out.
     */
    public function unlock(Principal&Model $account): void
    {
        $lockedUntil = $account->locked_until;
        $account->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->saveQuietly();

        $this->auditor->record('account.unlocked', $account, [
            'principal' => $account->principalType()->value,
            'locked_until' => $lockedUntil?->toIso8601String(),
        ]);
    }

    private function key(PrincipalType $type, string $what): SettingKey
    {
        return match ([$type, $what]) {
            [PrincipalType::User, 'attempts'] => SettingKey::UserLoginLockoutMaxAttempts,
            [PrincipalType::User, 'minutes'] => SettingKey::UserLoginLockoutMinutes,
            [PrincipalType::Client, 'attempts'] => SettingKey::ClientLoginLockoutMaxAttempts,
            [PrincipalType::Client, 'minutes'] => SettingKey::ClientLoginLockoutMinutes,
        };
    }
}
