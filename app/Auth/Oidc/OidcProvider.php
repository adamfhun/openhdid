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

        // Entra is always addressed by the dedicated tenant's directory id (GUID,
        // the token's tid claim); without one the provider is not configured at all.
        $tenant = isset($cfg['tenant']) && trim((string) $cfg['tenant']) !== '' ? trim((string) $cfg['tenant']) : null;

        $discoveryUrl = match ($this) {
            self::Adfs => rtrim((string) ($cfg['issuer'] ?? ''), '/').'/.well-known/openid-configuration',
            self::Entra => 'https://login.microsoftonline.com/'.($tenant ?? 'unconfigured').'/v2.0/.well-known/openid-configuration',
        };

        return new OidcProviderConfig(
            provider: $this,
            principal: $principal,
            discoveryUrl: $discoveryUrl,
            clientId: (string) ($cfg['client_id'] ?? ''),
            clientSecret: (string) ($cfg['client_secret'] ?? ''),
            mobileClientId: isset($cfg['mobile_client_id']) ? (string) $cfg['mobile_client_id'] : null,
            tenant: $this === self::Entra ? $tenant : null,
        );
    }
}
