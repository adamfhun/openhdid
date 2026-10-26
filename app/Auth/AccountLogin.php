<?php

namespace App\Auth;

use App\Audit\Auditor;
use App\Auth\Contracts\Principal;
use App\Clients\ClientTiers;
use App\Enums\PrincipalType;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\System\DemoData;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;

/**
 * The one rule every login path goes through: an identity may only log in
 * when a local account exists, is open, is linked to a present external
 * record that carries the same e-mail (for clients unless
 * CLIENT_LOGIN_REQUIRE_EMD is off), (for staff) carries a panel
 * permission, and (for clients) has a package that grants portal access.
 * In production a demo account (App\System\DemoData) never signs in.
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
     * @param  array<string, mixed>  $context  what a refusal's audit entry records besides the address
     *
     * @throws LoginRejectedException
     */
    public function resolveByEmail(PrincipalType $type, string $email, string $method, array $context = []): Principal
    {
        $email = mb_strtolower(trim($email));

        return $this->assertEligible($this->findByEmail($type, $email), $type, $method, ['email' => $email] + $context);
    }

    /**
     * The account whose stored address is exactly this one (case aside). The
     * database collation is accent- and case-insensitive, so a look-alike
     * address ("víctim@…") would find the account too and dodge every
     * per-address limit; such a match counts as no account.
     */
    public function findByEmail(PrincipalType $type, string $email): ?Principal
    {
        $email = mb_strtolower(trim($email));

        /** @var Principal|null $principal */
        $principal = $type->modelClass()::query()->where('email', $email)->first();

        return $principal !== null && mb_strtolower(trim($principal->getEmail())) === $email ? $principal : null;
    }

    /**
     * The account whose EMD record carries this external id (employee-id
     * mode of the company sign-in): the record found by its id, then the
     * account of the asked kind linked to it. Locally created records (ids
     * from ExternalRecord::LOCAL_ID_BASE up) never come from the directory,
     * so they cannot be found this way.
     *
     * @param  array<string, mixed>  $context  what a refusal's audit entry records besides the id
     *
     * @throws LoginRejectedException
     */
    public function resolveByExternalId(PrincipalType $type, int $externalId, string $method, array $context = []): Principal
    {
        return $this->assertEligible($this->findByExternalId($type, $externalId), $type, $method, ['external_id' => $externalId] + $context);
    }

    public function findByExternalId(PrincipalType $type, int $externalId): ?Principal
    {
        if ($externalId <= 0 || $externalId >= ExternalRecord::LOCAL_ID_BASE) {
            return null;
        }

        $record = ExternalRecord::query()->where('external_id', $externalId)->first();

        if ($record === null) {
            return null;
        }

        /** @var Principal|null $principal */
        $principal = $type->modelClass()::query()->where('external_record_id', $record->getKey())->first();

        return $principal;
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
            // Premium-only mode turns standard clients away with the usual
            // "not entitled"; the audit names the mode, so staff see why.
            $heldBack = $rejection === LoginRejection::NotEntitled && $principal instanceof Client && $this->tiers->heldBackByPremiumOnly($principal);

            $this->auditor->record('login.rejected', $principal, $context + [
                'principal' => $type->value,
                'method' => $method,
                'reason' => $rejection->value,
            ] + ($heldBack ? ['premium_only' => true] : []) + self::emdContext($principal));

            throw new LoginRejectedException($rejection);
        }

        return $principal;
    }

    /**
     * Signs the account in on its session guard. A remember-me cookie is set
     * only where the path allows it (SSO, password) and the setting is on;
     * the passwordless paths never set one.
     */
    /**
     * @param  array<string, mixed>  $context  extra facts for the login.succeeded audit entry
     */
    public function loginToSession(Principal $principal, string $method, bool $allowRemember = false, array $context = []): void
    {
        $guard = $this->auth->guard($principal->principalType()->guard());
        $remember = $allowRemember && $this->applyRememberDuration($principal->principalType());

        if ($guard instanceof StatefulGuard) {
            $guard->login($principal, $remember);
        }

        $this->recordSuccess($principal, $method, $context);
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
     *
     * @param  array<string, mixed>  $context  extra facts for the login.succeeded audit entry
     */
    public function issueToken(Principal $principal, string $method, string $deviceName, array $context = []): string
    {
        $abilities = $principal instanceof Client ? [self::ABILITY_CLIENT_PORTAL] : [self::ABILITY_STAFF];
        $token = $principal->createToken($deviceName, $abilities)->plainTextToken;
        $this->recordSuccess($principal, $method, $context + ['device' => $deviceName]);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordSuccess(Principal $principal, string $method, array $context = []): void
    {
        $principal->forceFill(['last_login_at' => now()])->saveQuietly();
        $this->auditor->record('login.succeeded', $principal, $context + ['method' => $method] + self::emdContext($principal), $principal);
    }

    /**
     * Whether client logins ask for a present EMD record with the same
     * e-mail (CLIENT_LOGIN_REQUIRE_EMD, on unless switched off).
     */
    public static function clientsNeedEmd(): bool
    {
        return (bool) config('hdid.client_login.require_emd', true);
    }

    /**
     * Audit detail of a client login while the EMD record is not asked for.
     *
     * @return array<string, bool>
     */
    private static function emdContext(?Principal $principal): array
    {
        return $principal instanceof Client && ! self::clientsNeedEmd() ? ['emd_required' => false] : [];
    }

    /**
     * The EMD part of the rule: a present record with the same e-mail. Not
     * asked of clients while CLIENT_LOGIN_REQUIRE_EMD is off.
     */
    private function directoryRejection(Principal $principal): ?LoginRejection
    {
        if ($principal instanceof Client && ! self::clientsNeedEmd()) {
            return null;
        }

        $record = $principal->externalRecord;

        return match (true) {
            $record === null => LoginRejection::NoExternalRecord,
            $record->isMissing() => LoginRejection::ExternalRecordMissing,
            mb_strtolower(trim((string) $record->email)) !== mb_strtolower(trim($principal->getEmail())) => LoginRejection::ExternalRecordMismatch,
            default => null,
        };
    }

    private function rejectionFor(?Principal $principal): ?LoginRejection
    {
        if ($principal === null) {
            return LoginRejection::NoAccount;
        }

        // The demo accounts' passwords are published: never in production.
        if (DemoData::refusedHere() && DemoData::isDemoAccount($principal)) {
            return LoginRejection::DemoAccount;
        }

        if ($principal->isClosed()) {
            return LoginRejection::AccountClosed;
        }

        if (($directory = $this->directoryRejection($principal)) !== null) {
            return $directory;
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
