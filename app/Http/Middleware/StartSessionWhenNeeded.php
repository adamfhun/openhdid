<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Arr;

/**
 * The framework's session middleware with two limits on what a visitor who
 * is not signed in leaves in the shared session store (Valkey in the
 * container release), so that cookieless requests cannot fill it (G14a):
 *
 *  - a request that brings no session cookie, on a route that needs no
 *    session before sign-in (the portal shell, the JSON APIs, the language
 *    switch, the old magic-link redirect, the health probe), and whose
 *    session ends the request holding only what the framework generates
 *    (the CSRF token), is answered without storing the session and without
 *    the session and XSRF cookies. Current browsers pass the CSRF check by
 *    Sec-Fetch-Site; an older one gets 419 once, and api.js fetches
 *    /sanctum/csrf-cookie (which keeps its session) and retries. Signing in,
 *    an SSO redirect or a flash message makes the session worth keeping;
 *  - the "previous" and "intended" addresses kept in any session are
 *    bounded: a longer URL is kept without its query string, a still longer
 *    one not at all, so the address length no longer sizes the entry.
 *
 * The container hands it out in place of StartSession, so the web group,
 * the Filament panel and Sanctum's stateful API stack all run it.
 */
class StartSessionWhenNeeded extends StartSession
{
    /** Longest URL kept as the previous or the intended address, in bytes. */
    public const MAX_STORED_URL_LENGTH = 2048;

    /**
     * Routes whose answers never need a new session of their own.
     *
     * @var list<string>
     */
    public const SESSIONLESS_ROUTES = ['client.spa', 'api.*', 'locale.switch', 'client.magic-link', 'health'];

    /** Session keys holding addresses taken from the request. */
    private const URL_KEYS = ['_previous.url', 'url.intended'];

    /**
     * Keys put into every session by themselves: the framework's CSRF token,
     * previous address and flash bookkeeping, and Filament's notification
     * mailbox address (derived from the session id and token on every route).
     */
    private const GENERATED_KEYS = ['_token', '_previous', '_flash.new', '_flash.old', 'filament.notifications.mailbox'];

    /** Request attribute: the request brought no session cookie of ours. */
    private const ARRIVED_WITHOUT_SESSION = 'hdid.session.arrived_without';

    /**
     * The parent's steps, with the stored addresses bounded and nothing
     * stored or sent for a session no one needs.
     */
    protected function handleStatefulRequest(Request $request, $session, Closure $next)
    {
        // getSession() has set the id from the cookie, or a new one without it.
        $request->attributes->set(self::ARRIVED_WITHOUT_SESSION, $request->cookies->get($session->getName()) !== $session->getId());

        $request->setLaravelSession($this->startSession($request, $session));

        $this->collectGarbage($session);

        $response = $next($request);

        if (self::isNotNeeded($request)) {
            return $response;
        }

        $this->storeCurrentUrl($request, $session);

        $this->limitStoredUrls($session);

        $this->addCookieToResponse($response, $session);

        $this->saveSession($request);

        return $response;
    }

    /**
     * Whether this answer goes out without a stored session (the CSRF guard
     * then leaves out its cookie too).
     */
    public static function isNotNeeded(Request $request): bool
    {
        $route = $request->route();

        return $request->attributes->get(self::ARRIVED_WITHOUT_SESSION) === true
            && $route instanceof Route
            && $route->named(...self::SESSIONLESS_ROUTES)
            && $request->hasSession()
            && self::holdsOnlyGeneratedKeys($request->session());
    }

    /** A flashed value, a login or an SSO state is a key of its own. */
    private static function holdsOnlyGeneratedKeys(Session $session): bool
    {
        $rest = Arr::dot(Arr::except($session->all(), self::GENERATED_KEYS));

        return array_filter($rest, fn (mixed $value): bool => $value !== []) === [];
    }

    private function limitStoredUrls(Session $session): void
    {
        foreach (self::URL_KEYS as $key) {
            $url = $session->get($key);

            if (! is_string($url) || strlen($url) <= self::MAX_STORED_URL_LENGTH) {
                continue;
            }

            $withoutQuery = explode('?', $url, 2)[0];

            if (strlen($withoutQuery) <= self::MAX_STORED_URL_LENGTH) {
                $session->put($key, $withoutQuery);
            } else {
                $session->forget($key);
            }
        }
    }
}
