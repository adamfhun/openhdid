<?php

namespace App\Sync;

use Illuminate\Support\Facades\Log;

/**
 * An EMD request the API refused (HTTP 4xx/5xx): the response body goes to
 * the application log, because it usually says why (a validation message
 * for a 400). Only the body, cut at MAX_BYTES, with the status and the
 * address without its query; the request (token, payload) never.
 */
final class RefusedResponse
{
    public const MAX_BYTES = 4000;

    public static function log(string $request, int $status, string $url, string $body): void
    {
        if ($status < 400) {
            return;
        }

        Log::warning('EMD '.$request.' refused (HTTP '.$status.'); the response body follows.', [
            'status' => $status,
            'url' => strtok($url, '?'),
            'body' => mb_scrub(mb_strcut($body, 0, self::MAX_BYTES, 'UTF-8'), 'UTF-8'),
            'truncated' => strlen($body) > self::MAX_BYTES,
        ]);
    }
}
