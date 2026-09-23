<?php

namespace App\Http\Middleware;

use App\Enums\PrincipalType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Sanctum authenticates both Users and Clients; this pins a route group to one.
 */
class EnsurePrincipal
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $expected = PrincipalType::from($type)->modelClass();

        if (! $request->user() instanceof $expected) {
            throw new HttpException(403, 'This endpoint is not available for the signed-in account type.');
        }

        return $next($request);
    }
}
