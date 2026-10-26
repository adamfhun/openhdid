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
        // session itself signs someone in (a client or a staff member); a
        // stray session cookie that carries no login (a guest gets one from
        // /sanctum/csrf-cookie or the panel) is harmless, so the mobile app
        // is not locked out by it.
        if ($request instanceof Request && $request->bearerToken() !== null && ! $this->sessionSignsSomeoneIn($request)) {
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
     * Read from the session keys only: asking a guard would sign the account
     * in from a remember cookie here, before the session checks decide
     * whether that cookie may be used at all.
     */
    private function sessionSignsSomeoneIn(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        foreach (PrincipalType::cases() as $type) {
            $guard = Auth::guard($type->guard());

            if ($guard instanceof SessionGuard && $request->session()->has($guard->getName())) {
                return true;
            }
        }

        return false;
    }
}
