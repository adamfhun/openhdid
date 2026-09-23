<?php

namespace App\Auth\Oidc;

use App\Audit\Auditor;
use App\Auth\Contracts\Principal;
use App\Auth\LoginRejectedException;
use App\Auth\LoginRejection;
use App\Enums\PrincipalType;
use App\Settings\Settings;
use Illuminate\Database\Eloquent\Model;

class SsoAccess
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Auditor $auditor,
    ) {}

    public function isAvailable(PrincipalType $principal, OidcProvider $provider): bool
    {
        return $this->settings->bool($provider->enabledSetting($principal))
            && $provider->configFor($principal)->isConfigured();
    }

    /**
     * Refuses a provider that is switched off or lacks its configuration. Once
     * the account is known (the check after the identity provider answered),
     * the audit entry names it.
     *
     * @throws LoginRejectedException
     */
    public function assertEnabled(PrincipalType $principal, OidcProvider $provider, string $method, ?Principal $account = null): void
    {
        // A provider can be disabled while an external authentication request is in flight.
        $this->settings->forgetLocal();

        if ($this->isAvailable($principal, $provider)) {
            return;
        }

        $subject = $account instanceof Model ? $account : null;

        $this->auditor->record('login.rejected', $subject, array_filter([
            'principal' => $principal->value,
            'provider' => $provider->value,
            'method' => $method,
            'reason' => LoginRejection::MethodDisabled->value,
            'email' => $subject?->getAttribute('email'),
            'configured' => $provider->configFor($principal)->isConfigured() ? null : false,
        ], fn (mixed $value): bool => $value !== null));

        throw new LoginRejectedException(LoginRejection::MethodDisabled);
    }
}
