<?php

namespace App\Auth\Oidc;

use App\Enums\PrincipalType;
use App\Settings\SettingKey;

enum OidcProvider: string
{
    case Adfs = 'adfs';
    case Entra = 'entra';

    public function label(): string
    {
        return match ($this) {
            self::Adfs => __('Company login (ADFS)'),
            self::Entra => __('Microsoft login'),
        };
    }

    public function enabledSetting(PrincipalType $principal): SettingKey
    {
        return match ([$principal, $this]) {
            [PrincipalType::User, self::Adfs] => SettingKey::UserSsoAdfsEnabled,
            [PrincipalType::User, self::Entra] => SettingKey::UserSsoEntraEnabled,
            [PrincipalType::Client, self::Adfs] => SettingKey::ClientSsoAdfsEnabled,
            [PrincipalType::Client, self::Entra] => SettingKey::ClientSsoEntraEnabled,
        };
    }

    /**
     * The .env keys an operator has to fill before this provider can be
     * switched on for the given account type.
     *
     * @return list<string>
     */
    public function envKeys(PrincipalType $principal): array
    {
        $prefix = strtoupper($principal->value).'_'.strtoupper($this->value).'_';

        return array_map(fn (string $key) => $prefix.$key, match ($this) {
            self::Adfs => ['ISSUER', 'CLIENT_ID', 'CLIENT_SECRET'],
            self::Entra => ['TENANT', 'CLIENT_ID', 'CLIENT_SECRET'],
        });
    }

    public function configFor(PrincipalType $principal): OidcProviderConfig
    {
        /** @var array<string, mixed> $cfg */
        $cfg = config("hdid.oidc.{$principal->value}.{$this->value}", []);
        /** @var array<string, mixed> $principalCfg */
        $principalCfg = config("hdid.oidc.{$principal->value}", []);

        // Entra is addressed by the dedicated tenant's directory id (the token's
        // tid claim); the client portal may list several tenants, which the
        // shared "organizations" endpoint serves while the list decides who may
        // sign in. Without a usable tenant the provider is not configured at all.
        $tenants = EntraTenants::parse($cfg['tenant'] ?? null, $principal === PrincipalType::Client ? ($cfg['tenants'] ?? null) : null);
        $tenant = $tenants->tenants[0]->id ?? null;

        $discoveryUrl = match ($this) {
            self::Adfs => rtrim((string) ($cfg['issuer'] ?? ''), '/').'/.well-known/openid-configuration',
            self::Entra => 'https://login.microsoftonline.com/'.(count($tenants->tenants) > 1 ? 'organizations' : ($tenant ?? 'unconfigured')).'/v2.0/.well-known/openid-configuration',
        };

        return new OidcProviderConfig(
            provider: $this,
            principal: $principal,
            discoveryUrl: $discoveryUrl,
            clientId: (string) ($cfg['client_id'] ?? ''),
            clientSecret: (string) ($cfg['client_secret'] ?? ''),
            mobileClientId: isset($cfg['mobile_client_id']) ? (string) $cfg['mobile_client_id'] : null,
            tenant: $this === self::Entra ? $tenant : null,
            scopes: $this === self::Adfs ? OidcProviderConfig::scopesWith($cfg['scopes'] ?? null) : OidcProviderConfig::BASE_SCOPES,
            emailClaims: OidcProviderConfig::listOf((string) ($cfg['email_claims'] ?? '')) ?: OidcProviderConfig::ADFS_DEFAULT_EMAIL_CLAIMS,
            resource: $this === self::Adfs && trim((string) ($cfg['resource'] ?? '')) !== '' ? trim((string) $cfg['resource']) : null,
            debug: $this === self::Adfs && ($cfg['debug'] ?? false) === true,
            matchMode: SsoMatchMode::fromConfig($principalCfg['match'] ?? null),
            employeeIdClaim: trim((string) ($principalCfg['employee_id_claim'] ?? '')) ?: OidcProviderConfig::DEFAULT_EMPLOYEE_ID_CLAIM,
            requireSubject: (bool) config('hdid.oidc.require_subject', true),
            tenants: $this === self::Entra ? $tenants->tenants : [],
            tenantProblem: $this === self::Entra ? $tenants->problem : null,
            tenantNote: $this === self::Entra ? $tenants->note : null,
        );
    }
}
