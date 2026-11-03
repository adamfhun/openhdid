<?php

use App\Auth\LoginRejectedException;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnforceAuthSessions;
use App\Http\Middleware\EnsureClientEntitled;
use App\Http\Middleware\EnsurePrincipal;
use App\Http\Middleware\EnsureStaffMfa;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TouchLastSeen;
use App\Http\Middleware\ValidateCsrfTokenUnlessBearer;
use App\Localization\SetLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusted proxies come from config/trustedproxy.php: this callback runs
        // before .env and config are loaded, so it reads nothing itself; only
        // closures that run per request (like the host list below) call config().
        // Trusted hosts: the APP_URL host, the extra names (hdid.extra_hosts)
        // and "localhost" for the container's internal liveness probe; any other
        // Host or X-Forwarded-Host is refused with 400. The closure runs per
        // request, after config is loaded; the framework skips the check in
        // local development and in tests.
        $middleware->trustHosts(at: fn (): array => array_map(
            fn (string $host): string => str_starts_with($host, '*.') ? '^.+\.'.preg_quote(substr($host, 2)).'$' : '^'.preg_quote($host).'$',
            array_values(array_filter([parse_url((string) config('app.url'), PHP_URL_HOST), 'localhost', ...(array) config('hdid.extra_hosts', [])], 'is_string')),
        ), subdomains: false);
        // A guest on an "auth" route: the framework's default sends them to a
        // route named "login", which does not exist here (a 500). The API
        // answers JSON 401 (null: no redirect); everything else that is not
        // the panel (the panel names its own login page) is a staff route,
        // such as the call-view switch. The closure runs per request.
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api', 'api/*') ? null : route('filament.admin.auth.login'));
        $middleware->append(SecurityHeaders::class);
        $middleware->statefulApi();
        $middleware->web(append: [SetLocale::class, EnforceAuthSessions::class, TouchLastSeen::class]);
        // Route-level auth is sorted ahead of the group's tail by priority; the
        // session lifetime and "sign out everywhere" check must run before it.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnforceAuthSessions::class);
        $middleware->api(prepend: [SetLocale::class]);
        $middleware->encryptCookies(except: [SetLocale::COOKIE]);
        // The CSRF guard lives in the "web" group under its Laravel 13 name;
        // a global replace() would never reach it, so swap it inside the group.
        $middleware->replaceInGroup('web', PreventRequestForgery::class, ValidateCsrfTokenUnlessBearer::class);
        $middleware->alias([
            'principal' => EnsurePrincipal::class,
            'staff.mfa' => EnsureStaffMfa::class,
            'entitled' => EnsureClientEntitled::class,
            'api-key' => AuthenticateApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api', 'api/*') || $request->expectsJson(),
        );

        // Every JSON error answer, on every path (any request that expects
        // JSON gets one, see above) and whatever APP_DEBUG says, is free of
        // the debug fields (exception class, file, line, trace); the details
        // stay in the log, as reporting is unchanged. In debug mode the
        // framework also puts the internal message of an unexpected server
        // error into the answer: that becomes the neutral "Server Error" it
        // gives without debug. An HTTP exception (maintenance mode, abort())
        // keeps its message and headers, and the application's own JSON
        // renders (provider_unavailable, unknown_host) carry no trace, so
        // they keep their fields.
        $exceptions->respond(function (Response $response, Throwable $e): Response {
            if (! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
                return $response;
            }

            $data = $response->getData(true);

            if (! is_array($data)) {
                return $response;
            }

            $frameworkDebug = array_key_exists('trace', $data);
            $data = Arr::except($data, ['exception', 'file', 'line', 'trace']);

            if ($frameworkDebug && $response->isServerError() && ! $e instanceof HttpExceptionInterface) {
                $data = ['message' => 'Server Error'];
            }

            // The framework builds its 404 text from the model class name
            // ("No query results for model [App\Models\Client] …"), which
            // leaks the internal structure even with debug off.
            if ($response->getStatusCode() === 404 && str_contains((string) ($data['message'] ?? ''), 'No query results for model')) {
                $data['message'] = __('Not found.');
            }

            return $response->setData($data);
        });

        // An expired or tampered magic link lands on the branded login page,
        // not on the framework's bare 403.
        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if ($request->routeIs('client.magic-link')) {
                return redirect('/login?error=invalid_credentials');
            }

            return null;
        });

        // A request naming a host the deployment does not serve (trusted hosts
        // above) is told so, instead of a bare "Bad request.": a wrong APP_URL
        // or a missing extra host name is then easy to spot.
        $exceptions->render(function (BadRequestHttpException $e, Request $request) {
            if (! $e->getPrevious() instanceof SuspiciousOperationException || ! str_contains($e->getPrevious()->getMessage(), 'Host "')) {
                return null;
            }

            $message = __('Unknown host name: this address is not set up for the application.');

            return $request->is('api', 'api/*') || $request->expectsJson()
                ? response()->json(['message' => $message, 'reason' => 'unknown_host'], 400)
                : response($message, 400, ['Content-Type' => 'text/plain; charset=UTF-8']);
        });

        // A refused login is an expected answer, audited as login.rejected;
        // reporting it would write a stack trace per bot attempt.
        $exceptions->dontReport(LoginRejectedException::class);

        $exceptions->render(function (LoginRejectedException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason->value], $e->reason->httpStatus());
            }

            return null;
        });
    })->create();
