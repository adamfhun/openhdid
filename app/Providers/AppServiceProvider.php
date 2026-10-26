<?php

namespace App\Providers;

use App\Auth\AuthSessions;
use App\Auth\Mfa\StaffMfa;
use App\Auth\Oidc\OidcProviderConfig;
use App\Auth\Permission;
use App\Enums\ApiKeyScope;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\StartSessionWhenNeeded;
use App\Http\Middleware\ThrottleRequestsUnlessExempt;
use App\Localization\RememberClientLocale;
use App\Mail\Transport\EwsTransport;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Settings\Settings;
use App\Sms\LogSmsSender;
use App\Sms\OzekiSmsSender;
use App\Sms\SmsSender;
use App\Support\ApiSignatureDocs;
use App\Support\ClientAddress;
use App\Support\HuDate;
use App\Support\OutboundProxy;
use App\Support\PhoneNormalizer;
use App\System\InstallationCommandMutex;
use App\System\InstallationEventMutex;
use App\System\InstallationSchedulingMutex;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\CommandMutex;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use libphonenumber\PhoneNumberUtil;
use Spatie\Permission\Models\Role as RoleModel;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);

        // The scheduler's and the isolated commands' mutexes carry this installation's
        // id, so two installations sharing a Valkey/Redis both run their tasks (G31c).
        $this->app->bind(EventMutex::class, InstallationEventMutex::class);
        $this->app->bind(SchedulingMutex::class, InstallationSchedulingMutex::class);
        $this->app->bind(CommandMutex::class, InstallationCommandMutex::class);

        // Every route throttle ("throttle:n,m,bucket", Livewire's upload limit too) leaves
        // the addresses of HDID_RATE_LIMIT_EXEMPT_NETWORKS alone; bound in the framework
        // class's place, so withoutMiddleware(ThrottleRequests::class) still switches it off.
        $this->app->bind(ThrottleRequests::class, ThrottleRequestsUnlessExempt::class);

        // The web group, the Filament panel and Sanctum's stateful API stack
        // all name StartSession; the bound one stores no session a guest does
        // not need and bounds the stored addresses (G14a).
        $this->app->singleton(StartSession::class, fn ($app): StartSession => new StartSessionWhenNeeded(
            $app->make(SessionManager::class),
            fn () => $app->make(CacheFactory::class),
        ));

        $this->app->singleton(PhoneNormalizer::class, fn (): PhoneNormalizer => new PhoneNormalizer(
            PhoneNumberUtil::getInstance(),
            (string) config('hdid.phone.default_region', 'HU'),
        ));

        $this->app->singleton(SmsSender::class, fn ($app): SmsSender => match (config('hdid.sms.driver')) {
            'ozeki' => new OzekiSmsSender(
                $app->make(Http::class),
                (string) config('hdid.ozeki.url'),
                (string) config('hdid.ozeki.username'),
                (string) config('hdid.ozeki.password'),
                trim((string) config('hdid.ozeki.status_action', '')) ?: OzekiSmsSender::DEFAULT_STATUS_ACTION,
                OidcProviderConfig::listOf(mb_strtolower((string) config('hdid.ozeki.delivered_statuses', ''))) ?: OzekiSmsSender::DEFAULT_DELIVERED_STATUSES,
                OidcProviderConfig::listOf(mb_strtolower((string) config('hdid.ozeki.undelivered_statuses', ''))) ?: OzekiSmsSender::DEFAULT_UNDELIVERED_STATUSES,
            ),
            default => new LogSmsSender,
        });
    }

    public function boot(): void
    {
        $this->useHungarianDateFormats();
        $this->useConfiguredRootUrl();

        // A long-running worker must not keep the settings of a past request.
        Queue::before(fn () => $this->app->make(Settings::class)->forgetLocal());

        // Markdown mails (the fallback login link mail prints the client's
        // name): echoed data stays text, a link or image in it is not rendered.
        // The secured encoding escapes only \ [ ] < >, not quotes or &: inside an
        // attribute (<x-mail::button :url>, <x-mail::header :url>) only a value the
        // application built itself may stand, never outside data; if one must,
        // escape it with e() and hand it over as an HTML string object.
        Markdown::withSecuredEncoding();

        // Every outbound request (master data, Exchange, SMS gateway, ADFS,
        // Entra) goes through the configured proxy, or explicitly direct.
        $this->app->make(Http::class)->globalOptions(fn (): array => OutboundProxy::options());

        Event::listen(Authenticated::class, RememberClientLocale::class);
        Event::listen(Login::class, fn (Login $event) => $this->app->make(AuthSessions::class)->stamp($event));
        // Every staff login (form, SSO, remember-me cookie) starts without the second factor.
        Event::listen(Login::class, fn (Login $event) => $event->guard === 'web' && request()->hasSession()
            ? $this->app->make(StaffMfa::class)->forget() : null);

        // Real health for monitoring (the framework's /up only proves PHP boots).
        RouteFacade::middleware(['api', 'throttle:30,1,health'])->get('/health', HealthController::class)->name('health');

        // Machine endpoints: one IVR platform shares one address, so limits
        // are keyed on the authenticated key, plus a tight budget per call.
        // The per-address ceiling caps unauthenticated noise (the throttle
        // runs before the key is verified, and an unknown key is not a bucket).
        // The per-call budget (IvrController) is counted only once the key and
        // its signature passed: a guessed call id hammered with forged keys, or
        // with a stolen key but no signing secret, must not block the phone menu.
        // An IPv6 address counts by its /64 network (ClientAddress).
        $ipCeiling = fn (Request $request): Limit => Limit::perMinute(1000)->by('machine-ip:'.ClientAddress::limitKey($request->ip()));

        RateLimiter::for('machine', fn (Request $request): array => [
            $ipCeiling($request),
            Limit::perMinute(600)->by('machine:'.AuthenticateApiKey::identityOf($request)),
        ]);

        // The per-call budget (10 a minute) is counted by the IVR controller, after the
        // key and its signature passed: a request that ends in 401 must not use up the
        // partner's budget for a call, even with a stolen key but no signing secret.
        RateLimiter::for('ivr', fn (Request $request): array => [
            $ipCeiling($request),
            Limit::perMinute(300)->by('ivr:'.AuthenticateApiKey::identityOf($request)),
        ]);

        RateLimiter::for('api-key', fn (Request $request): array => [
            $ipCeiling($request),
            Limit::perMinute(120)->by('api-key:'.AuthenticateApiKey::identityOf($request)),
        ]);

        // Spatie's Role lives outside App\Models, so policy discovery never finds
        // RolePolicy; without it the panel would let every staff user edit roles.
        Gate::policy(RoleModel::class, RolePolicy::class);

        // The interface documents need the second factor too, like the panel they are linked from.
        Gate::define('viewApiDocs', fn (?User $user): bool => ($user?->can(Permission::AdminAccess->value) ?? false)
            && $this->app->make(StaffMfa::class)->isSatisfied($user));

        $this->registerApiDocs();

        Mail::extend('ews', fn (): EwsTransport => new EwsTransport(
            $this->app->make(Http::class),
            $this->app->make(Cache::class),
            config('hdid.ews'),
        ));
    }

    /**
     * Every generated address (the login link in mails, the SSO return
     * address, redirects, asset and form URLs) starts with APP_URL, never with
     * the Host or X-Forwarded-Host of the request, which the client or a
     * forwarding proxy may set. An https APP_URL keeps https behind a proxy
     * that forwards plain http. Local development keeps the address it was
     * opened on.
     */
    private function useConfiguredRootUrl(): void
    {
        $root = rtrim((string) config('app.url'), '/');

        if ($this->app->environment('local') || preg_match('#^https?://[^/]+#i', $root) !== 1) {
            return;
        }

        URL::forceRootUrl($root);

        if (str_starts_with(strtolower($root), 'https://')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Every date on the staff panel follows the Hungarian standard
     * (2026. 09. 12. 14:05), in tables, detail pages, forms and pickers alike.
     */
    private function useHungarianDateFormats(): void
    {
        Table::configureUsing(fn (Table $table) => $table
            ->defaultDateDisplayFormat(HuDate::DATE)
            ->defaultDateTimeDisplayFormat(HuDate::DATETIME)
            ->defaultTimeDisplayFormat(HuDate::TIME));

        Schema::configureUsing(fn (Schema $schema) => $schema
            ->defaultDateDisplayFormat(HuDate::DATE)
            ->defaultDateTimeDisplayFormat(HuDate::DATETIME)
            ->defaultTimeDisplayFormat(HuDate::TIME));

        DatePicker::configureUsing(fn (DatePicker $picker) => $picker->displayFormat(HuDate::DATE));
        DateTimePicker::configureUsing(fn (DateTimePicker $picker) => $picker->displayFormat(HuDate::DATETIME));
    }

    /**
     * Three OpenAPI documents: the full API, the IVR contract for the call
     * center, and the server-to-server contract for the mobile app backend.
     */
    private function registerApiDocs(): void
    {
        $bearer = fn (OpenApi $openApi) => $openApi->secure(SecurityScheme::http('bearer'));
        $apiKey = fn (OpenApi $openApi) => $openApi->secure(SecurityScheme::apiKey('header', 'X-Api-Key'));

        $allMethods = fn (Route $route): array => array_values(array_diff(array_map('strtolower', $route->methods()), ['head']));

        Scramble::configure()->withDocumentTransformers($bearer)->withDocumentTransformers(ApiSignatureDocs::transformer())->resolveOperationMethodsUsing($allMethods);

        Scramble::registerApi('ivr', [
            'api_path' => 'api/v1/callcenter',
            'info' => [
                'version' => config('scramble.info.version'),
                'description' => 'Call-center / IVR contract: push call events and verify the caller\'s PIN (only for a caller recognised by a registered number) or one-time identification code. Authenticate with a long-lived API key (X-Api-Key header, bearer token or `api_key` parameter). Verification endpoints accept GET with query parameters for legacy IVR platforms. Keys with a signing secret must sign every request, see below.',
            ],
        ])
            ->expose(
                ui: fn (Router $router, $action) => $router->get('docs/ivr', $action)->name('scramble.ivr.ui'),
                document: fn (Router $router, $action) => $router->get('docs/ivr.json', $action)->name('scramble.ivr.document'),
            )
            ->withDocumentTransformers($apiKey)
            ->withDocumentTransformers(ApiSignatureDocs::transformer(ApiKeyScope::CallCenter))
            ->resolveOperationMethodsUsing($allMethods);

        Scramble::registerApi('mobile', [
            'api_path' => 'api/v1/mobile',
            'info' => [
                'version' => config('scramble.info.version'),
                'description' => 'Mobile app backend contract: issue an IVR identification code for a client the backend has already authenticated. The code is meant for the phone menu only (the app sends it as DTMF from the dialler); the helpdesk never accepts it and the client never has to read it out. Authenticate with a long-lived API key (X-Api-Key header or bearer token) and sign every request with the key\'s signing secret, see below.',
            ],
        ])
            ->expose(
                ui: fn (Router $router, $action) => $router->get('docs/mobile', $action)->name('scramble.mobile.ui'),
                document: fn (Router $router, $action) => $router->get('docs/mobile.json', $action)->name('scramble.mobile.document'),
            )
            ->withDocumentTransformers($apiKey)
            ->withDocumentTransformers(ApiSignatureDocs::transformer(ApiKeyScope::MobileBackend))
            ->resolveOperationMethodsUsing($allMethods);
    }
}
