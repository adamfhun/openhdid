<?php

namespace App\Http\Middleware;

use App\Support\ClientAddress;
use App\Support\RateLimitExemptions;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * The framework's route throttle ("throttle:n,m,bucket"), bound in its
 * place: a request without a signed-in user is counted by its address
 * (an IPv6 one by its /64 network, as nginx counts it in the container),
 * and an address of HDID_RATE_LIMIT_EXEMPT_NETWORKS is not counted at
 * all. A signed-in user keeps the limit per user, and a named limiter
 * (the machine interfaces: throttle:machine, ivr, api-key) keeps its own
 * keys, the address ceiling included: partners are limited per key.
 */
class ThrottleRequestsUnlessExempt extends ThrottleRequests
{
    public function __construct(RateLimiter $limiter, private readonly RateLimitExemptions $exemptions)
    {
        parent::__construct($limiter);
    }

    /**
     * @param  int|string  $maxAttempts
     * @param  float|int  $decayMinutes
     * @param  string  $prefix
     */
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = ''): Response
    {
        $named = is_string($maxAttempts) && func_num_args() === 3 && $this->limiter->limiter($maxAttempts) !== null;

        if (! $named && $this->exempt($request)) {
            return $next($request);
        }

        return parent::handle(...func_get_args());
    }

    /**
     * A request without a signed-in user is counted by its address, an IPv6
     * one by its /64 network (ClientAddress); a signed-in user per user.
     *
     * @param  Request  $request
     */
    protected function resolveRequestSignature($request): string
    {
        if ($request->user() === null && ($route = $request->route()) !== null) {
            $signature = $route->getDomain().'|'.ClientAddress::limitKey($request->ip());

            return static::$shouldHashKeys ? sha1($signature) : $signature;
        }

        return parent::resolveRequestSignature($request);
    }

    private function exempt(Request $request): bool
    {
        return $request->user() === null && $this->exemptions->covers($request->ip());
    }
}
