<?php

use App\Auth\Oidc\OidcClient;
use App\Auth\Oidc\OidcException;
use App\Auth\Oidc\OidcProvider;
use App\Enums\PrincipalType;
use App\Models\AuditLog;
use App\Models\Client;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeOidc;

beforeEach(function (): void {
    FakeOidc::reset();
    config()->set('hdid.oidc.client.entra', ['tenant' => '11111111-1111-4111-8111-111111111111', 'client_id' => 'web-app', 'client_secret' => 's', 'mobile_client_id' => 'mobile-app']);
    config()->set('hdid.oidc.client.adfs', ['issuer' => FakeOidc::ISSUER, 'client_id' => 'client-app', 'client_secret' => 's']);
    app(Settings::class)->set(SettingKey::ClientSsoEntraEnabled, true);
    app(Settings::class)->set(SettingKey::ClientSsoAdfsEnabled, true);
});

afterEach(fn () => FakeOidc::reset());

it('refuses an oversized mobile ID token as invalid input before any decoding', function (): void {
    Http::fake();

    // Dots only: the shape that made the JWT library split it into millions of segments.
    $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => str_repeat('.', OidcClient::MAX_TOKEN_LENGTH + 1), 'device_name' => 'iPhone'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['id_token' => 'must not be greater than '.OidcClient::MAX_TOKEN_LENGTH.' characters']);

    Http::assertNothingSent();
    expect(AuditLog::query()->where('event', 'login.rejected')->count())->toBe(0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('still hands a mobile ID token of exactly the maximum length to the validator', function (): void {
    FakeOidc::fake([], 'https://login.microsoftonline.com/11111111-1111-4111-8111-111111111111/v2.0', 'https://login.microsoftonline.com/11111111-1111-4111-8111-111111111111/v2.0/.well-known/openid-configuration');

    $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => str_repeat('a', OidcClient::MAX_TOKEN_LENGTH), 'device_name' => 'iPhone'])
        ->assertUnprocessable()->assertJsonPath('errors.id_token.0', 'The token is not valid.');

    expect(AuditLog::query()->where('event', 'login.rejected')->sole()->context)
        ->toMatchArray(['method' => 'sso.entra.mobile', 'detail' => 'invalid_token'])
        ->and(AuditLog::query()->where('event', 'login.rejected')->sole()->context['error'])->toStartWith('ID token invalid: ');
});

it('refuses an oversized ID token from the token endpoint of a browser sign-in, even when it is validly signed', function (): void {
    Client::factory()->synced()->create(['email' => 'c@x.hu']);
    // A correctly signed token for the right audience, padded beyond the limit.
    FakeOidc::fake(['aud' => 'client-app', 'email' => 'c@x.hu', 'padding' => str_repeat('x', OidcClient::MAX_TOKEN_LENGTH)]);

    $redirect = $this->get('/auth/client/adfs/redirect');
    parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
    FakeOidc::$nonce = session('oidc.pending')['nonce'];

    $this->get('/auth/client/adfs/callback?code=abc&state='.$query['state'])
        ->assertRedirect('/login?error=invalid_credentials');

    $this->assertGuest('client');
    expect(AuditLog::query()->where('event', 'login.rejected')->sole()->context)
        ->toMatchArray(['detail' => 'invalid_token', 'error' => 'ID token invalid: Token is longer than '.OidcClient::MAX_TOKEN_LENGTH.' bytes.']);
});

it('refuses an oversized token in the client before fetching keys or splitting it', function (): void {
    FakeOidc::fake([], 'https://login.microsoftonline.com/11111111-1111-4111-8111-111111111111/v2.0', 'https://login.microsoftonline.com/11111111-1111-4111-8111-111111111111/v2.0/.well-known/openid-configuration');
    $config = OidcProvider::Entra->configFor(PrincipalType::Client);

    expect(fn () => app(OidcClient::class)->validateIdToken($config, str_repeat('.', 1024 * 1024), 'mobile-app'))
        ->toThrow(OidcException::class, 'ID token invalid: Token is longer than '.OidcClient::MAX_TOKEN_LENGTH.' bytes.');

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://idp.test/jwks');
});

it('fetches the key set again only for an unknown key id, and at most once a minute', function (): void {
    FakeOidc::fake([], 'https://login.microsoftonline.com/11111111-1111-4111-8111-111111111111/v2.0', 'https://login.microsoftonline.com/11111111-1111-4111-8111-111111111111/v2.0/.well-known/openid-configuration');
    $forged = fn (string $kid): string => rtrim(strtr(base64_encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid])), '+/', '-_'), '=')
        .'.'.rtrim(strtr(base64_encode('{"sub":"x"}'), '+/', '-_'), '=').'.c2lnbmF0dXJl';
    $jwksRequests = fn (): int => Http::recorded(fn (Request $request): bool => $request->url() === 'https://idp.test/jwks')->count();
    $send = fn (string $token) => $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => $token, 'device_name' => 'iPhone'])->assertUnprocessable();

    // The cached set has test-kid: invalid tokens naming it never refresh it.
    $send($forged('test-kid'));
    $send($forged('test-kid'));
    expect($jwksRequests())->toBe(1);

    // An unknown key id refreshes once, the next ones within the minute do not.
    $send($forged('rotated-kid'));
    $send($forged('another-kid'));
    expect($jwksRequests())->toBe(2);
});
