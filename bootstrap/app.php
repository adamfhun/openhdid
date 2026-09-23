<?php

use App\Auth\LoginRejectedException;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsureClientEntitled;
use App\Http\Middleware\EnsurePrincipal;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ValidateCsrfTokenUnlessBearer;
use App\Localization\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusted proxies come from config/trustedproxy.php: this callback runs
        // before .env and config are loaded, so nothing may be read here.
        $middleware->append(SecurityHeaders::class);
        $middleware->statefulApi();
        $middleware->web(append: [SetLocale::class]);
        $middleware->api(prepend: [SetLocale::class]);
        $middleware->encryptCookies(except: [SetLocale::COOKIE]);
        // The CSRF guard lives in the "web" group under its Laravel 13 name;
        // a global replace() would never reach it, so swap it inside the group.
        $middleware->replaceInGroup('web', PreventRequestForgery::class, ValidateCsrfTokenUnlessBearer::class);
        $middleware->alias([
            'principal' => EnsurePrincipal::class,
            'entitled' => EnsureClientEntitled::class,
            'api-key' => AuthenticateApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api', 'api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (Response $response, Throwable $e, Request $request): Response {
            if ($request->is('api', 'api/*') && $response instanceof JsonResponse && $response->isClientError()) {
                $data = Arr::except($response->getData(true), ['exception', 'file', 'line', 'trace']);

                // The framework builds its 404 text from the model class name
                // ("No query results for model [App\Models\Client] …"), which
                // leaks the internal structure even with debug off.
                if ($response->getStatusCode() === 404 && str_contains((string) ($data['message'] ?? ''), 'No query results for model')) {
                    $data['message'] = __('Not found.');
                }

                $response->setData($data);
            }

            return $response;
        });

        // An expired or tampered magic link lands on the branded login page,
        // not on the framework's bare 403.
        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if ($request->routeIs('client.magic-link')) {
                return redirect('/login?error=invalid_credentials');
            }

            return null;
        });

        $exceptions->render(function (LoginRejectedException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason->value], $e->reason->httpStatus());
            }

            return null;
        });
    })->create();
