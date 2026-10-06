<?php

namespace App\Http\Middleware;

use App\Auth\AuthSessions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Absolute session lifetime and "sign out everywhere", before authentication.
 */
class EnforceAuthSessions
{
    public function __construct(private readonly AuthSessions $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->sessions->enforce($request);

        return $next($request);
    }
}
