<?php

namespace App\Auth\Oidc;

use App\Enums\PrincipalType;

final readonly class OidcProviderConfig
{
    /**
     * Entra's shared endpoints issue tokens for any tenant; accounts bound by
     * e-mail could then be claimed from a foreign tenant, so they never count
     * as a configured provider.
     */
    public const ENTRA_SHARED_TENANTS = ['common', 'organizations', 'consumers'];

    public function __construct(
        public OidcProvider $provider,
        public PrincipalType $principal,
        public string $discoveryUrl,
        public string $clientId,
        public string $clientSecret,
        public ?string $mobileClientId = null,
        public ?string $tenant = null,
        public string $scopes = 'openid profile email',
    ) {}

    public function isConfigured(): bool
    {
        if ($this->clientId === '') {
            return false;
        }

        return match ($this->provider) {
            OidcProvider::Adfs => $this->discoveryUrl !== '/.well-known/openid-configuration',
            OidcProvider::Entra => $this->hasDedicatedTenant(),
        };
    }

    public function hasDedicatedTenant(): bool
    {
        return $this->tenant !== null
            && trim($this->tenant) !== ''
            && ! in_array(strtolower(trim($this->tenant)), self::ENTRA_SHARED_TENANTS, true);
    }
}
