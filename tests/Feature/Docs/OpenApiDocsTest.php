<?php

use App\Auth\Role;
use App\Enums\ApiKeyScope;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\User;
use App\Support\ApiSignatureDocs;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('serves three openapi documents to administrators only', function (): void {
    $this->get('/docs/api.json')->assertForbidden();

    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    $full = $this->get('/docs/api.json')->assertOk()->json();
    $ivr = $this->get('/docs/ivr.json')->assertOk()->json();
    $mobile = $this->get('/docs/mobile.json')->assertOk()->json();

    expect(array_keys($full['paths']))->toContain('/v1/client/mobile-code', '/v1/callcenter/ivr/verify-code', '/v1/mobile/ivr-code', '/v1/auth/client/entra/token')
        ->and(array_keys($ivr['paths']))->toBe(['/calls', '/calls/{callId}', '/calls/{callId}/end', '/lookup', '/ivr/verify-pin', '/ivr/verify-code'])
        ->and(array_keys($mobile['paths']))->toBe(['/ivr-code'])
        ->and($ivr['components']['securitySchemes'])->toHaveKey('apiKey')
        ->and($ivr['paths']['/ivr/verify-code'])->toHaveKeys(['get', 'post']);

    $this->get('/docs/ivr')->assertOk();
    $this->get('/docs/mobile')->assertOk();
});

it('documents the request signing scheme on every machine operation and nowhere else', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    $headerNames = fn (array $operation): array => array_values(array_filter(array_map(
        fn (array $p) => $p['in'] === 'header' ? $p['name'] : null,
        $operation['parameters'] ?? [],
    )));

    foreach (['ivr', 'mobile', 'api'] as $document) {
        $doc = $this->get("/docs/{$document}.json")->assertOk()->json();

        expect($doc['info']['description'])->toContain('Request signing', 'X-Timestamp', 'X-Signature', 'HMAC-SHA256', 'stale_timestamp', 'bad_signature', 'replayed');
    }

    $ivr = $this->get('/docs/ivr.json')->json();
    $mobile = $this->get('/docs/mobile.json')->json();
    $full = $this->get('/docs/api.json')->json();

    foreach ($ivr['paths'] as $operations) {
        foreach ($operations as $operation) {
            expect($headerNames($operation))->toContain('X-Timestamp', 'X-Signature');
        }
    }

    expect($headerNames($mobile['paths']['/ivr-code']['post']))->toContain('X-Timestamp', 'X-Signature')
        ->and($headerNames($full['paths']['/v1/callcenter/ivr/verify-code']['get']))->toContain('X-Timestamp', 'X-Signature')
        ->and($headerNames($full['paths']['/v1/mobile/ivr-code']['post']))->toContain('X-Timestamp', 'X-Signature')
        ->and($headerNames($full['paths']['/v1/client/mobile-code']['post']))->not->toContain('X-Signature')
        ->and($headerNames($full['paths']['/v1/auth/client/entra/token']['post']))->not->toContain('X-Signature');

    // The documented payload is the one the middleware verifies.
    expect(ApiSignatureDocs::payload(1789714410, 'post', '/api/v1/callcenter/calls?x=1', '{"a":1}'))
        ->toBe("1789714410\nPOST\n/api/v1/callcenter/calls?x=1\n{\"a\":1}");
});

it('marks the signature headers required for the mobile backend and conditional for the call center', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    $signatureHeaders = fn (array $operation): array => collect($operation['parameters'] ?? [])
        ->filter(fn (array $p): bool => $p['in'] === 'header' && in_array($p['name'], ['X-Timestamp', 'X-Signature'], true))
        ->mapWithKeys(fn (array $p): array => [$p['name'] => $p['required'] ?? false])->all();

    $mobile = $this->get('/docs/mobile.json')->json();
    $ivr = $this->get('/docs/ivr.json')->json();
    $full = $this->get('/docs/api.json')->json();

    expect($signatureHeaders($mobile['paths']['/ivr-code']['post']))->toBe(['X-Timestamp' => true, 'X-Signature' => true])
        ->and($signatureHeaders($full['paths']['/v1/mobile/ivr-code']['post']))->toBe(['X-Timestamp' => true, 'X-Signature' => true])
        ->and($signatureHeaders($ivr['paths']['/ivr/verify-code']['post']))->toBe(['X-Timestamp' => false, 'X-Signature' => false])
        ->and($signatureHeaders($full['paths']['/v1/callcenter/ivr/verify-code']['post']))->toBe(['X-Timestamp' => false, 'X-Signature' => false]);

    expect($mobile['info']['description'])->toContain(
        'Mobile app backend: always signed', 'signature_required', 'Test vector', ApiSignatureDocs::TEST_SIGNATURE, 'Changing the secret', 'Node.js', 'Java', 'C#',
    );
});

it('documents a test vector that the server-side signing really produces', function (): void {
    $payload = ApiSignatureDocs::payload(ApiSignatureDocs::TEST_TIMESTAMP, ApiSignatureDocs::TEST_METHOD, ApiSignatureDocs::TEST_URI, ApiSignatureDocs::TEST_BODY);

    expect(hash_hmac('sha256', $payload, ApiSignatureDocs::TEST_SECRET))->toBe(ApiSignatureDocs::TEST_SIGNATURE);
});

it('accepts a request signed exactly as the documentation shows', function (): void {
    ['plain' => $key, 'secret' => $secret] = ApiKey::generate('app', ApiKeyScope::MobileBackend);
    Client::factory()->synced()->create(['email' => 'client@example.org']);
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp."\nPOST\n".ApiSignatureDocs::TEST_URI."\n".ApiSignatureDocs::TEST_BODY, $secret);

    $this->call('POST', ApiSignatureDocs::TEST_URI, [], [], [], [
        'HTTP_X_API_KEY' => $key, 'HTTP_X_TIMESTAMP' => $timestamp, 'HTTP_X_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], ApiSignatureDocs::TEST_BODY)->assertOk()->assertJsonStructure(['code', 'formatted', 'expires_at', 'client_id']);
});
