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

    /**
     * Only a plain address of this application: a path with exactly one
     * leading slash, or an http(s) address of the application's host and
     * port without user info. Anything parse_url and a browser could read
     * differently is refused: a backslash (a browser treats it as a slash,
     * so "https://evil.example\@app/" goes to evil.example while parse_url
     * names the application's host), whitespace and control characters (a
     * browser strips tabs and newlines), another scheme.
     */
    public static function isLocal(string $url): bool
    {
        if ($url === '' || preg_match('/[\\\\\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = isset($parts['scheme']) ? mb_strtolower($parts['scheme']) : null;

        if (! isset($parts['host'])) {
            // A relative path stays inside the application; "//host" and "https:/host" do not.
            return $scheme === null && str_starts_with($url, '/') && ! str_starts_with($url, '//');
        }

        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $application = parse_url(URL::to('/'));

        if (! is_array($application) || ! isset($application['host'])) {
            return false;
        }

        return mb_strtolower($parts['host']) === mb_strtolower($application['host'])
            && self::port($scheme, $parts['port'] ?? null) === self::port(mb_strtolower($application['scheme'] ?? 'http'), $application['port'] ?? null);
    }

    private static function port(string $scheme, ?int $port): int
    {
        return $port ?? ($scheme === 'https' ? 443 : 80);
    }
}
