<?php

namespace App\Http\Middleware;

use App\Audit\Auditor;
use App\Enums\ApiKeyScope;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Machine-to-machine auth with a long-lived key managed in the panel. The key
 * may arrive in the X-Api-Key header or as a bearer token; the call-center
 * scope also accepts an `api_key` query/body parameter, so that legacy IVR
 * platforms can integrate too (the header is preferred: a parameter may end
 * up in web-server logs).
 *
 * A managed key may carry a signing secret; when it does, every request must
 * be signed the same way the legacy static key is: X-Timestamp within the
 * configured window and X-Signature = HMAC-SHA256 over
 * "timestamp\nMETHOD\nrequest-uri\nbody" (the URI includes the query string,
 * so a captured GET signature is useless for any other call), and no
 * signature is accepted twice within the window. For the call-center scope
 * the static key from config/hdid.php is still accepted, with its optional HMAC.
 */
class AuthenticateApiKey
{
    public const REQUEST_ATTRIBUTE = 'api_key';

    /** Set (true) when the request was authenticated by the legacy static key. */
    public const LEGACY_ATTRIBUTE = 'api_key_legacy';

    public function __construct(private readonly Auditor $auditor) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $scope = ApiKeyScope::from($scope);
        $given = $this->givenKey($request, $scope);

        if ($given === '') {
            $this->reject($request, $scope, 'missing', 'Missing API key.');
        }

        $key = ApiKey::findActive($given, $scope);

        if ($key !== null) {
            if ($key->hmac_secret !== null) {
                $this->assertSigned($request, $key->hmac_secret, $scope, $key);
            }

            $key->touchLastUsed();
            $request->attributes->set(self::REQUEST_ATTRIBUTE, $key);

            return $next($request);
        }

        if ($scope === ApiKeyScope::CallCenter && $this->matchesLegacyCallCenterKey($request, $given)) {
            $request->attributes->set(self::REQUEST_ATTRIBUTE, null);
            $request->attributes->set(self::LEGACY_ATTRIBUTE, true);
            // Once a minute is enough to see that the static key is still in use.
            if (Cache::add('audit:api-key-legacy-used', 1, 60)) {
                $this->auditor->record('api_key.legacy_used', null, ['scope' => $scope->value, 'path' => $request->path()]);
            }

            return $next($request);
        }

        $this->reject($request, $scope, 'invalid', 'Invalid API key.');
    }

    /**
     * A stable identity of the credential a request presents, for rate
     * limits and audit entries: the managed key's id once authenticated, a
     * marker for the legacy static key, otherwise a digest of whatever was
     * presented (the throttle middleware may run before this one, so it
     * cannot rely on the request attribute).
     */
    public static function identityOf(Request $request): string
    {
        $key = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if ($key instanceof ApiKey) {
            return 'key:'.$key->getKey();
        }

        if ($request->attributes->get(self::LEGACY_ATTRIBUTE)) {
            return 'legacy';
        }

        $given = (new self(app(Auditor::class)))->givenKey($request, ApiKeyScope::CallCenter);

        if ($given === '') {
            return 'ip:'.$request->ip();
        }

        $legacy = (string) config('hdid.callcenter.api_key');

        return $legacy !== '' && hash_equals($legacy, $given) ? 'legacy' : 'presented:'.substr(hash('sha256', $given), 0, 24);
    }

    private function givenKey(Request $request, ApiKeyScope $scope): string
    {
        $header = (string) $request->header('X-Api-Key', '');

        if ($header !== '') {
            return $header;
        }

        $bearer = (string) $request->bearerToken();

        if ($bearer !== '') {
            return $bearer;
        }

        if ($scope !== ApiKeyScope::CallCenter) {
            return '';
        }

        return (string) ($request->input('api_key') ?? $request->query('api_key') ?? '');
    }

    private function matchesLegacyCallCenterKey(Request $request, string $given): bool
    {
        $apiKey = (string) config('hdid.callcenter.api_key');

        if ($apiKey === '' || ! hash_equals($apiKey, $given)) {
            return false;
        }

        $secret = config('hdid.callcenter.hmac_secret');

        if (is_string($secret) && $secret !== '') {
            $this->assertSigned($request, $secret, ApiKeyScope::CallCenter, null);
        }

        return true;
    }

    private function assertSigned(Request $request, string $secret, ApiKeyScope $scope, ?ApiKey $key): void
    {
        $timestamp = (int) $request->header('X-Timestamp', '0');
        $ttl = (int) config('hdid.callcenter.signature_ttl', 300);

        if (abs(time() - $timestamp) > $ttl) {
            $this->reject($request, $scope, 'stale_timestamp', 'Stale or missing timestamp.', $key);
        }

        $given = (string) $request->header('X-Signature', '');
        $expected = hash_hmac('sha256', self::signedPayload($timestamp, $request->getMethod(), $request->getRequestUri(), $request->getContent()), $secret);

        if (! hash_equals($expected, $given)) {
            $this->reject($request, $scope, 'bad_signature', 'Invalid signature.', $key);
        }

        // A signature is good for exactly one request within the window.
        if (! Cache::add('api-sig:'.hash('sha256', $given), 1, 2 * $ttl)) {
            $this->reject($request, $scope, 'replayed', 'Replayed request.', $key);
        }
    }

    /**
     * The string a partner signs: timestamp, HTTP method, request URI with its
     * query string, and the raw body, joined by newlines.
     */
    public static function signedPayload(int|string $timestamp, string $method, string $requestUri, string $body): string
    {
        return $timestamp."\n".strtoupper($method)."\n".$requestUri."\n".$body;
    }

    private function reject(Request $request, ApiKeyScope $scope, string $reason, string $message, ?ApiKey $key = null): never
    {
        $this->auditor->record('api_key.rejected', $key, ['scope' => $scope->value, 'reason' => $reason, 'path' => $request->path()]);

        throw new HttpException(401, $message);
    }
}
