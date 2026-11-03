<?php

use App\Models\AuditLog;
use Illuminate\Support\Facades\RateLimiter;

/*
 * A key sent as an array (or another non-text value) is refused as
 * malformed and counted against the address ceiling of the limiter, without
 * a server error. This file keeps the rate limiters on (InputLengthEndpointsTest
 * switches them off), so the limiter callbacks really run.
 */

beforeEach(function (): void {
    config()->set('hdid.callcenter.api_key', null);
});

it('answers 401 to an array api key on every machine route and counts it against the address ceiling', function (string $method, string $uri, array $json, string $limiter, string $reason): void {
    $response = $method === 'GET' ? $this->getJson($uri) : $this->postJson($uri, $json);

    $response->assertUnauthorized();
    expect(AuditLog::query()->where('event', 'api_key.rejected')->sole()->context['reason'])->toBe($reason)
        ->and(RateLimiter::attempts(md5($limiter.'machine-ip:127.0.0.1')))->toBe(1);
})->with([
    'a call lookup' => ['GET', '/api/v1/callcenter/calls/x?api_key[]=a', [], 'machine', 'malformed'],
    'a call event' => ['POST', '/api/v1/callcenter/calls', ['api_key' => ['a']], 'machine', 'malformed'],
    'the phone menu lookup' => ['GET', '/api/v1/callcenter/lookup?api_key[]=a', [], 'ivr', 'malformed'],
    'the mobile backend' => ['POST', '/api/v1/mobile/ivr-code?api_key[]=a', [], 'api-key', 'missing'],
]);

it('answers 401 malformed to a JSON api key that is a float or a boolean', function (mixed $key): void {
    $this->postJson('/api/v1/callcenter/calls', ['api_key' => $key, 'call_id' => 'c-1', 'status' => 'ringing'])->assertUnauthorized();

    expect(AuditLog::query()->where('event', 'api_key.rejected')->sole()->context['reason'])->toBe('malformed');
})->with(['a float' => [1.5], 'a boolean' => [true]]);
