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

    private static ?string $privateKey = null;

    private static ?array $jwk = null;

    /** Nonce the fake token endpoint will sign into the ID token. */
    public static string $nonce = 'FROM_SESSION';

    /** @var array<string, mixed> Claims the fake token endpoint will sign. */
    public static array $claims = [];

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function fake(array $claims = [], string $issuer = self::ISSUER, string $discoveryUrl = self::DISCOVERY): void
    {
        self::ensureKeys();
        self::$claims = $claims;

        Http::fake([
            $discoveryUrl => Http::response([
                'issuer' => $issuer,
                'authorization_endpoint' => 'https://idp.test/authorize',
                'token_endpoint' => 'https://idp.test/token',
                'jwks_uri' => 'https://idp.test/jwks',
            ]),
            'https://idp.test/jwks' => Http::response(['keys' => [self::$jwk]]),
            'https://idp.test/token' => fn ($request) => Http::response([
                'id_token' => self::idToken(self::$claims + ['nonce' => self::$nonce], $issuer),
                'access_token' => 'x',
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function idToken(array $claims, string $issuer = self::ISSUER): string
    {
        self::ensureKeys();

        return JWT::encode($claims + [
            'iss' => $issuer,
            'iat' => time(),
            'exp' => time() + 300,
        ], self::$privateKey, 'RS256', 'test-kid');
    }

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
