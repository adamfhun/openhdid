<?php

namespace App\Http\Middleware;

use App\Audit\Auditor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening headers (OWASP Secure Headers). The content security
 * policy allows inline scripts and eval because Livewire, Alpine and the
 * Vite-built bundles rely on them; extra origins come from config.
 */
class SecurityHeaders
{
    /**
     * The API documentation pages (Scramble) render with Stoplight Elements loaded from
     * this CDN; the policy admits it on those routes only, every other page stays strict.
     */
    private const DOCS_ASSET_ORIGIN = 'https://unpkg.com';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($requestId = $request->attributes->get(Auditor::REQUEST_ID_ATTRIBUTE)) {
            $headers->set('X-Request-Id', $requestId);
        }

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (config('hdid.security.csp_enabled') && ! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy($request));
        }

        return $response;
    }

    private function contentSecurityPolicy(Request $request): string
    {
        $extra = trim((string) config('hdid.security.csp_extra_sources'));
        $docs = $request->routeIs('scramble.*') ? self::DOCS_ASSET_ORIGIN : '';

        $directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' {$docs} {$extra}",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com {$docs} {$extra}",
            "font-src 'self' data: https://fonts.bunny.net https://fonts.gstatic.com {$docs} {$extra}",
            "img-src 'self' data: blob: https: {$extra}",
            "connect-src 'self' ws: wss: {$extra}",
            "frame-src 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];

        return implode('; ', array_map(fn (string $d): string => trim(preg_replace('/ {2,}/', ' ', $d) ?? $d), $directives));
    }
}
