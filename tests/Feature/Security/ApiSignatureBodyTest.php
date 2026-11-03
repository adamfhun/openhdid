<?php

use App\Enums\ApiKeyScope;
use App\Enums\PhoneNumberSource;
use App\Http\Middleware\AuthenticateApiKey;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\IdSession;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;

/*
 * A signed machine request covers its raw body. A multipart/form-data body
 * is parsed into fields while the raw body the signature covers is empty,
 * so its fields would ride along unsigned: such a request is refused.
 */

beforeEach(function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    config()->set('hdid.callcenter.api_key', null);
});

/**
 * @param  array<string, string>  $fields
 */
function signedMultipart(string $key, string $secret, string $uri, array $fields, ?string $signature = null, ?int $timestamp = null): TestResponse
{
    $timestamp ??= time();
    $signature ??= hash_hmac('sha256', AuthenticateApiKey::signedPayload($timestamp, 'POST', $uri, ''), $secret);

    return test()->call('POST', $uri, $fields, [], [], [
        'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_API_KEY' => $key,
        'HTTP_X_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_SIGNATURE' => $signature,
    ]);
}

/**
 * A recognised call from a client with a PIN, so a PIN check would really run.
 */
function callOfClientWithPin(string $key): void
{
    $client = Client::factory()->synced()->withPin('123456')->create();
    $client->phoneNumbers()->create(['number_e164' => '+36301234500', 'source' => PhoneNumberSource::Sync, 'is_primary' => true]);
    test()->withHeaders(['X-Api-Key' => $key])->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-b', 'caller_number' => '+36301234500', 'status' => 'active'])->assertSuccessful();
    test()->flushHeaders();
}

it('refuses a signed multipart request whose fields the signature does not cover', function (): void {
    ['plain' => $plain, 'key' => $key] = ApiKey::generate('ivr', ApiKeyScope::CallCenter);
    callOfClientWithPin($plain);
    $secret = $key->rotateSigningSecret();

    signedMultipart($plain, $secret, '/api/v1/callcenter/ivr/verify-pin', ['call_id' => 'c-b', 'pin' => '123456'])->assertUnauthorized();

    expect(AuditLog::query()->where('event', 'api_key.rejected')->sole()->context['reason'])->toBe('unsigned_body')
        ->and(IdSession::query()->exists())->toBeFalse();
});

it('refuses a signed multipart request with the legacy static key and its signing secret too', function (): void {
    config()->set('hdid.callcenter.api_key', 'legacy-key');
    config()->set('hdid.callcenter.hmac_secret', 'legacy-secret');

    signedMultipart('legacy-key', 'legacy-secret', '/api/v1/callcenter/ivr/verify-pin', ['call_id' => 'c-b', 'pin' => '123456'])->assertUnauthorized();

    expect(AuditLog::query()->where('event', 'api_key.rejected')->sole()->context['reason'])->toBe('unsigned_body');
});

it('refuses a signed multipart request of the mobile backend key', function (): void {
    ['plain' => $plain, 'secret' => $secret] = ApiKey::generate('app', ApiKeyScope::MobileBackend);
    Client::factory()->synced()->create(['email' => 'c@x.hu']);

    signedMultipart($plain, $secret, '/api/v1/mobile/ivr-code', ['email' => 'c@x.hu'])->assertUnauthorized();

    expect(AuditLog::query()->where('event', 'api_key.rejected')->sole()->context['reason'])->toBe('unsigned_body');
});

it('does not use up a captured signature when it is replayed as multipart', function (): void {
    ['plain' => $plain, 'secret' => $secret] = ApiKey::generate('app', ApiKeyScope::MobileBackend);
    Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $timestamp = time();
    $uri = '/api/v1/mobile/ivr-code?email=c%40x.hu';
    $signature = hash_hmac('sha256', AuthenticateApiKey::signedPayload($timestamp, 'POST', $uri, ''), $secret);

    signedMultipart($plain, $secret, $uri, ['email' => 'c@x.hu'], $signature, $timestamp)->assertUnauthorized();

    $this->call('POST', $uri, [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_API_KEY' => $plain,
        'HTTP_X_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_SIGNATURE' => $signature,
    ])->assertOk();
});

it('still accepts a signed JSON and a signed form-urlencoded request', function (): void {
    ['plain' => $plain, 'secret' => $secret] = ApiKey::generate('app', ApiKeyScope::MobileBackend);
    Client::factory()->synced()->create(['email' => 'c@x.hu']);

    signedMachineCall($plain, $secret, 'POST', '/api/v1/mobile/ivr-code', ['email' => 'c@x.hu'])->assertOk();

    $timestamp = time() + 1;
    $body = 'email=c%40x.hu';
    $this->call('POST', '/api/v1/mobile/ivr-code', ['email' => 'c@x.hu'], [], [], [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_API_KEY' => $plain,
        'HTTP_X_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', AuthenticateApiKey::signedPayload($timestamp, 'POST', '/api/v1/mobile/ivr-code', $body), $secret),
    ], $body)->assertOk();
});

it('keeps accepting an unsigned multipart request of a key without a signing secret', function (): void {
    ['plain' => $plain] = ApiKey::generate('ivr', ApiKeyScope::CallCenter);

    $this->call('POST', '/api/v1/callcenter/ivr/verify-pin', ['call_id' => 'c-x', 'pin' => '123456'], [], [], [
        'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_API_KEY' => $plain,
    ])->assertOk();
});
