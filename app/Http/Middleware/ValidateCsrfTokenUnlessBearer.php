<?php

namespace App\Http\Middleware;

use App\Enums\PrincipalType;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Same-origin browser requests carry the session cookie and must prove CSRF;
 * mobile apps authenticate with a bearer token and have no session to forge.
 */
class ValidateCsrfTokenUnlessBearer extends PreventRequestForgery
{
    protected function inExceptArray($request): bool
    {
        // A bearer request can only be forged from a browser page when the
        // browser's credentials sign someone in (a client or a staff member):
        // a session login, or a remember-me cookie that the route's guard
        // would turn into one. A stray session cookie that carries no login
        // (a guest gets one from /sanctum/csrf-cookie or the panel) is
        // harmless, so the mobile app is not locked out by it.
        if ($request instanceof Request && $request->bearerToken() !== null && ! $this->browserCredentialsPresent($request)) {
            return true;
        }

        return parent::inExceptArray($request);
    }

    /**
     * No XSRF cookie on an answer whose session is not kept
     * (StartSessionWhenNeeded): it would carry a token nobody stores.
     */
    protected function addCookieToResponse($request, $response)
    {
        if ($request instanceof Request && StartSessionWhenNeeded::isNotNeeded($request)) {
            return $response;
        }

        return parent::addCookieToResponse($request, $response);
    }

    /**
     * Read from the cookie and session key names only: asking a guard would
     * sign the account in from a remember cookie here, before the session
     * checks decide whether that cookie may be used at all. A remember-me
     * cookie counts by its presence (with a value: one EncryptCookies could
     * not decrypt is left without one, and the guard ignores it too), and
     * also when the request has no session yet.
     */
    private function browserCredentialsPresent(Request $request): bool
    {
        $guards = array_filter(
            array_map(fn (PrincipalType $type): mixed => Auth::guard($type->guard()), PrincipalType::cases()),
            fn (mixed $guard): bool => $guard instanceof SessionGuard,
        );

        foreach ($guards as $guard) {
            if (filled($request->cookies->get($guard->getRecallerName()))) {
                return true;
            }
        }

        if (! $request->hasSession()) {
            return false;
        }

        foreach ($guards as $guard) {
            if ($request->session()->has($guard->getName())) {
                return true;
            }
        }

        return false;
    }
}
