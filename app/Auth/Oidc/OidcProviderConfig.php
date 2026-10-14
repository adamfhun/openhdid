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

    /** Scopes every authorization request carries. */
    public const BASE_SCOPES = 'openid profile email';

    /**
     * ADFS claims compared with the account's e-mail address when the
     * environment names none: the address claim of an ADFS issuance rule
     * first, then the claims ADFS sends without any rule.
     */
    public const ADFS_DEFAULT_EMAIL_CLAIMS = ['emailaddress', 'email', 'upn', 'preferred_username', 'unique_name'];

    /**
     * @param  list<string>  $emailClaims  ADFS: the claims compared with the account's address, in order
     * @param  bool  $debug  ADFS: write the decoded tokens to the application log
     */
    public function __construct(
        public OidcProvider $provider,
        public PrincipalType $principal,
        public string $discoveryUrl,
        public string $clientId,
        public string $clientSecret,
        public ?string $mobileClientId = null,
        public ?string $tenant = null,
        public string $scopes = self::BASE_SCOPES,
        public array $emailClaims = self::ADFS_DEFAULT_EMAIL_CLAIMS,
        public ?string $resource = null,
        public bool $debug = false,
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

    /**
     * Whether the decoded tokens go to the application log (ADFS only).
     */
    public function logsTokens(): bool
    {
        return $this->debug && $this->provider === OidcProvider::Adfs;
    }

    /**
     * The base scopes followed by the configured extra ones, each once.
     */
    public static function scopesWith(?string $extra): string
    {
        return implode(' ', array_values(array_unique([
            ...self::listOf(self::BASE_SCOPES),
            ...self::listOf((string) $extra),
        ])));
    }

    /**
     * A space- or comma-separated environment value as a list.
     *
     * @return list<string>
     */
    public static function listOf(string $value): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', trim($value)) ?: [], fn (string $item): bool => $item !== ''));
    }
}
