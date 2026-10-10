<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Portal access is package-gated; factories give clients the "Premium" package.
        app(Settings::class)->set(SettingKey::PackagesPremium, ['Premium']);
        app(Settings::class)->set(SettingKey::PackagesStandard, ['Basic']);
    })
    ->in('Feature');

/**
 * Press the button on the login link's landing page: the token sits in the
 * link's fragment and is posted, as the portal does.
 */
function signInWithLink(string $url): TestResponse
{
    preg_match('/#token=([A-Za-z0-9]+)$/', $url, $match);

    return test()->postJson('/api/v1/client/auth/magic-link/consume', ['token' => $match[1] ?? '']);
}

/**
 * A machine request signed the way the partner documentation describes:
 * a JSON body for POST, the data in the query string for GET.
 *
 * @param  array<string, mixed>  $data
 */
function signedMachineCall(string $key, string $secret, string $method, string $uri, array $data = []): TestResponse
{
    $method = strtoupper($method);
    $body = $method === 'GET' ? '' : json_encode($data, JSON_THROW_ON_ERROR);

    if ($method === 'GET' && $data !== []) {
        $uri .= (str_contains($uri, '?') ? '&' : '?').http_build_query($data);
    }

    $timestamp = time();

    return test()->call($method, $uri, [], [], [], [
        'HTTP_X_API_KEY' => $key,
        'HTTP_X_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', AuthenticateApiKey::signedPayload($timestamp, $method, $uri, $body), $secret),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $body);
}
