<?php

use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Http\Middleware\StartSessionWhenNeeded;
use App\Http\Middleware\ValidateCsrfTokenUnlessBearer;
use App\Models\Client;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/*
 * G14a: every cookieless request used to leave a session in the shared store
 * (Valkey in the container release), the whole address included, so one
 * address could fill the store within the session lifetime. Sessions are
 * encrypted here, as in the container release (SESSION_ENCRYPT=true).
 */

beforeEach(function (): void {
    Mail::fake();

    $this->sessionWrites = $handler = new class(120) extends ArraySessionHandler
    {
        /** @var array<string, int> stored bytes per session id */
        public array $sizes = [];

        public function write($sessionId, $data): bool
        {
            $this->sizes[$sessionId] = strlen($data);

            return parent::write($sessionId, $data);
        }
    };

    // The manager binds the creator to itself, hence the local variable.
    app('session')->extend('recording', fn () => $handler);
    config()->set(['session.driver' => 'recording', 'session.encrypt' => true]);
    app()->forgetInstance('session.store');
});

/**
 * A request as a new browser process sees it: no session carried over in
 * memory from the previous test request, only what the cookies bring.
 *
 * @param  array<string, string>  $headers
 * @param  array<string, string>  $cookies
 */
function asFreshProcess(string $method, string $uri, array $data = [], array $headers = [], array $cookies = []): TestResponse
{
    app('session')->forgetDrivers();
    Auth::forgetGuards();

    $server = ['HTTP_ACCEPT' => $method === 'GET' ? 'text/html' : 'application/json'];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $content = null;
    if ($method !== 'GET') {
        $server['CONTENT_TYPE'] = 'application/json';
        $content = json_encode($data, JSON_THROW_ON_ERROR);
    }

    // Encrypted as the browser holds them; the test client's own cookie
    // helpers would carry them into every later request.
    $encrypted = [];
    foreach ($cookies as $name => $value) {
        $encrypted[$name] = encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$value, false);
    }

    return test()->call($method, $uri, [], $encrypted, [], $server, $content);
}

/** @return list<string> */
function cookieNames(TestResponse $response): array
{
    return array_map(fn ($cookie): string => $cookie->getName(), $response->headers->getCookies());
}

it('uses the bounded session middleware everywhere the framework one would run', function (): void {
    expect(app(StartSession::class))->toBeInstanceOf(StartSessionWhenNeeded::class);
});

it('stores no session for cookieless visits of the portal, however long the address', function (): void {
    $long = str_repeat('A', 7000);

    foreach (range(1, 25) as $i) {
        $shell = asFreshProcess('GET', "/portal/{$i}?x={$long}")->assertOk();
        expect(cookieNames($shell))->not->toContain(config('session.cookie'))->not->toContain('XSRF-TOKEN');
    }

    asFreshProcess('GET', '/api/v1/client/me?x='.$long, headers: ['Accept' => 'application/json'])->assertUnauthorized();
    asFreshProcess('GET', '/api/v1/branding?x='.$long, headers: ['Accept' => 'application/json', 'Referer' => config('app.url')])->assertOk();
    asFreshProcess('GET', '/locale/en?x='.$long)->assertRedirect();

    expect($this->sessionWrites->sizes)->toBe([]);
});

it('keeps the previous and intended addresses of a guest session short', function (): void {
    $long = str_repeat('A', 7000);

    asFreshProcess('GET', '/admin/clients?x='.$long)->assertRedirect();
    expect(session('url.intended'))->toBe(url('/admin/clients'));

    asFreshProcess('GET', '/sanctum/csrf-cookie?x='.$long)->assertNoContent();
    expect(session()->previousUrl())->toBe(url('/sanctum/csrf-cookie'));

    asFreshProcess('GET', '/admin/login?x='.$long.'/'.$long)->assertOk();
    expect(strlen((string) session()->previousUrl()))->toBeLessThanOrEqual(StartSessionWhenNeeded::MAX_STORED_URL_LENGTH);

    // Three guest sessions, each a few hundred bytes instead of ~14.7 KB.
    expect($this->sessionWrites->sizes)->toHaveCount(3)
        ->and(max($this->sessionWrites->sizes))->toBeLessThan(1024);
});

it('drops a stored address that is too long even without its query string', function (): void {
    // A path longer than the limit: kept neither whole nor shortened.
    asFreshProcess('GET', '/sanctum/csrf-cookie?x=1');
    session()->put('url.intended', url('/'.str_repeat('p', StartSessionWhenNeeded::MAX_STORED_URL_LENGTH)));
    session()->save();

    $id = session()->getId();
    asFreshProcess('GET', '/sanctum/csrf-cookie', cookies: [config('session.cookie') => $id])->assertNoContent();

    expect(session()->has('url.intended'))->toBeFalse();
});

it('still keeps the session where a guest needs one and once someone signs in', function (): void {
    $csrf = asFreshProcess('GET', '/sanctum/csrf-cookie')->assertNoContent();
    expect(cookieNames($csrf))->toContain(config('session.cookie'))->toContain('XSRF-TOKEN');

    asFreshProcess('GET', '/admin/login')->assertOk();
    expect($this->sessionWrites->sizes)->toHaveCount(2);

    $client = Client::factory()->synced()->create();
    $url = app(ClientPasswordlessLogin::class)->issueMagicLink($client);
    app('session')->forgetDrivers();
    $signIn = signInWithLink($url)->assertOk();
    $sessionId = $signIn->getCookie(config('session.cookie'))?->getValue();
    expect($sessionId)->not->toBeNull()
        ->and($this->sessionWrites->sizes)->toHaveKey($sessionId);

    // The signed-in browser comes back with its cookie: the stored session signs it in.
    asFreshProcess('GET', '/api/v1/client/me', headers: ['Accept' => 'application/json'], cookies: [config('session.cookie') => $sessionId])
        ->assertOk()
        ->assertJsonPath('data.id', $client->id);

    // And its visits of the portal shell keep the session alive.
    $before = count($this->sessionWrites->sizes);
    $shell = asFreshProcess('GET', '/overview', cookies: [config('session.cookie') => $sessionId])->assertOk();
    expect(cookieNames($shell))->toContain(config('session.cookie'))
        ->and($this->sessionWrites->sizes)->toHaveCount($before);
});

it('keeps the portal csrf flow working without a stored guest session', function (): void {
    // The real CSRF guard, not the one the framework relaxes in unit tests.
    app()->bind(ValidateCsrfTokenUnlessBearer::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfTokenUnlessBearer
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
    $email = ['email' => 'someone@example.test'];

    // A browser that sends Sec-Fetch-Site (every current one) needs no token at all.
    asFreshProcess('POST', '/api/v1/client/auth/magic-link', $email, ['Sec-Fetch-Site' => 'same-origin'])->assertOk();
    expect($this->sessionWrites->sizes)->toBe([]);

    // An older browser: the token of the unstored page session fails (419),
    // api.js fetches /sanctum/csrf-cookie once and retries with it.
    $page = asFreshProcess('GET', '/login')->assertOk();
    preg_match('/name="csrf-token" content="([^"]*)"/', (string) $page->getContent(), $meta);
    asFreshProcess('POST', '/api/v1/client/auth/magic-link', $email, ['X-CSRF-TOKEN' => $meta[1] ?? ''])->assertStatus(419);

    $csrf = asFreshProcess('GET', '/sanctum/csrf-cookie')->assertNoContent();
    asFreshProcess('POST', '/api/v1/client/auth/magic-link', $email,
        ['X-XSRF-TOKEN' => $csrf->getCookie('XSRF-TOKEN', decrypt: false)->getValue()],
        [config('session.cookie') => $csrf->getCookie(config('session.cookie'))->getValue()],
    )->assertOk();
});
