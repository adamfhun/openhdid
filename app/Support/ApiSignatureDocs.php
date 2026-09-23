<?php

namespace App\Support;

use App\Http\Middleware\AuthenticateApiKey;
use Closure;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Path;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Documents the optional request signing of machine API keys in the OpenAPI
 * output: the scheme in the document description and the two headers on
 * every machine operation. The wording mirrors what AuthenticateApiKey
 * enforces, so a partner can implement it from the generated docs alone.
 */
final class ApiSignatureDocs
{
    /** Path prefixes (relative to the document root) of the operations that may require a signature. */
    private const MACHINE_PREFIXES = ['v1/callcenter/', 'v1/mobile/'];

    private const HEADING = '## Request signing (optional)';

    public static function description(): string
    {
        $ttl = (int) config('hdid.callcenter.signature_ttl', 300);

        $heading = self::HEADING;

        return <<<MD
{$heading}

An API key may carry a signing secret (issued in the admin panel under *System › API keys › Signing secret*, shown once). While a key has a secret, **every** request made with that key must be signed, otherwise it is refused with `401`. Keys without a secret need no extra headers.

Send two headers in addition to the API key:

| Header | Value |
|---|---|
| `X-Timestamp` | Unix time in seconds; must be within {$ttl} seconds of the server clock |
| `X-Signature` | Lower-case hex `HMAC-SHA256(payload, secret)` |

The payload is four lines joined with a single `\\n` and no trailing newline:

```
<timestamp>
<HTTP method, upper case>
<request URI including the query string, e.g. /api/v1/callcenter/ivr/verify-code?code=12345678&call_id=abc>
<raw request body; empty for GET>
```

The URI and the body must be signed byte-for-byte as sent (same parameter order and encoding). Because the URI is part of the payload, a captured signature cannot be reused for another call. Each signature is accepted only once within the time window: a retry must carry a fresh timestamp and a fresh signature.

Rejections return `401` and are recorded in the audit log with one of the reasons `stale_timestamp`, `bad_signature` or `replayed`.

Shell example:

```
TS=\$(date +%s); URI='/api/v1/callcenter/calls'
BODY='call_id=demo-1&caller_number=06301234567&status=ringing'
SIG=\$(printf '%s\\nPOST\\n%s\\n%s' "\$TS" "\$URI" "\$BODY" | openssl dgst -sha256 -hmac "\$SECRET" | awk '{print \$NF}')
curl -H "X-Api-Key: hdid_<key>" -H "X-Timestamp: \$TS" -H "X-Signature: \$SIG" -X POST "https://hdid.example.hu\$URI" -d "\$BODY"
```
MD;
    }

    /**
     * Document transformer: appends the scheme to the description and adds
     * the two headers to every machine operation. A partner document (IVR,
     * mobile backend) consists of machine operations only, so its paths are
     * relative and every operation is signed; in the full API document only
     * the call-center and mobile prefixes are.
     *
     * @return Closure(OpenApi): void
     */
    public static function transformer(bool $everyOperation = false): Closure
    {
        return function (OpenApi $openApi) use ($everyOperation): void {
            // A partner document runs the default transformers as well, so
            // both the description and the headers are added only once.
            if (! str_contains($openApi->info->description, self::HEADING)) {
                $openApi->info->setDescription(trim($openApi->info->description."\n\n".self::description()));
            }

            /** @var Path $path */
            foreach ($openApi->paths as $path) {
                if (! $everyOperation && ! self::isMachinePath($path->path)) {
                    continue;
                }

                /** @var Operation $operation */
                foreach ($path->operations as $operation) {
                    if (! self::isSigned($operation)) {
                        $operation->addParameters(self::headerParameters());
                    }
                }
            }
        };
    }

    /**
     * @return list<Parameter>
     */
    public static function headerParameters(): array
    {
        $ttl = (int) config('hdid.callcenter.signature_ttl', 300);

        return [
            (new Parameter('X-Timestamp', 'header'))
                ->setSchema(Schema::fromType(new StringType))
                ->description("Unix time in seconds, within {$ttl} s of the server clock. Required only when the API key has a signing secret.")
                ->example('1789714410'),
            (new Parameter('X-Signature', 'header'))
                ->setSchema(Schema::fromType(new StringType))
                ->description('Hex HMAC-SHA256 of "timestamp\nMETHOD\nrequest-uri\nbody" with the signing secret (see the document description). Required only when the API key has a signing secret; each signature is accepted once.')
                ->example('9c1f2b7e4d0a6f3c8b5e1a2d4f6c8e0b7a9d1c3e5f7b9d1a3c5e7f9b1d3f5a7c'),
        ];
    }

    private static function isSigned(Operation $operation): bool
    {
        foreach ($operation->parameters as $parameter) {
            if ($parameter instanceof Parameter && $parameter->name === 'X-Signature' && $parameter->in === 'header') {
                return true;
            }
        }

        return false;
    }

    private static function isMachinePath(string $path): bool
    {
        $path = ltrim($path, '/');

        foreach (self::MACHINE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The signed payload, exposed for the docs and for partners' reference tests.
     */
    public static function payload(int|string $timestamp, string $method, string $requestUri, string $body): string
    {
        return AuthenticateApiKey::signedPayload($timestamp, $method, $requestUri, $body);
    }
}
