<?php

namespace App\Http\Middleware;

use App\System\ErrorMail;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The outermost middleware of every web request for the HTTP 500 Report:
 * marks the request so the errors it reports are only noted, and after the
 * answer has gone out lets ErrorMail decide on the final status.
 */
class ReportServerErrors
{
    public function __construct(private readonly ErrorMail $errorMail) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->errorMail->enabled()) {
            $request->attributes->set(ErrorMail::REQUEST_ATTRIBUTE, []);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->attributes->has(ErrorMail::REQUEST_ATTRIBUTE)) {
            $this->errorMail->afterRequest($request, $response);
        }
    }
}
