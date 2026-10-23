<?php

namespace App\Auth\Oidc;

use App\Enums\PrincipalType;

/**
 * The stable identity of the person who signed in, as the provider names
 * it: Entra's object id (`oid`, unique within the tenant whose issuer the
 * token carries), ADFS's `sub` (pairwise per client registration). Together
 * with the issuer and the client registration it is the key an account is
 * bound to; the e-mail address or employee id only finds the account.
 */
final readonly class SsoSubject
{
    public function __construct(
        public OidcProvider $provider,
        public string $issuer,
        public string $clientId,
        public string $subject,
        public string $claim,
    ) {}

    /**
     * The claim that carries the provider's stable subject.
     */
    public static function claimOf(OidcProvider $provider): string
    {
        return match ($provider) {
            OidcProvider::Entra => 'oid',
            OidcProvider::Adfs => 'sub',
        };
    }

    /**
     * The subject of a validated ID token, or null when the token has none.
     * The registration is the configured web client id even for the mobile
     * app's token: Entra's object id does not depend on the app, so one
     * binding serves the browser and the mobile app alike.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function fromClaims(array $claims, OidcProviderConfig $config): ?self
    {
        $claim = self::claimOf($config->provider);
        $subject = $claims[$claim] ?? null;
        $issuer = $claims['iss'] ?? null;

        if (! is_string($subject) || trim($subject) === '' || ! is_string($issuer) || trim($issuer) === '') {
            return null;
        }

        return new self($config->provider, trim($issuer), $config->clientId, trim($subject), $claim);
    }

    /**
     * The hashed uniqueness key: one subject of one registration binds at
     * most one account of a kind.
     */
    public function lookup(PrincipalType $type): string
    {
        return self::lookupOf($type, $this->provider, $this->issuer, $this->clientId, $this->subject);
    }

    /**
     * Whether a stored binding was made for the registration this sign-in
     * comes from. Entra's object id is the same for every app registration
     * of the tenant, so only the issuer (which carries the tenant) counts;
     * ADFS's subject is pairwise per client id, so the client id counts too.
     */
    public function isSameRegistration(string $issuer, string $clientId): bool
    {
        return $this->issuer === $issuer && self::registrationClientId($this->provider, $this->clientId) === self::registrationClientId($this->provider, $clientId);
    }

    public static function lookupOf(PrincipalType $type, OidcProvider $provider, string $issuer, string $clientId, string $subject): string
    {
        return hash('sha256', implode("\0", [$type->value, $provider->value, $issuer, self::registrationClientId($provider, $clientId), $subject]));
    }

    /**
     * The part of the client id that makes a registration distinct for the
     * subject: nothing for Entra, the client id for ADFS.
     */
    private static function registrationClientId(OidcProvider $provider, string $clientId): string
    {
        return $provider === OidcProvider::Entra ? '' : $clientId;
    }
}
