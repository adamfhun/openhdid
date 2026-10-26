<?php

use App\Auth\LoginRejection;
use App\Auth\Oidc\OidcClient;
use App\Auth\Oidc\OidcProvider;
use App\Enums\PrincipalType;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\SsoIdentity;
use App\Settings\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeOidc;

/**
 * What the browser brings back from the provider and what the signed token
 * names are bounded too: an authorization code longer than any token is
 * refused before it is sent on, and a subject longer than its column is a
 * refused sign-in with the reason in the audit log, not a database error.
 */
beforeEach(function (): void {
    FakeOidc::reset();
    config()->set('hdid.oidc.client.adfs', ['issuer' => FakeOidc::ISSUER, 'client_id' => 'client-app', 'client_secret' => 's']);
    app(Settings::class)->set(OidcProvider::Adfs->enabledSetting(PrincipalType::Client), true);
});

afterEach(fn () => FakeOidc::reset());

/**
 * @param  array<string, mixed>  $claims
 */
function clientAdfsCallback(array $claims, string $code = 'abc'): TestResponse
{
    FakeOidc::fake($claims + ['aud' => 'client-app'], FakeOidc::ISSUER, OidcProvider::Adfs->configFor(PrincipalType::Client)->discoveryUrl);

    test()->get('/auth/client/adfs/redirect')->assertRedirect();
    FakeOidc::$nonce = session('oidc.pending.nonce');

    return test()->get('/auth/client/adfs/callback?code='.$code.'&state='.session('oidc.pending.state'));
}

it('refuses an authorization code longer than any token before it reaches the provider', function (): void {
    Client::factory()->synced()->create(['email' => 'c@x.hu']);

    clientAdfsCallback(['email' => 'c@x.hu'], str_repeat('c', OidcClient::MAX_TOKEN_LENGTH + 1))
        ->assertRedirect('/login?error='.LoginRejection::InvalidCredentials->value);

    $entry = AuditLog::query()->where('event', 'login.rejected')->sole();
    expect($entry->context['detail'])->toBe('code_too_long')
        ->and(AuditLog::query()->where('context', 'like', '%'.str_repeat('c', 100).'%')->exists())->toBeFalse();
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/token'));
});

it('refuses a sign-in whose subject is longer than the binding can hold and names the claim', function (): void {
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);

    clientAdfsCallback(['email' => 'c@x.hu', 'sub' => str_repeat('s', 256)])
        ->assertRedirect('/login?error='.LoginRejection::InvalidCredentials->value);

    $entry = AuditLog::query()->where('event', 'login.rejected')->sole();
    expect($entry->context)->toMatchArray(['detail' => 'claim_too_long', 'claim' => 'sub', 'reason' => LoginRejection::InvalidCredentials->value])
        ->and(SsoIdentity::query()->count())->toBe(0)
        ->and(auth('client')->check())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'login.succeeded')->where('subject_id', $client->id)->exists())->toBeFalse();
});

it('binds a subject of exactly the column length', function (): void {
    Client::factory()->synced()->create(['email' => 'c@x.hu']);

    clientAdfsCallback(['email' => 'c@x.hu', 'sub' => str_repeat('s', 255)])->assertRedirect();

    expect(SsoIdentity::query()->sole()->subject)->toBe(str_repeat('s', 255));
});

it('treats a state or code sent as a list as a forged return, not a server error', function (string $query): void {
    FakeOidc::fake(['email' => 'c@x.hu', 'aud' => 'client-app'], FakeOidc::ISSUER, OidcProvider::Adfs->configFor(PrincipalType::Client)->discoveryUrl);
    $this->get('/auth/client/adfs/redirect')->assertRedirect();

    $this->get('/auth/client/adfs/callback?'.$query)
        ->assertRedirect('/login?error='.LoginRejection::InvalidCredentials->value);

    expect(AuditLog::query()->where('event', 'login.rejected')->sole()->context['detail'])->toBe('state_or_code');
})->with(['state' => 'code=abc&state[]=x', 'code' => 'state=x&code[]=abc']);
