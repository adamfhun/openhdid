<?php

use App\Models\Client;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Support\HuDate;
use App\System\CheckStatus;
use App\System\HealthChecks;
use App\System\Installation;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeOidc;

/*
 * The provider's key set (JWKS) and discovery document during an outage:
 * the last good copy is kept (up to 24 hours) instead of being dropped
 * before a refresh, a failed download is not retried for 30 seconds, and
 * the status page says when sign-ins run on a stale set.
 */

const OUTAGE_DISCOVERY = 'https://login.microsoftonline.com/11111111-1111-4111-8111-111111111111/v2.0/.well-known/openid-configuration';
const OUTAGE_ISSUER = 'https://login.microsoftonline.com/11111111-1111-4111-8111-111111111111/v2.0';
const OUTAGE_JWKS = 'https://idp.test/jwks';

beforeEach(function (): void {
    config()->set('hdid.oidc.client.entra', ['tenant' => '11111111-1111-4111-8111-111111111111', 'client_id' => 'web-app', 'client_secret' => 's', 'mobile_client_id' => 'mobile-app']);
    app(Settings::class)->set(SettingKey::ClientSsoEntraEnabled, true);
    Client::factory()->synced()->create(['email' => 'c@x.hu']);

    // The endpoints answer from these, so a test can take them down: Http::fake
    // keeps the first stub registered for a URL.
    $this->jwks = 'up';
    $this->discovery = 'up';
    Http::fake([
        OUTAGE_JWKS => fn () => match ($this->jwks) {
            'up' => Http::response(['keys' => [FakeOidc::jwk()]]),
            'html' => Http::response('<html>maintenance</html>', 200),
            default => Http::failedConnection(),
        },
        OUTAGE_DISCOVERY => fn () => $this->discovery === 'up'
            ? Http::response(['issuer' => OUTAGE_ISSUER, 'authorization_endpoint' => 'https://idp.test/authorize', 'token_endpoint' => 'https://idp.test/token', 'jwks_uri' => OUTAGE_JWKS])
            : Http::failedConnection(),
    ]);
});

afterEach(function (): void {
    FakeOidc::reset();
    JWT::$timestamp = null;
});

function outageExchange(?string $token = null): TestResponse
{
    static $iat = 0;
    // The tests travel in time: the token and its check follow the test clock.
    JWT::$timestamp = now()->getTimestamp();
    $token ??= FakeOidc::idToken(['aud' => 'mobile-app', 'tid' => '11111111-1111-4111-8111-111111111111', 'preferred_username' => 'c@x.hu', 'iat' => now()->getTimestamp() + $iat++, 'exp' => now()->getTimestamp() + 600], OUTAGE_ISSUER);

    return test()->postJson('/api/v1/auth/client/entra/token', ['id_token' => $token, 'device_name' => 'phone']);
}

/** A token signed with a key the provider never published, under an unknown key id. */
function outageForeignToken(string $kid = 'rotated-kid', string $alg = 'RS256'): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    $claims = ['iss' => OUTAGE_ISSUER, 'aud' => 'mobile-app', 'tid' => '11111111-1111-4111-8111-111111111111', 'preferred_username' => 'c@x.hu', 'oid' => 'oid-c', 'iat' => now()->getTimestamp(), 'exp' => now()->getTimestamp() + 600];

    return $alg === 'RS256' ? JWT::encode($claims, $private, 'RS256', $kid) : JWT::encode($claims, 'secret-secret-secret-secret-secret', $alg, $kid);
}

function jwksRequests(): int
{
    return Http::recorded(fn ($request) => $request->url() === OUTAGE_JWKS)->count();
}

it('keeps the cached key set when a refresh for an unknown key id fails', function (): void {
    outageExchange()->assertOk();
    $this->jwks = 'down';

    outageExchange(outageForeignToken())->assertServiceUnavailable()->assertJsonPath('reason', 'provider_unavailable');
    outageExchange()->assertOk();
});

it('asks the key set endpoint once while it is down instead of on every sign-in', function (): void {
    $this->jwks = 'down';

    foreach (range(1, 3) as $i) {
        outageExchange()->assertServiceUnavailable()->assertJsonPath('reason', 'provider_unavailable');
    }

    expect(jwksRequests())->toBe(1);

    $this->travel(31)->seconds();
    outageExchange()->assertServiceUnavailable();
    expect(jwksRequests())->toBe(2);
});

it('does not replace a usable key set with an unusable document', function (): void {
    outageExchange()->assertOk();
    $this->jwks = 'html';

    outageExchange(outageForeignToken())->assertUnprocessable();
    outageExchange()->assertOk();
});

it('signs in with the last good key set after it went stale while the provider is down, and the status page says so', function (): void {
    outageExchange()->assertOk();
    $this->travel(2)->hours();
    $this->jwks = 'down';
    $this->discovery = 'down';
    Log::spy();

    outageExchange()->assertOk();
    outageExchange()->assertOk();

    $check = app(HealthChecks::class)->oidc();
    expect($check->status)->toBe(CheckStatus::Warn)
        ->and($check->detail)->toContain(__('the key set could not be refreshed since :time; sign-ins use the last good set until :until', [
            'time' => now()->subHours(2)->format(HuDate::DATETIME),
            'until' => now()->subHours(2)->addDay()->format(HuDate::DATETIME),
        ]));
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'last good'))->atLeast()->once();
});

it('stops serving the key set a day after the last good download', function (): void {
    outageExchange()->assertOk();
    $this->travel(25)->hours();
    $this->jwks = 'down';
    $this->discovery = 'down';

    outageExchange()->assertServiceUnavailable();
});

it('fetches the key set on a cold cache even while another request holds the refresh lock', function (): void {
    Cache::lock(Installation::lockName('oidc-jwks:'.md5(OUTAGE_JWKS)), 10)->get();

    outageExchange()->assertOk();

    expect(jwksRequests())->toBe(1);
});

it('signs in with the stale key set without fetching while another request refreshes it', function (): void {
    outageExchange()->assertOk();
    $this->travel(2)->hours();
    Cache::lock(Installation::lockName('oidc-jwks:'.md5(OUTAGE_JWKS)), 10)->get();

    outageExchange()->assertOk();

    expect(jwksRequests())->toBe(1);
});

it('accepts only an RS256 header with three segments as a reason to refresh the key set', function (): void {
    outageExchange()->assertOk();

    outageExchange(outageForeignToken('hs-kid', 'HS256'))->assertUnprocessable();
    outageExchange(outageForeignToken().'.extra')->assertUnprocessable();

    expect(jwksRequests())->toBe(1);
});

it('says on the status page when a key set refresh failed recently', function (): void {
    outageExchange()->assertOk();
    $this->jwks = 'down';
    outageExchange(outageForeignToken())->assertServiceUnavailable();

    expect(app(HealthChecks::class)->oidc()->status)->toBe(CheckStatus::Warn);
});
