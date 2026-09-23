<?php

namespace App\Localization;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the UI language: explicit `locale` cookie, then the browser's
 * Accept-Language, then the configured default (Hungarian).
 */
class SetLocale
{
    public const COOKIE = 'locale';

    /** @var list<string> */
    public const SUPPORTED = ['hu', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(static::resolve($request));

        return $next($request);
    }

    public static function resolve(Request $request): string
    {
        $cookie = $request->cookie(self::COOKIE);
        if (is_string($cookie) && in_array($cookie, self::SUPPORTED, true)) {
            return $cookie;
        }

        $preferred = $request->getPreferredLanguage(self::SUPPORTED);

        return is_string($preferred) && in_array($preferred, self::SUPPORTED, true) ? $preferred : (string) config('app.locale');
    }
}
