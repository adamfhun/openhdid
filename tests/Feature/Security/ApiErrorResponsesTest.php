<?php

use App\Models\Client;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    config()->set('app.debug', true);
});

it('returns only the public message for every api client error even in debug mode', function (int $status): void {
    Route::get('/api/test-client-error', fn () => throw new HttpException($status, 'Request rejected.', headers: ['Retry-After' => '30']));

    $this->get('/api/test-client-error')
        ->assertStatus($status)
        ->assertHeader('Retry-After', '30')
        ->assertExactJson(['message' => 'Request rejected.']);
})->with(range(400, 499));

it('does not disclose a trace for invalid ivr or mobile api keys', function (string $path, string $accept): void {
    $this->withHeaders(['X-Api-Key' => 'invalid-key', 'Accept' => $accept])
        ->post($path)
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Invalid API key.']);
})->with([
    ['/api/v1/callcenter/ivr/verify-pin', 'application/json'],
    ['/api/v1/callcenter/ivr/verify-code', 'text/html'],
    ['/api/v1/mobile/ivr-code', 'application/json'],
]);

it('preserves api validation errors without debug metadata', function (): void {
    config()->set('hdid.callcenter.api_key', 'valid-key');
    config()->set('hdid.callcenter.hmac_secret', null);

    $response = $this->withHeader('X-Api-Key', 'valid-key')
        ->postJson('/api/v1/callcenter/ivr/verify-pin', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['call_id', 'pin']);

    expect(array_keys($response->json()))->toBe(['message', 'errors']);
});

it('keeps structured account rejections without debug metadata', function (): void {
    Sanctum::actingAs(Client::factory()->synced()->closed()->create(), guard: 'client');

    $response = $this->getJson('/api/v1/client/me')->assertForbidden()
        ->assertJsonPath('reason', 'account_closed');

    expect(array_keys($response->json()))->toBe(['message', 'reason']);
});

it('returns clean json for unknown api routes and unsupported methods', function (): void {
    $missing = $this->get('/api/route-that-does-not-exist')->assertNotFound();
    expect(array_keys($missing->json()))->toBe(['message']);

    $method = $this->post('/api/v1/branding')->assertStatus(405)->assertHeader('Allow', 'GET, HEAD');
    expect(array_keys($method->json()))->toBe(['message']);
});

it('returns a clean csrf error for the client api web stack', function (): void {
    Route::post('/api/test-expired-session', fn () => throw new TokenMismatchException('CSRF token mismatch.'));

    $this->post('/api/test-expired-session')->assertStatus(419)
        ->assertExactJson(['message' => 'CSRF token mismatch.']);
});

it('answers an anonymous request to the client API without a JSON accept header with 401, not a server error', function (string $path): void {
    Exceptions::fake();

    $this->get($path)
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.'])
        ->assertHeaderMissing('Location');

    Exceptions::assertNothingReported();
})->with(['/api/v1/client/me', '/api/v1/client/questions', '/api/v1/client/news']);

it('sends a guest who posts the call-view switch to the staff login page', function (): void {
    Exceptions::fake();

    $this->post(route('tier.switch', 'premium'))->assertRedirect(route('filament.admin.auth.login'));

    Exceptions::assertNothingReported();
});

it('still sends a guest of the panel to the panel login page', function (): void {
    $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
});

it('answers an unexpected api server error with a neutral message even in debug mode', function (): void {
    Route::get('/api/test-server-error', fn () => throw new RuntimeException('SQLSTATE secret '.base_path()));

    $this->get('/api/test-server-error')
        ->assertStatus(500)
        ->assertExactJson(['message' => 'Server Error']);
});

it('still reports an unexpected server error to the log', function (): void {
    Exceptions::fake();
    Route::get('/api/test-server-error', fn () => throw new RuntimeException('SQLSTATE secret'));

    $this->get('/api/test-server-error')->assertStatus(500);

    Exceptions::assertReported(RuntimeException::class);
});

it('keeps only the public message and headers of every api server http error in debug mode', function (int $status): void {
    Route::get('/api/test-server-http-error', fn () => throw new HttpException($status, 'Request rejected.', headers: ['Retry-After' => '30']));

    $this->get('/api/test-server-http-error')
        ->assertStatus($status)
        ->assertHeader('Retry-After', '30')
        ->assertExactJson(['message' => 'Request rejected.']);
})->with(range(500, 599));

it('keeps the message of a maintenance-mode answer in debug mode', function (): void {
    Route::get('/api/test-maintenance', fn () => throw new HttpException(503, 'Service Unavailable', headers: ['Retry-After' => '60']));

    $this->get('/api/test-maintenance')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '60')
        ->assertExactJson(['message' => 'Service Unavailable']);
});

it('answers a json server error on a web route without debug metadata', function (): void {
    // Under a prefix the portal's catch-all route leaves alone.
    Route::middleware('web')->get('/auth/test-web-server-error', fn () => throw new RuntimeException('boom '.base_path()));

    $this->getJson('/auth/test-web-server-error')
        ->assertStatus(500)
        ->assertExactJson(['message' => 'Server Error']);
});

it('answers a json client error outside the api without debug metadata in debug mode', function (): void {
    $response = $this->getJson('/admin/does-not-exist')->assertNotFound();

    expect(array_keys($response->json()))->toBe(['message']);
});

it('keeps the model class out of a json 404 on a web route', function (): void {
    Route::middleware('web')->get('/auth/test-web-model', fn () => Client::query()->findOrFail('00000000-0000-0000-0000-000000000000'));

    $this->getJson('/auth/test-web-model')
        ->assertNotFound()
        ->assertExactJson(['message' => __('Not found.')]);
});

it('switches PHP\'s own error display off before anything else runs in the web entry point', function (): void {
    $lines = array_values(array_filter(array_map('trim', file(public_path('index.php'))), fn (string $line): bool => $line !== '' && ! str_starts_with($line, '//') && $line !== '<?php' && ! str_starts_with($line, 'use ')));

    expect($lines[0])->toBe("ini_set('display_errors', '0');");
});
