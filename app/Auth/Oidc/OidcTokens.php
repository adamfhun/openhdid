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
}
