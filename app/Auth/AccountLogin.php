<?php

namespace App\Auth;

use App\Audit\Auditor;
use App\Auth\Contracts\Principal;
use App\Clients\ClientTiers;
use App\Enums\PrincipalType;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;

/**
 * The one rule every login path goes through: an identity may only log in
 * when a local account exists, is open, is linked to a present external
 * record that carries the same e-mail, (for staff) carries a panel
 * permission, and (for clients) has a package that grants portal access.
 */
class AccountLogin
{
    public const ABILITY_CLIENT_PORTAL = 'client:portal';

    public const ABILITY_STAFF = 'staff';

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Auditor $auditor,
        private readonly ClientTiers $tiers,
        private readonly Settings $settings,
    ) {}

    /**
     * @throws LoginRejectedException
     */
    public function resolveByEmail(PrincipalType $type, string $email, string $method): Principal
    {
        $email = mb_strtolower(trim($email));

        /** @var Principal|null $principal */
        $principal = $type->modelClass()::query()->where('email', $email)->first();

        return $this->assertEligible($principal, $type, $method, ['email' => $email]);
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws LoginRejectedException
     */
    public function assertEligible(?Principal $principal, PrincipalType $type, string $method, array $context = []): Principal
    {
        $rejection = $this->rejectionFor($principal);

        if ($rejection !== null) {
            $this->auditor->record('login.rejected', $principal, $context + [
                'principal' => $type->value,
                'method' => $method,
                'reason' => $rejection->value,
            ]);

            throw new LoginRejectedException($rejection);
        }

        return $principal;
    }

    /**
     * Signs the account in on its session guard. A remember-me cookie is set
     * only where the path allows it (SSO, password) and the setting is on;
     * the passwordless paths never set one.
     */
    public function loginToSession(Principal $principal, string $method, bool $allowRemember = false): void
    {
        $guard = $this->auth->guard($principal->principalType()->guard());
        $remember = $allowRemember && $this->applyRememberDuration($principal->principalType());

        if ($guard instanceof StatefulGuard) {
            $guard->login($principal, $remember);
        }

        $this->recordSuccess($principal, $method);
    }

    /**
     * Remember-me lifetime in minutes, or null while it is switched off.
     */
    public function rememberMinutes(PrincipalType $type): ?int
    {
        [$enabled, $days] = $type === PrincipalType::User
            ? [SettingKey::UserLoginRememberEnabled, SettingKey::UserLoginRememberDays]
            : [SettingKey::ClientLoginRememberEnabled, SettingKey::ClientLoginRememberDays];

        return $this->settings->bool($enabled) ? $this->settings->int($days) * 1440 : null;
    }

    /**
     * Sets the configured remember-me lifetime on the guard; false while
     * remember-me is switched off.
     */
    public function applyRememberDuration(PrincipalType $type): bool
    {
        $minutes = $this->rememberMinutes($type);
        $guard = $this->auth->guard($type->guard());

        if ($minutes !== null && $guard instanceof SessionGuard) {
            $guard->setRememberDuration($minutes);
        }

        return $minutes !== null;
    }

    /**
     * A failed login attempt that never reached an account rule (wrong or
     * replayed token, state mismatch, unknown address): audited all the same.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordRejection(PrincipalType $type, string $method, LoginRejection $reason, array $context = [], ?Principal $principal = null): void
    {
        $this->auditor->record('login.rejected', $principal instanceof Model ? $principal : null, array_filter($context, fn (mixed $value): bool => $value !== null) + [
            'principal' => $type->value,
            'method' => $method,
            'reason' => $reason->value,
        ]);
    }

    /**
     * A personal access token scoped to the principal's own surface; it
     * expires after the configured Sanctum lifetime (30 days by default).
     */
    public function issueToken(Principal $principal, string $method, string $deviceName): string
    {
        $abilities = $principal instanceof Client ? [self::ABILITY_CLIENT_PORTAL] : [self::ABILITY_STAFF];
        $token = $principal->createToken($deviceName, $abilities)->plainTextToken;
        $this->recordSuccess($principal, $method, ['device' => $deviceName]);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordSuccess(Principal $principal, string $method, array $context = []): void
    {
        $principal->forceFill(['last_login_at' => now()])->saveQuietly();
        $this->auditor->record('login.succeeded', $principal, $context + ['method' => $method], $principal);
    }

    private function rejectionFor(?Principal $principal): ?LoginRejection
    {
        if ($principal === null) {
            return LoginRejection::NoAccount;
        }

        if ($principal->isClosed()) {
            return LoginRejection::AccountClosed;
        }

        $record = $principal->externalRecord;

        if ($record === null) {
            return LoginRejection::NoExternalRecord;
        }

        if ($record->isMissing()) {
            return LoginRejection::ExternalRecordMissing;
        }

        if (mb_strtolower(trim((string) $record->email)) !== mb_strtolower(trim($principal->getEmail()))) {
            return LoginRejection::ExternalRecordMismatch;
        }

        if ($principal instanceof User && ! $principal->hasAnyPermission([Permission::AdminAccess->value, Permission::HelpdeskAccess->value])) {
            return LoginRejection::NoPermission;
        }

        if ($principal instanceof Client && ! $this->tiers->isEntitled($principal)) {
            return LoginRejection::NotEntitled;
        }

        return null;
    }
}
