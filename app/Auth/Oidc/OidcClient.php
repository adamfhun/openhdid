<?php

namespace App\Auth\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
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

        $url = $this->discovery($config)['authorization_endpoint'].'?'.http_build_query([
            'client_id' => $config->clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => $config->scopes,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return compact('url', 'state', 'nonce', 'verifier');
    }

    /**
     * Exchange the authorization code and return the validated ID-token claims.
     *
     * @return array<string, mixed>
     */
    public function exchangeCode(OidcProviderConfig $config, string $code, string $redirectUri, string $verifier, string $nonce): array
    {
        $tokenEndpoint = $this->discovery($config)['token_endpoint'];
        $response = $this->reach(fn (): Response => $this->http
            ->asForm()
            ->timeout(15)
            ->post($tokenEndpoint, [
                'grant_type' => 'authorization_code',
                'client_id' => $config->clientId,
                'client_secret' => $config->clientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $verifier,
            ]));

        if ($response->serverError()) {
            throw new OidcUnavailableException('Token endpoint answered HTTP '.$response->status().'.');
        }

        if ($response->failed() || ! is_string($response->json('id_token'))) {
            throw new OidcException('Token exchange failed: '.($response->json('error_description') ?? $response->status()));
        }

        $claims = $this->validateIdToken($config, $response->json('id_token'), $config->clientId);

        if (($claims['nonce'] ?? null) !== $nonce) {
            throw new OidcException('ID token nonce mismatch.');
        }

        return $claims;
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
            $claims = (array) JWT::decode($idToken, $this->keys($config, $discovery['jwks_uri']));
        } catch (Throwable $e) {
            // A rotated key may not be in the cached set yet: refresh once.
            try {
                $claims = (array) JWT::decode($idToken, $this->keys($config, $discovery['jwks_uri'], refresh: true));
            } catch (Throwable $e) {
                throw new OidcException('ID token invalid: '.$e->getMessage(), previous: $e);
            }
        }

        $audiences = (array) ($claims['aud'] ?? []);
        if (! in_array($expectedAudience, $audiences, true)) {
            throw new OidcException('ID token audience mismatch.');
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
     * The address the account is bound by. Entra's `email` claim mirrors an
     * editable directory attribute, so it counts only when the tenant marks
     * it verified (`xms_edov`); otherwise the UPN-based claims decide, whose
     * domain the issuing tenant had to prove.
     *
     * @param  array<string, mixed>  $claims
     */
    public function email(array $claims, ?OidcProviderConfig $config = null): ?string
    {
        $order = ['email', 'upn', 'preferred_username', 'unique_name'];

        if ($config?->provider === OidcProvider::Entra) {
            $verified = in_array($claims['xms_edov'] ?? null, [true, 1, '1', 'true'], true)
                || in_array($claims['email_verified'] ?? null, [true, 1, '1', 'true'], true);
            $order = $verified ? ['email', 'preferred_username', 'upn', 'unique_name'] : ['preferred_username', 'upn', 'unique_name'];
        }

        foreach ($order as $claim) {
            $value = $claims[$claim] ?? null;

            if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return mb_strtolower($value);
            }
        }

        return null;
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
     * @return array<string, Key>
     */
    private function keys(OidcProviderConfig $config, string $jwksUri, bool $refresh = false): array
    {
        $cacheKey = 'oidc.jwks.'.md5($jwksUri);

        if ($refresh) {
            $this->cache->forget($cacheKey);
        }

        $jwks = $this->cache->remember($cacheKey, self::DISCOVERY_TTL, fn (): array => $this->reach(fn (): Response => $this->http->timeout(10)->get($jwksUri)->throw())->json());

        return JWK::parseKeySet($jwks, 'RS256');
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
