<?php

namespace App\Auth\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Minimal OpenID Connect relying party: discovery, authorization-code flow
 * with PKCE, and ID-token validation against the provider's JWKS. Works for
 * ADFS 2016+ and Microsoft Entra ID alike.
 */
class OidcClient
{
    private const DISCOVERY_TTL = 3600;

    /** Tolerated clock difference to the provider for exp, nbf and iat. */
    public const CLOCK_SKEW_SECONDS = 60;

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
    ) {}

    /**
     * @return array{url: string, state: string, nonce: string, verifier: string}
     */
    public function authorizationRequest(OidcProviderConfig $config, string $redirectUri): array
    {
        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $endpoint = $this->discovery($config)['authorization_endpoint'];
        $url = $endpoint.'?'.http_build_query(array_filter([
            'client_id' => $config->clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => $config->scopes,
            'resource' => $config->resource,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], fn (?string $value): bool => $value !== null));

        $this->debug($config, 'authorization request', [
            'authorization_endpoint' => $endpoint,
            'client_id' => $config->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $config->scopes,
            'resource' => $config->resource,
            'email_claims' => $config->emailClaims,
        ]);

        return compact('url', 'state', 'nonce', 'verifier');
    }

    /**
     * Exchange the authorization code and return the validated claims: the
     * ID token's, and for ADFS the access token's when it validates too.
     */
    public function exchangeCode(OidcProviderConfig $config, string $code, string $redirectUri, string $verifier, string $nonce): OidcTokens
    {
        $discovery = $this->discovery($config);
        $response = $this->reach(fn (): Response => $this->http
            ->asForm()
            ->timeout(15)
            ->post($discovery['token_endpoint'], array_filter([
                'grant_type' => 'authorization_code',
                'client_id' => $config->clientId,
                'client_secret' => $config->clientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $verifier,
                'resource' => $config->resource,
            ], fn (?string $value): bool => $value !== null)));

        $this->debugTokenResponse($config, $discovery['token_endpoint'], $response);

        if ($response->serverError()) {
            throw new OidcUnavailableException('Token endpoint answered HTTP '.$response->status().'.');
        }

        if ($response->failed() || ! is_string($response->json('id_token'))) {
            throw new OidcException('Token exchange failed: '.($response->json('error_description') ?? $response->json('error') ?? $response->status()));
        }

        $claims = $this->validateIdToken($config, $response->json('id_token'), $config->clientId);

        if (($claims['nonce'] ?? null) !== $nonce) {
            throw new OidcException('ID token nonce mismatch.');
        }

        if ($config->provider !== OidcProvider::Adfs) {
            return new OidcTokens($claims);
        }

        return $this->withAccessToken($config, $discovery, $claims, $response->json('access_token'));
    }

    /**
     * ADFS puts the claims of the application group's issuance rules into the
     * access token; the ID token gets them only for clients allowed the
     * "allatclaims" scope. The access token came in the same TLS response as
     * the nonce-bound ID token, and its claims count only when it is signed
     * by the same keys, issued by this ADFS, unexpired and issued to this
     * client; otherwise the reason is reported and the ID token stands alone.
     *
     * @param  array{jwks_uri: string, issuer: string, access_token_issuer?: string}  $discovery
     * @param  array<string, mixed>  $idClaims
     */
    private function withAccessToken(OidcProviderConfig $config, array $discovery, array $idClaims, mixed $accessToken): OidcTokens
    {
        if (! is_string($accessToken) || $accessToken === '') {
            return new OidcTokens($idClaims, accessStatus: OidcTokens::ACCESS_ABSENT);
        }

        if (substr_count($accessToken, '.') !== 2) {
            return new OidcTokens($idClaims, accessStatus: OidcTokens::ACCESS_NOT_A_JWT);
        }

        // The ID token already proved the sign-in: whatever goes wrong with
        // the access token only leaves its claims out.
        try {
            $claims = $this->decodeSigned($discovery['jwks_uri'], $accessToken);
        } catch (OidcException $e) {
            return new OidcTokens($idClaims, accessStatus: 'ignored: '.$e->getMessage());
        }

        $issuers = array_filter([$discovery['access_token_issuer'] ?? null, $discovery['issuer']], 'is_string');
        $client = $claims['appid'] ?? $claims['client_id'] ?? $claims['azp'] ?? null;

        $problem = match (true) {
            ! in_array((string) ($claims['iss'] ?? ''), $issuers, true) => 'issuer mismatch',
            $client !== $config->clientId => 'issued to another client',
            default => null,
        };

        return $problem === null
            ? new OidcTokens($idClaims, $claims, OidcTokens::ACCESS_READ)
            : new OidcTokens($idClaims, accessStatus: 'ignored: '.$problem);
    }

    /**
     * Validate an ID token's signature, issuer, audience and expiry.
     *
     * @return array<string, mixed>
     */
    public function validateIdToken(OidcProviderConfig $config, string $idToken, string $expectedAudience): array
    {
        if (! $config->isConfigured()) {
            throw new OidcException('Provider is not configured.');
        }

        $discovery = $this->discovery($config);

        try {
            $claims = $this->decodeSigned($discovery['jwks_uri'], $idToken);
        } catch (OidcUnavailableException $e) {
            throw $e;
        } catch (OidcException $e) {
            throw new OidcException('ID token invalid: '.$e->getMessage(), previous: $e);
        }

        $audiences = (array) ($claims['aud'] ?? []);
        if (! in_array($expectedAudience, $audiences, true)) {
            throw new OidcException('ID token audience mismatch.');
        }

        // OIDC Core 3.1.3.7: with several audiences the token must name us as
        // the authorized party, and a present azp must be us in any case.
        $authorizedParty = $claims['azp'] ?? null;
        if ((count($audiences) > 1 || $authorizedParty !== null) && $authorizedParty !== $expectedAudience) {
            throw new OidcException('ID token authorized party mismatch.');
        }

        if (! $this->issuerMatches($discovery['issuer'], (string) ($claims['iss'] ?? ''), $config)) {
            throw new OidcException('ID token issuer mismatch.');
        }

        // The signature and audience prove the token came from Microsoft for
        // our app; only the tenant claim proves it came from OUR directory.
        if ($config->provider === OidcProvider::Entra) {
            $tenantId = $claims['tid'] ?? null;

            if (! is_string($tenantId) || strcasecmp($tenantId, (string) $config->tenant) !== 0) {
                throw new OidcException('ID token tenant mismatch.');
            }
        }

        return $claims;
    }

    /**
     * The address a token binds to, for callers holding only ID-token claims
     * (the mobile token exchange); see EmailClaimMatch for the rules.
     *
     * @param  array<string, mixed>  $claims
     */
    public function email(array $claims, OidcProviderConfig $config): ?string
    {
        return EmailClaimMatch::resolve(new OidcTokens($claims), $config)->email;
    }

    /**
     * Write a step of an ADFS sign-in to the application log when the
     * operator switched HDID_ADFS_DEBUG on. Warning level, so it shows at
     * the production log level as well.
     *
     * @param  array<string, mixed>  $context
     */
    public function debug(OidcProviderConfig $config, string $step, array $context): void
    {
        if (! $config->logsTokens()) {
            return;
        }

        Log::warning('ADFS debug: '.$step, ['principal' => $config->principal->value] + $context);
    }

    /**
     * Every field of the token response with the tokens decoded (header and
     * claims, unverified, so a token that is about to be refused shows too).
     * The raw tokens are credentials and stay out: the signature carries no
     * information, and the refresh token is only reported as present.
     */
    private function debugTokenResponse(OidcProviderConfig $config, string $endpoint, Response $response): void
    {
        if (! $config->logsTokens()) {
            return;
        }

        $body = $response->json();
        $fields = is_array($body) ? $body : ['body' => mb_substr($response->body(), 0, 2000)];

        foreach (['id_token', 'access_token'] as $name) {
            if (is_string($fields[$name] ?? null)) {
                $fields[$name] = self::decodeUnverified($fields[$name]);
            }
        }

        if (array_key_exists('refresh_token', $fields)) {
            $fields['refresh_token'] = '(present, not logged)';
        }

        $this->debug($config, 'token response', ['token_endpoint' => $endpoint, 'http_status' => $response->status()] + $fields);
    }

    /**
     * @return array{header: mixed, claims: mixed}|string
     */
    private static function decodeUnverified(string $token): array|string
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return '(not a JWT, '.strlen($token).' characters)';
        }

        $decode = fn (string $part): mixed => json_decode((string) base64_decode(strtr($part, '-_', '+/'), true), true);

        return ['header' => $decode($parts[0]), 'claims' => $decode($parts[1])];
    }

    /**
     * Verify a JWT against the provider's key set and return its claims.
     * The key set is fetched outside the guarded decode so a JWKS outage
     * stays an OidcUnavailableException instead of an invalid token; an
     * unusable document still fails inside it, like a stale key.
     *
     * @return array<string, mixed>
     */
    private function decodeSigned(string $jwksUri, string $token): array
    {
        $jwks = $this->jwks($jwksUri);
        JWT::$leeway = self::CLOCK_SKEW_SECONDS;

        try {
            return (array) JWT::decode($token, JWK::parseKeySet($jwks, 'RS256'));
        } catch (Throwable) {
            // A rotated key may not be in the cached set yet: refresh once.
            $jwks = $this->jwks($jwksUri, refresh: true);

            try {
                return (array) JWT::decode($token, JWK::parseKeySet($jwks, 'RS256'));
            } catch (Throwable $e) {
                throw new OidcException($e->getMessage(), previous: $e);
            }
        }
    }

    /**
     * @return array{authorization_endpoint: string, token_endpoint: string, jwks_uri: string, issuer: string}
     */
    public function discovery(OidcProviderConfig $config): array
    {
        return $this->cache->remember('oidc.discovery.'.md5($config->discoveryUrl), self::DISCOVERY_TTL, function () use ($config): array {
            $document = $this->reach(fn (): Response => $this->http->timeout(10)->retry(2, 200)->get($config->discoveryUrl)->throw())->json();

            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'issuer'] as $key) {
                if (! is_string($document[$key] ?? null)) {
                    throw new OidcException("Discovery document is missing {$key}.");
                }
            }

            return $document;
        });
    }

    /**
     * The provider's raw JWKS document. Only reaching the endpoint can fail
     * here; whether the document holds a usable key is the caller's concern.
     *
     * @return array<string, mixed>
     */
    private function jwks(string $jwksUri, bool $refresh = false): array
    {
        $cacheKey = 'oidc.jwks.'.md5($jwksUri);

        if ($refresh) {
            $this->cache->forget($cacheKey);
        }

        return $this->cache->remember($cacheKey, self::DISCOVERY_TTL, fn (): array => (array) $this->reach(fn (): Response => $this->http->timeout(10)->get($jwksUri)->throw())->json());
    }

    /**
     * Network failures and HTTP errors of the provider's own endpoints become
     * an outage, so the callers can tell it apart from a rejected login.
     *
     * @param  callable(): Response  $request
     */
    private function reach(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new OidcUnavailableException('Identity provider unreachable.', previous: $e);
        } catch (RequestException $e) {
            throw new OidcUnavailableException('Identity provider answered HTTP '.$e->response->status().'.', previous: $e);
        }
    }

    private function issuerMatches(string $expected, string $actual, OidcProviderConfig $config): bool
    {
        if ($actual === $expected) {
            return true;
        }

        // A discovery document that templates the tenant id into the issuer is
        // only accepted for the configured tenant, never as a wildcard.
        if ($config->provider === OidcProvider::Entra && str_contains($expected, '{tenantid}')) {
            return strcasecmp(str_replace('{tenantid}', (string) $config->tenant, $expected), $actual) === 0;
        }

        return false;
    }
}
