<?php

namespace App\Providers;

use App\Auth\Permission;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Localization\RememberClientLocale;
use App\Mail\Transport\EwsTransport;
use App\Models\User;
use App\Settings\Settings;
use App\Sms\LogSmsSender;
use App\Sms\OzekiSmsSender;
use App\Sms\SmsSender;
use App\Support\ApiSignatureDocs;
use App\Support\HuDate;
use App\Support\PhoneNormalizer;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\ServiceProvider;
use libphonenumber\PhoneNumberUtil;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);

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
            ),
            default => new LogSmsSender,
        });
    }

    public function boot(): void
    {
        $this->useHungarianDateFormats();

        // A long-running worker must not keep the settings of a past request.
        Queue::before(fn () => $this->app->make(Settings::class)->forgetLocal());

        Event::listen(Authenticated::class, RememberClientLocale::class);

        // Real health for monitoring (the framework's /up only proves PHP boots).
        RouteFacade::middleware(['api', 'throttle:30,1,health'])->get('/health', HealthController::class)->name('health');

        // Machine endpoints: one IVR platform shares one address, so limits
        // are keyed on the authenticated key, plus a tight budget per call.
        // The per-address ceiling caps unauthenticated noise (the throttle
        // runs before the key is verified, and an unknown key is not a bucket).
        $ipCeiling = fn (Request $request): Limit => Limit::perMinute(1000)->by('machine-ip:'.$request->ip());
        $callId = fn (Request $request): ?string => is_string($id = $request->input('call_id')) && $id !== '' ? $id : null;

        RateLimiter::for('machine', fn (Request $request): array => [
            $ipCeiling($request),
            Limit::perMinute(600)->by('machine:'.AuthenticateApiKey::identityOf($request)),
        ]);

        RateLimiter::for('ivr', fn (Request $request): array => array_values(array_filter([
            $ipCeiling($request),
            Limit::perMinute(300)->by('ivr:'.AuthenticateApiKey::identityOf($request)),
            $callId($request) !== null ? Limit::perMinute(10)->by('ivr-call:'.$callId($request)) : null,
        ])));

        RateLimiter::for('api-key', fn (Request $request): array => [
            $ipCeiling($request),
            Limit::perMinute(120)->by('api-key:'.AuthenticateApiKey::identityOf($request)),
        ]);

        Gate::define('viewApiDocs', fn (?User $user): bool => $user?->can(Permission::AdminAccess->value) ?? false);

        $this->registerApiDocs();

        Mail::extend('ews', fn (): EwsTransport => new EwsTransport(
            $this->app->make(Http::class),
            $this->app->make(Cache::class),
            config('hdid.ews'),
        ));
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
                'description' => 'Call-center / IVR contract: push call events and verify the caller\'s PIN or dictated identification code. Authenticate with a long-lived API key (X-Api-Key header, bearer token or `api_key` parameter). Verification endpoints accept GET with query parameters for legacy IVR platforms. Keys with a signing secret must sign every request, see below.',
            ],
        ])
            ->expose(
                ui: fn (Router $router, $action) => $router->get('docs/ivr', $action)->name('scramble.ivr.ui'),
                document: fn (Router $router, $action) => $router->get('docs/ivr.json', $action)->name('scramble.ivr.document'),
            )
            ->withDocumentTransformers($apiKey)
            ->withDocumentTransformers(ApiSignatureDocs::transformer(everyOperation: true))
            ->resolveOperationMethodsUsing($allMethods);

        Scramble::registerApi('mobile', [
            'api_path' => 'api/v1/mobile',
            'info' => [
                'version' => config('scramble.info.version'),
                'description' => 'Mobile app backend contract: issue an IVR identification code for a client the backend has already authenticated. Authenticate with a long-lived API key (X-Api-Key header or bearer token). Keys with a signing secret must sign every request, see below.',
            ],
        ])
            ->expose(
                ui: fn (Router $router, $action) => $router->get('docs/mobile', $action)->name('scramble.mobile.ui'),
                document: fn (Router $router, $action) => $router->get('docs/mobile.json', $action)->name('scramble.mobile.document'),
            )
            ->withDocumentTransformers($apiKey)
            ->withDocumentTransformers(ApiSignatureDocs::transformer(everyOperation: true))
            ->resolveOperationMethodsUsing($allMethods);
    }
}
