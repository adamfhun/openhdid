<?php

namespace App\Auth\Oidc;

/**
 * The validated claims of one sign-in: the ID token's, and for ADFS the
 * access token's too, since the claims of the application group's issuance
 * rules arrive there unless the client may request "allatclaims".
 */
final readonly class OidcTokens
{
    /** The access token was not looked at (Entra, mobile token exchange). */
    public const ACCESS_NOT_READ = 'not_read';

    public const ACCESS_ABSENT = 'absent';

    public const ACCESS_NOT_A_JWT = 'not_a_jwt';

    public const ACCESS_READ = 'read';

    public const ID_TOKEN = 'id_token';

    public const ACCESS_TOKEN = 'access_token';

    /**
     * @param  array<string, mixed>  $idClaims
     * @param  array<string, mixed>|null  $accessClaims  only when the access token validated
     * @param  string  $accessStatus  one of the ACCESS_* values, or "ignored: <reason>"
     */
    public function __construct(
        public array $idClaims,
        public ?array $accessClaims = null,
        public string $accessStatus = self::ACCESS_NOT_READ,
    ) {}

    /**
     * The claim sets a lookup goes through, the ID token first.
     *
     * @return array<string, array<string, mixed>>
     */
    public function sources(): array
    {
        return array_filter([self::ID_TOKEN => $this->idClaims, self::ACCESS_TOKEN => $this->accessClaims], fn (?array $claims): bool => $claims !== null);
    }

    /**
     * The claim key for a configured name: the name itself, or a claim-type
     * URI ending in it (".../claims/emailaddress" for "emailaddress").
     *
     * @param  array<string, mixed>  $claims
     */
    public static function claimKey(array $claims, string $name): ?string
    {
        if (array_key_exists($name, $claims)) {
            return $name;
        }

        if (str_contains($name, '/')) {
            return null;
        }

        foreach (array_keys($claims) as $key) {
            if (str_contains((string) $key, '/') && strcasecmp(substr((string) $key, strrpos((string) $key, '/') + 1), $name) === 0) {
                return (string) $key;
            }
        }

        return null;
    }
}
