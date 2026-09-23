<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * "Back" without trusting the Referer header. The header is attacker
 * controlled on any GET link, so a bare redirect()->back() turns these
 * endpoints into an open redirect on a trusted domain.
 */
final class SafeRedirect
{
    public static function back(string $fallback): string
    {
        $previous = URL::previous($fallback);

        return self::isLocal($previous) ? $previous : $fallback;
    }

    public static function isLocal(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null || $host === false) {
            // A relative path stays inside the application.
            return ! str_starts_with($url, '//');
        }

        return mb_strtolower($host) === mb_strtolower((string) parse_url(URL::to('/'), PHP_URL_HOST));
    }
}
