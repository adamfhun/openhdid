<?php

namespace Tests\Support;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

/**
 * Fakes an OpenID Connect provider: discovery, JWKS and the token endpoint.
 */
class FakeOidc
{
    public const ISSUER = 'https://idp.test/adfs';

    public const DISCOVERY = self::ISSUER.'/.well-known/openid-configuration';

    /** ADFS signs access tokens as its federation service, not as the OIDC issuer. */
    public const ACCESS_TOKEN_ISSUER = 'http://idp.test/adfs/services/trust';

    private static ?string $privateKey = null;

    private static ?array $jwk = null;

    /** Nonce the fake token endpoint will sign into the ID token. */
    public static string $nonce = 'FROM_SESSION';

    /** @var array<string, mixed> Claims the fake token endpoint will sign. */
    public static array $claims = [];

    /**
     * @var array<string, mixed>|null Claims of a signed access token; null
     *                                answers with an opaque one.
     */
    public static ?array $accessClaims = null;

    /** @var array<string, mixed> Further fields of the token response. */
    public static array $tokenResponse = [];

    /**
     * @param  array<string, mixed>  $claims
     */
    /** Issuer the fake token endpoint signs into the ID token. */
    public static string $issuer = self::ISSUER;

    /** Issuer the fake discovery document publishes (Entra's shared endpoints template the tenant id). */
    public static ?string $discoveryIssuer = null;

    /**
     * The stubs read the statics at request time: Http::fake keeps the
     * first stub registered for a URL, so a test that signs in several
     * times (even from several tenants) still gets the latest claims and
     * issuer.
     */
    public static function fake(array $claims = [], string $issuer = self::ISSUER, string $discoveryUrl = self::DISCOVERY, ?string $discoveryIssuer = null): void
    {
        self::ensureKeys();
        self::$claims = $claims;
        self::$issuer = $issuer;
        self::$discoveryIssuer = $discoveryIssuer;

        Http::fake([
            $discoveryUrl => fn ($request) => Http::response([
                'issuer' => self::$discoveryIssuer ?? self::$issuer,
                'access_token_issuer' => self::ACCESS_TOKEN_ISSUER,
                'authorization_endpoint' => 'https://idp.test/authorize',
                'token_endpoint' => 'https://idp.test/token',
                'jwks_uri' => 'https://idp.test/jwks',
            ]),
            'https://idp.test/jwks' => Http::response(['keys' => [self::$jwk]]),
            'https://idp.test/token' => fn ($request) => Http::response(self::$tokenResponse + [
                'id_token' => self::idToken(self::$claims + ['nonce' => self::$nonce], self::$issuer),
                'access_token' => self::$accessClaims === null ? 'x' : self::idToken(self::$accessClaims, self::ACCESS_TOKEN_ISSUER, withSubject: false),
            ]),
        ]);
    }

    /**
     * Back to an opaque access token and a bare token response.
     */
    public static function reset(): void
    {
        self::$accessClaims = null;
        self::$tokenResponse = [];
        self::$withoutSubject = false;
        self::$issuer = self::ISSUER;
        self::$discoveryIssuer = null;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function idToken(array $claims, string $issuer = self::ISSUER, bool $withSubject = true): string
    {
        self::ensureKeys();

        return JWT::encode($claims + ($withSubject ? self::defaultSubject($claims) : []) + [
            'iss' => $issuer,
            'iat' => time(),
            'exp' => time() + 300,
        ], self::$privateKey, 'RS256', 'test-kid');
    }

    /**
     * A stable subject per person, derived from the address-like claim, so
     * that the binding check (SsoSubjectBinding) sees the same identity on
     * every sign-in of the same person; a test about the subject itself
     * passes `oid` or `sub` explicitly. `FakeOidc::$withoutSubject` leaves
     * both out.
     *
     * @param  array<string, mixed>  $claims
     * @return array<string, string>
     */
    public static function defaultSubject(array $claims): array
    {
        if (self::$withoutSubject) {
            return [];
        }

        $person = '';
        foreach (['email', 'preferred_username', 'upn', 'emailaddress', 'unique_name'] as $name) {
            $value = $claims[$name] ?? null;
            if (is_string($value) && $value !== '') {
                $person = mb_strtolower($value);
                break;
            }
        }

        $hash = substr(hash('sha256', $person), 0, 16);

        return ['sub' => 'sub-'.$hash, 'oid' => 'oid-'.$hash];
    }

    /** Sign tokens without any subject claim (oid, sub). */
    public static bool $withoutSubject = false;

    private static function ensureKeys(): void
    {
        if (self::$privateKey !== null) {
            return;
        }

        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $private);
        $details = openssl_pkey_get_details($resource);

        self::$privateKey = $private;
        self::$jwk = [
            'kty' => 'RSA',
            'kid' => 'test-kid',
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
        ];
    }
}
