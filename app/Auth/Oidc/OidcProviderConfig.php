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

    /** The claim the employee id is read from when the match mode asks for it. */
    public const DEFAULT_EMPLOYEE_ID_CLAIM = 'employeeId';

    /**
     * @param  list<string>  $emailClaims  ADFS: the claims compared with the account's address, in order
     * @param  bool  $debug  ADFS: write the decoded tokens to the application log
     * @param  SsoMatchMode|null  $matchMode  which claim finds the account; null for an unknown configured value
     * @param  string  $employeeIdClaim  the claim compared with the EMD record's external id in employee-id mode
     * @param  bool  $requireSubject  refuse a token without a stable subject (oid, sub) instead of signing in unbound
     * @param  list<EntraTenant>  $tenants  Entra: the tenants a sign-in may come from (one for a dedicated tenant, several for the client portal)
     * @param  string|null  $tenantProblem  Entra: why the tenant configuration cannot be used
     * @param  string|null  $tenantNote  Entra: a configuration oddity worth fixing that does not stop sign-ins
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
        public ?SsoMatchMode $matchMode = SsoMatchMode::Email,
        public string $employeeIdClaim = self::DEFAULT_EMPLOYEE_ID_CLAIM,
        public bool $requireSubject = true,
        public array $tenants = [],
        public ?string $tenantProblem = null,
        public ?string $tenantNote = null,
    ) {}

    public function isConfigured(): bool
    {
        if ($this->clientId === '' || $this->matchMode === null) {
            return false;
        }

        return match ($this->provider) {
            OidcProvider::Adfs => $this->discoveryUrl !== '/.well-known/openid-configuration',
            OidcProvider::Entra => $this->hasDedicatedTenant(),
        };
    }

    /**
     * At least one usable tenant and no problem in the list: the shared
     * endpoints never count, since a token from any tenant would validate.
     */
    public function hasDedicatedTenant(): bool
    {
        return $this->tenants !== [] && $this->tenantProblem === null;
    }

    /**
     * Several tenants: the shared "organizations" endpoint issues the
     * tokens, and the tenant claim is checked against the list.
     */
    public function isMultiTenant(): bool
    {
        return count($this->tenants) > 1;
    }

    public function allowsTenant(?string $tenantId): bool
    {
        return $this->tenantFor($tenantId) !== null;
    }

    public function tenantFor(?string $tenantId): ?EntraTenant
    {
        if ($tenantId === null || trim($tenantId) === '') {
            return null;
        }

        foreach ($this->tenants as $tenant) {
            if (strcasecmp($tenant->id, trim($tenantId)) === 0) {
                return $tenant;
            }
        }

        return null;
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
