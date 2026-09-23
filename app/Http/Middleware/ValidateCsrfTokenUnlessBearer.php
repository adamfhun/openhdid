<?php

namespace App\Http\Middleware;

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
        // session itself signs the client in; a stray session cookie that
        // carries no login (every response on the web stack sets one) is
        // harmless, so the mobile app is not locked out by it.
        if ($request instanceof Request && $request->bearerToken() !== null && ! Auth::guard('client')->check()) {
            return true;
        }

        return parent::inExceptArray($request);
    }
}
