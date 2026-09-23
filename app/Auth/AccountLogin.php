<?php

namespace App\Auth;

use App\Audit\Auditor;
use App\Auth\Contracts\Principal;
use App\Clients\ClientTiers;
use App\Enums\PrincipalType;
use App\Models\Client;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;

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

    public function loginToSession(Principal $principal, string $method, bool $remember = false): void
    {
        $guard = $this->auth->guard($principal->principalType()->guard());

        if ($guard instanceof StatefulGuard) {
            $guard->login($principal, $remember);
        }

        $this->recordSuccess($principal, $method);
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
