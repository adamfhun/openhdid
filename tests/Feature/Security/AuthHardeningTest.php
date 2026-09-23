<?php

use App\Auth\AccountLogin;
use App\Enums\ApiKeyScope;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\ValidateCsrfTokenUnlessBearer;
use App\Mail\MagicLinkMail;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\OneTimeCode;
use App\Sms\FakeSmsSender;
use App\Sms\SmsSender;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->withHeader('Referer', config('app.url'));
    Mail::fake();
    $this->sms = new FakeSmsSender;
    app()->instance(SmsSender::class, $this->sms);
    RateLimiter::clear('passwordless:magic_link:'.hash('sha256', 'c@x.hu'));
});

function magicLinkFor(string $email): string
{
    test()->postJson('/api/v1/client/auth/magic-link', ['email' => $email])->assertOk();
    $url = null;
    Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $mail) use (&$url) {
        $url = $mail->url;

        return true;
    });

    return $url;
}

it('signs in from a magic link without a remember cookie and never audits the remember token', function (): void {
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);

    $response = $this->get(magicLinkFor('c@x.hu'))->assertRedirect('/');
    $this->assertAuthenticatedAs($client, 'client');

    $rememberCookies = collect($response->headers->getCookies())->filter(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_'));
    expect($rememberCookies)->toBeEmpty()
        ->and($client->fresh()->remember_token)->toBeNull()
        ->and(AuditLog::query()->get()->filter(fn (AuditLog $log) => str_contains(json_encode($log->context), 'remember_token')))->toBeEmpty();
});

it('limits login requests per e-mail address, quietly', function (): void {
    Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $this->withoutMiddleware(ThrottleRequests::class);

    foreach (range(1, 7) as $i) {
        $this->postJson('/api/v1/client/auth/magic-link', ['email' => $i % 2 ? 'c@x.hu' : 'C@x.hu '])->assertOk();
    }

    Mail::assertSent(MagicLinkMail::class, 5);
    expect(AuditLog::query()->where('event', 'login.request_throttled')->count())->toBe(2);
});

it('signs a closed client out of a live session and deletes its sessions and secrets on closure', function (): void {
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $this->get(magicLinkFor('c@x.hu'));
    $this->getJson('/api/v1/client/me')->assertOk();
    $this->postJson('/api/v1/client/mobile-code')->assertOk();
    DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $client->id, 'payload' => '', 'last_activity' => time()]);

    config()->set('session.driver', 'database');
    auth('client')->user()->close('test');
    config()->set('session.driver', 'array');

    expect(DB::table('sessions')->where('user_id', $client->id)->count())->toBe(0)
        ->and(OneTimeCode::query()->where('client_id', $client->id)->usable()->count())->toBe(0);

    $this->getJson('/api/v1/client/me')->assertForbidden()->assertJsonPath('reason', 'account_closed');
    $this->getJson('/api/v1/client/me')->assertUnauthorized();
});

/**
 * GET /me authenticated by nothing but a remember-me cookie, as a browser
 * would after its session expired. Cookies are passed per request (not via
 * withCookie) so they never leak onto the bearer call, and the guard cache
 * and session are reset so only the cookie can sign the client in.
 */
function meViaRecaller(Client $client, string $rememberToken): TestResponse
{
    $guard = Auth::guard('client');
    $name = $guard->getRecallerName();
    $value = $client->getKey().'|'.$rememberToken.'|'.$guard->hashPasswordForCookie((string) $client->getAuthPassword());

    Auth::forgetGuards();
    test()->flushSession();

    return test()->call('GET', '/api/v1/client/me', [], [
        $name => encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$value, false),
    ], [], ['HTTP_ACCEPT' => 'application/json']);
}

it('issues scoped tokens and can sign a client out everywhere, including the remember-me cookie', function (): void {
    $client = Client::factory()->synced()->create();
    $token = app(AccountLogin::class)->issueToken($client, 'test', 'phone');
    app(AccountLogin::class)->issueToken($client, 'test', 'tablet');
    $client->forceFill(['remember_token' => $oldRememberToken = Str::random(60)])->saveQuietly();

    expect($client->tokens()->first()->abilities)->toBe([AccountLogin::ABILITY_CLIENT_PORTAL]);
    meViaRecaller($client, $oldRememberToken)->assertOk();

    // "Sign out everywhere" from the mobile app: bearer token only, no session or cookie.
    Auth::forgetGuards();
    $this->flushSession();
    $this->withoutMiddleware(ThrottleRequests::class)->flushHeaders()->withToken($token)->postJson('/api/v1/client/logout-all')->assertOk();

    expect($client->tokens()->count())->toBe(0)
        ->and($client->fresh()->remember_token)->not->toBeNull()
        ->and($client->fresh()->remember_token)->not->toBe($oldRememberToken);
    meViaRecaller($client, $oldRememberToken)->assertUnauthorized();
});

it('installs the bearer-aware csrf guard in the web group instead of the stock one', function (): void {
    $web = app('router')->getMiddlewareGroups()['web'];

    expect($web)->toContain(ValidateCsrfTokenUnlessBearer::class)
        ->and($web)->not->toContain(PreventRequestForgery::class);
});

it('skips csrf for bearer requests unless the session itself signs a client in', function (): void {
    $middleware = app(ValidateCsrfTokenUnlessBearer::class);
    $exempt = fn (Request $request): bool => (fn () => $this->inExceptArray($request))->call($middleware);

    $bare = Request::create('/api/v1/client/logout', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer t']);
    $strayCookie = Request::create('/api/v1/client/logout', 'POST', cookies: [config('session.cookie') => 'x'], server: ['HTTP_AUTHORIZATION' => 'Bearer t']);
    $browser = Request::create('/api/v1/client/logout', 'POST', cookies: [config('session.cookie') => 'x']);

    // A session cookie without a login (the web stack sets one on every response) is harmless.
    expect($exempt($bare))->toBeTrue()
        ->and($exempt($strayCookie))->toBeTrue()
        ->and($exempt($browser))->toBeFalse();

    // Once the session authenticates a client, a bearer header no longer lifts the CSRF check.
    Auth::guard('client')->setUser(Client::factory()->synced()->create());
    expect($exempt($strayCookie))->toBeFalse();
    Auth::guard('client')->forgetUser();
});
it('accepts an api key from the query string for the call center scope only and audits rejections', function (): void {
    ['plain' => $mobile] = ApiKey::generate('app', ApiKeyScope::MobileBackend);
    Client::factory()->synced()->create(['email' => 'app@x.hu']);

    $this->getJson('/api/v1/mobile/ivr-code?email=app@x.hu&api_key='.$mobile)->assertUnauthorized();
    $this->withHeaders(['X-Api-Key' => $mobile])->getJson('/api/v1/mobile/ivr-code?email=app@x.hu')->assertOk();

    expect(AuditLog::query()->where('event', 'api_key.rejected')->value('context'))->toMatchArray(['scope' => 'mobile_backend', 'reason' => 'missing'])
        ->and(AuditLog::query()->where('event', 'client.mobile_otp_issued')->value('context')['api_key'])->toStartWith('key:');
});

it('enforces the signature of a managed key once a signing secret is issued', function (): void {
    config()->set('hdid.callcenter.api_key', null);
    ['plain' => $plain, 'key' => $key] = ApiKey::generate('ivr', ApiKeyScope::CallCenter);
    $body = json_encode(['call_id' => 'c-4', 'caller_number' => null, 'status' => 'ringing']);

    $this->withHeaders(['X-Api-Key' => $plain])->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-3', 'caller_number' => null, 'status' => 'ringing'])->assertCreated();

    $secret = $key->rotateSigningSecret();
    expect($key->fresh()->requiresSignature())->toBeTrue()->and(DB::table('api_keys')->value('hmac_secret'))->not->toBe($secret);

    $this->withHeaders(['X-Api-Key' => $plain])->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-5', 'caller_number' => null, 'status' => 'ringing'])->assertUnauthorized();

    $ts = time();
    $this->call('POST', '/api/v1/callcenter/calls', [], [], [], [
        'HTTP_X_API_KEY' => $plain, 'HTTP_X_TIMESTAMP' => $ts, 'HTTP_X_SIGNATURE' => hash_hmac('sha256', AuthenticateApiKey::signedPayload($ts, 'POST', '/api/v1/callcenter/calls', $body), $secret), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], $body)->assertCreated();

    $key->fresh()->removeSigningSecret();
    $this->withHeaders(['X-Api-Key' => $plain])->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-6', 'caller_number' => null, 'status' => 'ringing'])->assertCreated();
    expect(AuditLog::query()->where('event', 'api_key.rejected')->where('subject_id', $key->id)->count())->toBe(1);
});

it('limits ivr checks per call and keys the limiter on the api key', function (): void {
    config()->set('hdid.callcenter.api_key', 'key-123');
    config()->set('hdid.callcenter.hmac_secret', null);

    foreach (range(1, 10) as $i) {
        $this->withHeaders(['X-Api-Key' => 'key-123'])->postJson('/api/v1/callcenter/ivr/verify-code', ['code' => '11111111', 'call_id' => 'c-1'])->assertOk();
    }
    $this->withHeaders(['X-Api-Key' => 'key-123'])->postJson('/api/v1/callcenter/ivr/verify-code', ['code' => '11111111', 'call_id' => 'c-1'])->assertStatus(429);
    $this->withHeaders(['X-Api-Key' => 'key-123'])->postJson('/api/v1/callcenter/ivr/verify-code', ['code' => '11111111', 'call_id' => 'c-2'])->assertOk();
    expect(RateLimiter::attempts(md5('ivr'.'ivr:legacy')))->toBe(11)
        ->and(RateLimiter::attempts(md5('ivr'.'ivr:ip:127.0.0.1')))->toBe(0);
});

it('gives every route-level rate limit its own bucket', function (): void {
    $buckets = collect(app('router')->getRoutes())
        // Vendor routes (Livewire's upload endpoint) bring their own limits.
        ->reject(fn ($route) => str_starts_with((string) $route->getName(), 'livewire.'))
        ->flatMap(fn ($route) => collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'))->all())
        ->unique()
        ->values();

    // A bare "throttle:n,m" shares one counter per IP across every route
    // that uses it, so the strictest limit would swallow the others.
    $shared = $buckets->filter(fn (string $m) => preg_match('/^throttle:\d+,\d+$/', $m) === 1);

    expect($shared->all())->toBe([]);
});

it('throttles the client pin endpoints', function (): void {
    $pin = collect(app('router')->getRoutes())->first(fn ($route) => $route->getName() === 'api.v1.client.pin.store');

    expect(collect($pin->gatherMiddleware())->contains('throttle:10,1,pin-change'))->toBeTrue();
});
