<?php

namespace App\Support;

use App\Enums\ApiKeyScope;
use App\Http\Middleware\AuthenticateApiKey;
use Closure;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Path;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Documents the request signing of machine API keys in the OpenAPI output:
 * the scheme in the document description and the two headers on every
 * machine operation, required where the key's scope always signs (mobile
 * backend) and conditional elsewhere (call center). The wording mirrors what
 * AuthenticateApiKey enforces, so a partner can implement it from the
 * generated docs alone; the test vector is checked by the test suite.
 */
final class ApiSignatureDocs
{
    /** Path prefixes (relative to the document root) of the machine operations and their key scope. */
    private const MACHINE_PREFIXES = ['v1/callcenter/' => ApiKeyScope::CallCenter, 'v1/mobile/' => ApiKeyScope::MobileBackend];

    private const HEADING = '## Request signing';

    /** The documented test vector: a partner implementation must produce TEST_SIGNATURE from these. */
    public const TEST_SECRET = 'test-secret-do-not-use';

    public const TEST_TIMESTAMP = '1789714410';

    public const TEST_METHOD = 'POST';

    public const TEST_URI = '/api/v1/mobile/ivr-code';

    public const TEST_BODY = '{"email":"client@example.org"}';

    public const TEST_SIGNATURE = '39567683b5ed04b56f1e37f182abd62322d5e9eb1b2d78ac2900b94cb7fa7c06';

    public static function description(): string
    {
        $text = <<<'MD'
## Request signing

**Mobile app backend: always signed.** A key of the *Mobile app backend* scope comes with its signing secret: both are shown once, when the key is created (admin panel, *System › API keys › New API key*, or `php artisan hdid:api-key:create <name> mobile_backend`). Every request made with such a key must be signed; an unsigned one is refused with `401`. A mobile backend key created before signing became mandatory has no secret and is refused until an administrator issues one (*Signing secret* action on the key).

**Call center / IVR: once a secret is issued.** A call-center key needs no signature until an administrator issues a signing secret for it (*Signing secret* action). From then on every request made with that key must be signed, like a mobile backend key.

The key and the secret belong to the partner's **server**. Never ship them in a mobile app or a web page: whoever holds both can act as the partner.

### Headers

Send two headers in addition to the API key (`X-Api-Key` header or bearer token):

| Header | Value |
|---|---|
| `X-Timestamp` | Unix time in whole seconds; must be within {ttl} seconds of the server clock |
| `X-Signature` | Lower-case hex `HMAC-SHA256(payload, secret)` |

### Payload

The payload is four lines joined with a single line feed (`\n`, byte 0x0A), with no trailing line feed:

```
<timestamp, exactly as sent in X-Timestamp>
<HTTP method, upper case>
<request URI: path and query string exactly as sent, e.g. /api/v1/callcenter/ivr/verify-code?code=12345678&call_id=abc>
<raw request body exactly as sent; empty for GET>
```

- Sign the bytes you send. Serialise the JSON body once, sign that string and send the same string: a library that re-serialises it (other key order, spaces, escaping) breaks the signature.
- The URI starts with `/api/v1/...` (no scheme or host) and keeps the query string's parameter order and percent-encoding as sent. Because the URI is signed, a captured signature is useless for any other call.
- The secret is used as UTF-8 bytes, as given.
- Keep the server clock synchronised (NTP): a timestamp more than {ttl} seconds off is refused.
- Each signature is accepted once within the time window. A retry needs a fresh timestamp and a fresh signature.

### Test vector

Check an implementation with these values before going live:

| | |
|---|---|
| secret | `{test_secret}` |
| timestamp | `{test_timestamp}` |
| method | `{test_method}` |
| URI | `{test_uri}` |
| body | `{test_body}` |
| signature | `{test_signature}` |

### Rejections

Every rejection returns `401` with a JSON `message`; the reason is recorded in the audit log.

| Reason | Message | Cause |
|---|---|---|
| `missing` | Missing API key. | no key in the request |
| `invalid` | Invalid API key. | unknown, revoked or other-scope key |
| `signature_required` | Signature required: this key has no signing secret yet. | a mobile backend key without a secret; an administrator has to issue one |
| `stale_timestamp` | Stale or missing timestamp. | `X-Timestamp` missing or more than {ttl} s off |
| `bad_signature` | Invalid signature. | the signature does not match the payload |
| `replayed` | Replayed request. | the same signature was already used |

### Changing the secret

Issuing a new secret for a key takes effect at once, so the partner has to switch in the same moment. To change without an interruption, create a second key (for the mobile backend it comes with its own secret), switch the partner's server to the new key and secret, then revoke the old key.

### Examples

Shell (`openssl`):

```sh
TS=$(date +%s); URI='/api/v1/mobile/ivr-code'; BODY='{"email":"client@example.org"}'
SIG=$(printf '%s\nPOST\n%s\n%s' "$TS" "$URI" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}')
curl -H "X-Api-Key: $KEY" -H "X-Timestamp: $TS" -H "X-Signature: $SIG" -H 'Content-Type: application/json' \
     -X POST "https://hdid.example.org$URI" --data-binary "$BODY"
```

Node.js:

```js
const crypto = require('crypto');

function sign(secret, timestamp, method, uri, body) {
  const payload = `${timestamp}\n${method.toUpperCase()}\n${uri}\n${body}`;
  return crypto.createHmac('sha256', secret).update(payload, 'utf8').digest('hex');
}

const uri = '/api/v1/mobile/ivr-code';
const body = JSON.stringify({ email: 'client@example.org' });
const timestamp = Math.floor(Date.now() / 1000).toString();
const response = await fetch('https://hdid.example.org' + uri, {
  method: 'POST',
  headers: {
    'X-Api-Key': key, 'X-Timestamp': timestamp, 'X-Signature': sign(secret, timestamp, 'POST', uri, body),
    'Content-Type': 'application/json', 'Accept': 'application/json',
  },
  body,
});
```

PHP:

```php
$payload = $timestamp."\n".strtoupper($method)."\n".$uri."\n".$body;
$signature = hash_hmac('sha256', $payload, $secret);
```

Python:

```python
import hashlib, hmac

payload = f"{timestamp}\n{method.upper()}\n{uri}\n{body}"
signature = hmac.new(secret.encode(), payload.encode(), hashlib.sha256).hexdigest()
```

Java (17+):

```java
Mac mac = Mac.getInstance("HmacSHA256");
mac.init(new SecretKeySpec(secret.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
String payload = timestamp + "\n" + method.toUpperCase() + "\n" + uri + "\n" + body;
String signature = HexFormat.of().formatHex(mac.doFinal(payload.getBytes(StandardCharsets.UTF_8)));
```

C# (.NET 5+):

```csharp
using var hmac = new HMACSHA256(Encoding.UTF8.GetBytes(secret));
var payload = $"{timestamp}\n{method.ToUpperInvariant()}\n{uri}\n{body}";
var signature = Convert.ToHexString(hmac.ComputeHash(Encoding.UTF8.GetBytes(payload))).ToLowerInvariant();
```
MD;

        return strtr($text, [
            '{ttl}' => (string) (int) config('hdid.callcenter.signature_ttl', 300),
            '{test_secret}' => self::TEST_SECRET,
            '{test_timestamp}' => self::TEST_TIMESTAMP,
            '{test_method}' => self::TEST_METHOD,
            '{test_uri}' => self::TEST_URI,
            '{test_body}' => self::TEST_BODY,
            '{test_signature}' => self::TEST_SIGNATURE,
        ]);
    }

    /**
     * Document transformer: appends the scheme to the description and adds
     * the two headers to every machine operation. A partner document (IVR,
     * mobile backend) passes its scope: its paths are relative and every
     * operation belongs to that scope. In the full API document the scope
     * comes from the path prefix, and other operations get no headers.
     *
     * @return Closure(OpenApi): void
     */
    public static function transformer(?ApiKeyScope $documentScope = null): Closure
    {
        return function (OpenApi $openApi) use ($documentScope): void {
            // A partner document runs the default transformers as well, so
            // both the description and the headers are added only once.
            if (! str_contains($openApi->info->description, self::HEADING)) {
                $openApi->info->setDescription(trim($openApi->info->description."\n\n".self::description()));
            }

            /** @var Path $path */
            foreach ($openApi->paths as $path) {
                $scope = $documentScope ?? self::scopeOf($path->path);

                if ($scope === null) {
                    continue;
                }

                /** @var Operation $operation */
                foreach ($path->operations as $operation) {
                    self::removeSignatureHeaders($operation);
                    $operation->addParameters(self::headerParameters($scope));
                }
            }
        };
    }

    /**
     * @return list<Parameter>
     */
    public static function headerParameters(ApiKeyScope $scope): array
    {
        $ttl = (int) config('hdid.callcenter.signature_ttl', 300);
        $when = $scope->signatureRequired()
            ? 'Required: every request of a mobile app backend key is signed.'
            : 'Required only when the API key has a signing secret.';

        return [
            (new Parameter('X-Timestamp', 'header'))
                ->setSchema(Schema::fromType(new StringType))
                ->required($scope->signatureRequired())
                ->description("Unix time in seconds, within {$ttl} s of the server clock. {$when}")
                ->example(self::TEST_TIMESTAMP),
            (new Parameter('X-Signature', 'header'))
                ->setSchema(Schema::fromType(new StringType))
                ->required($scope->signatureRequired())
                ->description("Lower-case hex HMAC-SHA256 of \"timestamp\\nMETHOD\\nrequest-uri\\nbody\" with the signing secret (see the document description). {$when} Each signature is accepted once.")
                ->example(self::TEST_SIGNATURE),
        ];
    }

    /**
     * The full document runs the default transformer and a partner document
     * its own one: the last one decides whether the headers are required.
     */
    private static function removeSignatureHeaders(Operation $operation): void
    {
        $operation->parameters = array_values(array_filter(
            $operation->parameters,
            fn ($parameter): bool => ! ($parameter instanceof Parameter && $parameter->in === 'header' && in_array($parameter->name, ['X-Timestamp', 'X-Signature'], true)),
        ));
    }

    private static function scopeOf(string $path): ?ApiKeyScope
    {
        $path = ltrim($path, '/');

        foreach (self::MACHINE_PREFIXES as $prefix => $scope) {
            if (str_starts_with($path, $prefix)) {
                return $scope;
            }
        }

        return null;
    }

    /**
     * The signed payload, exposed for the docs and for partners' reference tests.
     */
    public static function payload(int|string $timestamp, string $method, string $requestUri, string $body): string
    {
        return AuthenticateApiKey::signedPayload($timestamp, $method, $requestUri, $body);
    }
}
