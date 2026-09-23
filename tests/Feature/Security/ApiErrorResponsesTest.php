<?php

use App\Models\Client;
use Illuminate\Session\TokenMismatchException;
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
