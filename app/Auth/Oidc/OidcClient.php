<?php

namespace App\Auth\Oidc;

use App\System\Installation;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache as CacheFacade;
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
    /** A discovery document or key set younger than this is used without asking the provider. */
    private const FRESH_SECONDS = 3600;

    /**
     * The last good copy is kept this long: while the provider cannot be
     * reached, sign-ins go on with it (a key the provider revoked meanwhile
     * may stay accepted that long; the status page says when this happens).
     */
    public const KEEP_SECONDS = 86400;

    /** After a failed download, the provider is not asked again for this long. */
    private const FAILURE_BACKOFF_SECONDS = 30;

    /** Tolerated clock difference to the provider for exp, nbf and iat. */
    public const CLOCK_SKEW_SECONDS = 60;

    /**
     * Longest ID or access token accepted (the input standard's limit for
     * tokens). Checked before any splitting or decoding, so an oversized
     * token costs neither memory nor a key-set request.
     */
    public const MAX_TOKEN_LENGTH = 16384;

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

        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
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

        // The signature and audience prove the token came from Microsoft for
        // our app; only the tenant claim proves it came from one of OUR
        // directories, so it is checked before the (tenant-templated) issuer.
        $tenantId = null;
        if ($config->provider === OidcProvider::Entra) {
            $tenantId = $claims['tid'] ?? null;

            if (! is_string($tenantId) || ! $config->allowsTenant($tenantId)) {
                throw new OidcException('ID token tenant not allowed.');
            }
        }

        if (! $this->issuerMatches($discovery['issuer'], (string) ($claims['iss'] ?? ''), $config, $tenantId)) {
            throw new OidcException('ID token issuer mismatch.');
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
     * Every field of the token response, each JWT as received and decoded
     * (header and claims, unverified, so a token that is about to be refused
     * shows too). The signatures stay out, so the log never holds a token
     * that could be presented again, and the refresh token is only reported
     * as present.
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
                $fields[$name] = self::unsignedToken($fields[$name]);
            }
        }

        if (array_key_exists('refresh_token', $fields)) {
            $fields['refresh_token'] = '(present, not logged)';
        }

        $this->debug($config, 'token response', ['token_endpoint' => $endpoint, 'http_status' => $response->status()] + $fields);
    }

    /**
     * A JWT for the debug log: the header and payload segments exactly as
     * received, the signature only by its length, and both segments decoded.
     *
     * @return array{received: string, signature: string, header: mixed, claims: mixed}|string
     */
    private static function unsignedToken(string $token): array|string
    {
        $parts = explode('.', $token, 4);

        if (count($parts) !== 3) {
            return '(not a JWT, '.strlen($token).' characters)';
        }

        $decode = fn (string $part): mixed => json_decode((string) base64_decode(strtr($part, '-_', '+/'), true), true);

        return [
            'received' => $parts[0].'.'.$parts[1],
            'signature' => '('.strlen($parts[2]).' characters, not logged)',
            'header' => $decode($parts[0]),
            'claims' => $decode($parts[1]),
        ];
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
        if (strlen($token) > self::MAX_TOKEN_LENGTH) {
            throw new OidcException('Token is longer than '.self::MAX_TOKEN_LENGTH.' bytes.');
        }

        $jwks = $this->jwks($jwksUri);
        JWT::$leeway = self::CLOCK_SKEW_SECONDS;

        try {
            return (array) JWT::decode($token, JWK::parseKeySet($jwks, 'RS256'));
        } catch (Throwable $first) {
            // A rotated key may not be in the cached set yet: refresh once, but only
            // for a key id the cached set does not have, at most once a minute, and
            // not within 30 seconds of a failed download, so a stream of invalid
            // tokens does not turn into a stream of JWKS requests.
            if ($this->cache->has($this->failureKey('jwks', $jwksUri)) || ! $this->mayRefreshFor($jwksUri, $jwks, $token)) {
                throw new OidcException($first->getMessage(), previous: $first);
            }

            $jwks = $this->jwks($jwksUri, refresh: true);

            try {
                return (array) JWT::decode($token, JWK::parseKeySet($jwks, 'RS256'));
            } catch (Throwable $e) {
                throw new OidcException($e->getMessage(), previous: $e);
            }
        }
    }

    /**
     * Whether a failed decode is worth a fresh key set: the token names a key
     * id (kid) the cached set lacks, and no refresh ran in the last minute.
     *
     * @param  array<string, mixed>  $jwks
     */
    private function mayRefreshFor(string $jwksUri, array $jwks, string $token): bool
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            return false;
        }

        $header = json_decode((string) base64_decode(strtr($segments[0], '-_', '+/'), true), true);
        $kid = is_array($header) && is_string($header['kid'] ?? null) ? $header['kid'] : null;
        $known = array_filter(array_map(fn (mixed $key): mixed => is_array($key) ? ($key['kid'] ?? null) : null, (array) ($jwks['keys'] ?? [])), 'is_string');

        // Only a token that could be signed with a published key is worth a
        // download: RS256, under a key id the cached set lacks.
        if ($kid === null || ($header['alg'] ?? null) !== 'RS256' || in_array($kid, $known, true)) {
            return false;
        }

        return $this->cache->add(Installation::key('oidc.jwks.refresh.'.md5($jwksUri)), true, 60);
    }

    /**
     * What the status page tells about a provider's key set, read from the
     * cache only (no request): when the last good copy was fetched, and
     * whether a download failed in the last moments. Null before any
     * sign-in fetched one.
     *
     * @return array{fetched_at: int, stale: bool, failed: bool}|null
     */
    public function keySetState(OidcProviderConfig $config): ?array
    {
        $discovery = $this->cache->get($this->envelopeKey('discovery', $config->discoveryUrl));
        $uri = is_array($discovery) ? ($discovery['document']['jwks_uri'] ?? null) : null;
        $envelope = is_string($uri) ? $this->cache->get($this->envelopeKey('jwks', $uri)) : null;

        if (! is_array($envelope)) {
            return null;
        }

        return [
            'fetched_at' => (int) $envelope['fetched_at'],
            'stale' => now()->getTimestamp() - (int) $envelope['fetched_at'] >= self::FRESH_SECONDS,
            'failed' => $this->cache->has($this->failureKey('jwks', $uri)) || $this->cache->has($this->failureKey('discovery', $config->discoveryUrl)),
        ];
    }

    /**
     * @return array{authorization_endpoint: string, token_endpoint: string, jwks_uri: string, issuer: string}
     */
    public function discovery(OidcProviderConfig $config): array
    {
        /** @var array{authorization_endpoint: string, token_endpoint: string, jwks_uri: string, issuer: string} $document */
        $document = $this->lastGood('discovery', $config->discoveryUrl, false, function () use ($config): array {
            $document = (array) $this->reach(fn (): Response => $this->http->timeout(10)->retry(2, 200)->get($config->discoveryUrl)->throw())->json();

            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'issuer'] as $key) {
                if (! is_string($document[$key] ?? null)) {
                    throw new OidcException("Discovery document is missing {$key}.");
                }
            }

            return $document;
        }, fn (array $document): bool => true);

        return $document;
    }

    /**
     * The provider's JWKS document. A downloaded document that holds no
     * usable RS256 key is handed to the caller (the token then fails as
     * invalid) but never replaces a good one.
     *
     * @return array<string, mixed>
     */
    private function jwks(string $jwksUri, bool $refresh = false): array
    {
        return $this->lastGood('jwks', $jwksUri, $refresh, fn (): array => (array) $this->reach(fn (): Response => $this->http->timeout(10)->get($jwksUri)->throw())->json(), function (array $document): bool {
            if (! is_array($document['keys'] ?? null) || $document['keys'] === []) {
                return false;
            }

            try {
                JWK::parseKeySet($document, 'RS256');

                return true;
            } catch (Throwable) {
                return false;
            }
        });
    }

    /**
     * A provider document with its last good copy (discovery, key set):
     * fresh for an hour, kept for a day. A stale copy is refreshed by one
     * request (a lock nobody waits for); the others, and every request while
     * the provider is down, go on with the copy. Without a copy (or when a
     * refresh is forced for an unknown key id) the download runs directly
     * and its failure is an outage. A failed or unusable download is not
     * repeated for 30 seconds, and never replaces the copy.
     *
     * @param  callable(): array<string, mixed>  $download
     * @param  callable(array<string, mixed>): bool  $usable
     * @return array<string, mixed>
     */
    private function lastGood(string $kind, string $uri, bool $force, callable $download, callable $usable): array
    {
        $envelope = $this->cache->get($this->envelopeKey($kind, $uri));
        $copy = is_array($envelope) ? $envelope : null;

        if ($copy !== null && ! $force && now()->getTimestamp() - (int) $copy['fetched_at'] < self::FRESH_SECONDS) {
            return $copy['document'];
        }

        if ($copy !== null && ! $force) {
            if ($this->cache->has($this->failureKey($kind, $uri))) {
                return $copy['document'];
            }

            $lock = CacheFacade::lock(Installation::lockName('oidc-'.$kind.':'.md5($uri)), 30);

            if (! $lock->get()) {
                return $copy['document'];
            }

            try {
                return $this->download($kind, $uri, $download, $usable, $copy);
            } finally {
                $lock->release();
            }
        }

        if ($this->cache->has($this->failureKey($kind, $uri))) {
            throw new OidcUnavailableException('Identity provider unreachable (retried shortly).');
        }

        return $this->download($kind, $uri, $download, $usable, $force ? null : $copy);
    }

    /**
     * @param  callable(): array<string, mixed>  $download
     * @param  callable(array<string, mixed>): bool  $usable
     * @param  array{document: array<string, mixed>, fetched_at: int}|null  $fallback  served when the download fails
     * @return array<string, mixed>
     */
    private function download(string $kind, string $uri, callable $download, callable $usable, ?array $fallback): array
    {
        try {
            $document = $download();
        } catch (OidcUnavailableException|OidcException $e) {
            $this->noteFailure($kind, $uri, $fallback);

            if ($fallback !== null) {
                return $fallback['document'];
            }

            throw $e;
        }

        if (! $usable($document)) {
            $this->noteFailure($kind, $uri, $fallback);

            return $fallback['document'] ?? $document;
        }

        $this->cache->put($this->envelopeKey($kind, $uri), ['document' => $document, 'fetched_at' => now()->getTimestamp()], self::KEEP_SECONDS);

        return $document;
    }

    /**
     * Marks the failure for the back-off; the first one of a window is
     * logged when a last good copy is served instead.
     *
     * @param  array{document: array<string, mixed>, fetched_at: int}|null  $fallback
     */
    private function noteFailure(string $kind, string $uri, ?array $fallback): void
    {
        if ($this->cache->add($this->failureKey($kind, $uri), now()->getTimestamp(), self::FAILURE_BACKOFF_SECONDS) && $fallback !== null) {
            Log::warning('OIDC '.$kind.' refresh failed; using the last good copy', ['uri' => $uri, 'fetched_at' => date(DATE_ATOM, (int) $fallback['fetched_at'])]);
        }
    }

    private function envelopeKey(string $kind, string $uri): string
    {
        return Installation::key('oidc.'.$kind.'.v2.'.md5($uri));
    }

    private function failureKey(string $kind, string $uri): string
    {
        return Installation::key('oidc.'.$kind.'.failed.'.md5($uri));
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

    private function issuerMatches(string $expected, string $actual, OidcProviderConfig $config, ?string $tenantId = null): bool
    {
        if ($actual === $expected) {
            return true;
        }

        // A discovery document that templates the tenant id into the issuer
        // (the shared endpoints do) is only accepted for the token's own,
        // already allowed tenant, never as a wildcard.
        if ($config->provider === OidcProvider::Entra && str_contains($expected, '{tenantid}') && $tenantId !== null) {
            return strcasecmp(str_replace('{tenantid}', $tenantId, $expected), $actual) === 0;
        }

        return false;
    }
}
