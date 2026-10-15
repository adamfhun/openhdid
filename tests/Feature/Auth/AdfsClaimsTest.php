<?php

use App\Auth\Oidc\EmailClaimMatch;
use App\Auth\Oidc\OidcProvider;
use App\Auth\Oidc\OidcProviderConfig;
use App\Auth\Oidc\OidcTokens;
use App\Auth\Role;
use App\Enums\PrincipalType;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\System\CheckStatus;
use App\System\HealthChecks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeOidc;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    FakeOidc::reset();
    adfsConfig('user');
    adfsConfig('client');
    app(Settings::class)->set(SettingKey::UserSsoAdfsEnabled, true);
    app(Settings::class)->set(SettingKey::ClientSsoAdfsEnabled, true);
});

afterEach(fn () => FakeOidc::reset());

/**
 * @param  array<string, mixed>  $extra
 */
function adfsConfig(string $principal, array $extra = []): void
{
    config()->set("hdid.oidc.{$principal}.adfs", $extra + ['issuer' => FakeOidc::ISSUER, 'client_id' => "{$principal}-app", 'client_secret' => 's']);
}

/**
 * Drive an ADFS sign-in whose ID token carries these claims (the audience is added).
 *
 * @param  array<string, mixed>  $claims
 */
function adfsSignIn(string $principal, array $claims, string $query = ''): TestResponse
{
    FakeOidc::fake($claims + ['aud' => "{$principal}-app"]);

    $redirect = test()->get("/auth/{$principal}/adfs/redirect");
    parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $authorize);
    FakeOidc::$nonce = session('oidc.pending.nonce');

    return test()->get("/auth/{$principal}/adfs/callback?".($query !== '' ? $query : 'code=abc').'&state='.($authorize['state'] ?? ''));
}

function staffAgent(string $email = 'agent@corp.hu'): User
{
    return User::factory()->synced()->withRole(Role::Agent)->create(['email' => $email]);
}

it('binds an ADFS sign-in by the emailaddress claim by default and names the claim in the audit', function (): void {
    $user = staffAgent();

    adfsSignIn('user', ['upn' => 'agent@corp.local', 'emailaddress' => 'Agent@corp.hu'])->assertRedirect('/admin');

    $this->assertAuthenticatedAs($user, 'web');
    expect(AuditLog::query()->where('event', 'login.succeeded')->sole()->context)
        ->toMatchArray(['method' => 'sso.adfs', 'email_claim' => 'emailaddress', 'email_token' => 'id_token', 'access_token' => 'not_a_jwt'])
        ->not->toHaveKey('claims_passed_over');
});

it('matches a configured claim name against a claim-type URI ending in it', function (): void {
    $user = staffAgent();
    $uri = 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress';

    adfsSignIn('user', [$uri => 'agent@corp.hu', 'upn' => 'agent@corp.local'])->assertRedirect('/admin');

    $this->assertAuthenticatedAs($user, 'web');
    expect(AuditLog::query()->where('event', 'login.succeeded')->sole()->context['email_claim'])->toBe($uri);
});

it('reads the issuance-rule claims from a signed access token issued to this client', function (): void {
    $user = staffAgent();
    FakeOidc::$accessClaims = ['aud' => 'urn:hdid', 'appid' => 'user-app', 'emailaddress' => 'agent@corp.hu'];

    adfsSignIn('user', ['upn' => 'agent@corp.local'])->assertRedirect('/admin');

    $this->assertAuthenticatedAs($user, 'web');
    expect(AuditLog::query()->where('event', 'login.succeeded')->sole()->context)
        ->toMatchArray(['email_claim' => 'emailaddress', 'email_token' => 'access_token', 'access_token' => 'read']);
});

it('leaves out an access token that is not this client\'s or not this ADFS\'s, and says why', function (array $accessClaims, string $verdict): void {
    staffAgent();
    FakeOidc::$accessClaims = $accessClaims + ['aud' => 'urn:hdid', 'emailaddress' => 'agent@corp.hu'];

    adfsSignIn('user', ['upn' => 'someone@corp.local'])->assertRedirect(route('filament.admin.auth.login'));

    $this->assertGuest('web');
    // The UPN decided, so the refusal names the address, the claim and what was passed over.
    expect(AuditLog::query()->where('event', 'login.rejected')->sole()->context)->toMatchArray([
        'reason' => 'no_account',
        'email' => 'someone@corp.local',
        'email_claim' => 'upn',
        'claims_passed_over' => ['emailaddress' => 'missing', 'email' => 'missing'],
        'access_token' => $verdict,
    ]);
})->with([
    'another client' => [['appid' => 'someone-else'], 'ignored: issued to another client'],
    'another issuer' => [['appid' => 'user-app', 'iss' => 'http://elsewhere.test/adfs/services/trust'], 'ignored: issuer mismatch'],
    'expired' => [['appid' => 'user-app', 'exp' => time() - 3600], 'ignored: Expired token'],
]);

it('refuses a sign-in without a usable e-mail claim with the reason and the claim names, never their values', function (): void {
    $this->withUnencryptedCookie('locale', 'hu');
    adfsConfig('user', ['email_claims' => 'emailaddress, unique_name']);
    staffAgent();

    adfsSignIn('user', ['upn' => 'agent@corp.hu', 'unique_name' => 'CORP\\agent'])
        ->assertRedirect(route('filament.admin.auth.login'));

    $this->assertGuest('web');
    $context = AuditLog::query()->where('event', 'login.rejected')->sole()->context;
    expect($context)->toMatchArray([
        'reason' => 'no_email_claim',
        'method' => 'sso.adfs',
        'claims_checked' => ['emailaddress', 'unique_name'],
        'claims_passed_over' => ['emailaddress' => 'missing', 'unique_name' => 'domain_account'],
        'access_token' => 'not_a_jwt',
    ])->and($context['id_token_claims'])->toContain('upn', 'unique_name', 'aud', 'nonce')
        ->and(json_encode($context))->not->toContain('agent@corp.hu')->not->toContain('CORP');

    $this->get(route('filament.admin.auth.login'))->assertOk()
        ->assertSee('A bejelentkezési szolgáltatás nem küldött használható e-mail-címet, ezért a fiók nem azonosítható.');
});

it('sends a client without a usable e-mail claim to the portal login with the reason', function (): void {
    Client::factory()->synced()->create(['email' => 'c@x.hu']);
    FakeOidc::$accessClaims = ['aud' => 'urn:hdid', 'appid' => 'client-app', 'role' => 'customer'];

    adfsSignIn('client', ['unique_name' => 'CORP\\c'])->assertRedirect('/login?error=no_email_claim');

    $this->assertGuest('client');
    expect(AuditLog::query()->where('event', 'login.rejected')->sole()->context)
        ->toMatchArray(['access_token' => 'read', 'access_token_claims' => ['aud', 'appid', 'role', 'iss', 'iat', 'exp']]);
});

it('requests the configured extra scopes and resource, and nothing extra without them', function (): void {
    staffAgent();
    FakeOidc::fake([]);
    adfsConfig('user', ['scopes' => 'allatclaims, custom openid', 'resource' => 'urn:hdid']);
    $redirect = $this->get('/auth/user/adfs/redirect');
    parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($query['scope'])->toBe('openid profile email allatclaims custom')
        ->and($query['resource'])->toBe('urn:hdid');

    adfsSignIn('user', ['emailaddress' => 'agent@corp.hu'])->assertRedirect('/admin');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://idp.test/token' && $request['resource'] === 'urn:hdid');

    adfsConfig('user');
    $redirect = $this->get('/auth/user/adfs/redirect');
    parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($query['scope'])->toBe('openid profile email')
        ->and($query)->not->toHaveKey('resource');
});

it('writes the ADFS tokens as received and decoded to the log only while debugging is on, never their signatures', function (): void {
    staffAgent();
    FakeOidc::$accessClaims = ['aud' => 'urn:hdid', 'appid' => 'user-app', 'emailaddress' => 'agent@corp.hu'];
    FakeOidc::$tokenResponse = ['refresh_token' => 'refresh-secret', 'token_type' => 'bearer', 'expires_in' => 3600];
    Log::spy();

    adfsSignIn('user', ['upn' => 'agent@corp.local'])->assertRedirect('/admin');
    Log::shouldNotHaveReceived('warning');

    adfsConfig('user', ['debug' => true]);
    $this->post('/admin/logout');
    adfsSignIn('user', ['upn' => 'agent@corp.local'])->assertRedirect('/admin');

    $logged = [];
    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use (&$logged): bool {
        $logged[$message] = $context;

        return true;
    });
    expect($logged)->toHaveKeys(['ADFS debug: authorization request', 'ADFS debug: token response', 'ADFS debug: e-mail claim']);

    $issued = Http::recorded(fn (Request $request): bool => $request->url() === 'https://idp.test/token')->last()[1]->json();
    [$idHeader, $idPayload, $idSignature] = explode('.', $issued['id_token']);
    [$accessHeader, $accessPayload, $accessSignature] = explode('.', $issued['access_token']);

    $response = $logged['ADFS debug: token response'];
    expect($response['id_token']['received'])->toBe("{$idHeader}.{$idPayload}")
        ->and($response['id_token']['signature'])->toBe('('.strlen($idSignature).' characters, not logged)')
        ->and($response['access_token']['received'])->toBe("{$accessHeader}.{$accessPayload}")
        ->and(json_encode($logged))->not->toContain($idSignature)
        ->and(json_encode($logged))->not->toContain($accessSignature)
        ->and($response['id_token']['claims'])->toMatchArray(['upn' => 'agent@corp.local', 'aud' => 'user-app'])
        ->and($response['id_token']['header'])->toMatchArray(['alg' => 'RS256'])
        ->and($response['access_token']['claims'])->toMatchArray(['emailaddress' => 'agent@corp.hu', 'appid' => 'user-app'])
        ->and($response)->toMatchArray(['refresh_token' => '(present, not logged)', 'token_type' => 'bearer', 'http_status' => 200])
        ->and(json_encode($logged))->not->toContain('refresh-secret')
        ->and($logged['ADFS debug: authorization request'])->toMatchArray(['scope' => 'openid profile email', 'email_claims' => OidcProviderConfig::ADFS_DEFAULT_EMAIL_CLAIMS])
        ->and($logged['ADFS debug: e-mail claim'])->toMatchArray(['email' => 'agent@corp.hu', 'email_claim' => 'emailaddress', 'email_token' => 'access_token']);
});

it('records the cause ADFS gives for a refused authorization', function (): void {
    adfsConfig('user', ['debug' => true]);
    Log::spy();

    adfsSignIn('user', [], 'error=invalid_scope&error_description='.rawurlencode('MSIS9605: The client is not allowed to access the requested scope.'))
        ->assertRedirect(route('filament.admin.auth.login'));

    expect(AuditLog::query()->where('event', 'login.rejected')->sole()->context)->toMatchArray([
        'reason' => 'invalid_credentials',
        'detail' => 'state_or_code',
        'provider_error' => 'invalid_scope',
        'provider_error_description' => 'MSIS9605: The client is not allowed to access the requested scope.',
    ]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'ADFS debug: callback without a usable code'
        && $context['provider_error_description'] === 'MSIS9605: The client is not allowed to access the requested scope.');
});

it('shows the compared ADFS claims on the status tile and turns it yellow while debugging', function (): void {
    adfsConfig('user', ['email_claims' => 'emailaddress,upn']);
    $check = app(HealthChecks::class)->oidc();

    expect($check->status)->toBe(CheckStatus::Ok)
        ->and($check->detail)->toContain('emailaddress, upn')
        ->and($check->detail)->toContain('emailaddress, email, upn, preferred_username, unique_name');

    adfsConfig('client', ['debug' => true]);

    expect(app(HealthChecks::class)->oidc()->status)->toBe(CheckStatus::Warn)
        ->and(app(HealthChecks::class)->oidc()->detail)->toContain('HDID_ADFS_DEBUG');
});

it('tells why each claim was passed over', function (mixed $value, string $verdict): void {
    $config = OidcProvider::Adfs->configFor(PrincipalType::User);

    $match = EmailClaimMatch::resolve(new OidcTokens(['emailaddress' => $value, 'upn' => 'agent@corp.hu']), $config);

    expect($match->email)->toBe('agent@corp.hu')
        ->and($match->claim)->toBe('upn')
        ->and($match->passedOver)->toBe(['emailaddress' => $verdict, 'email' => 'missing']);
})->with([
    'empty' => ['  ', 'empty'],
    'domain account' => ['CORP\\agent', 'domain_account'],
    'not an address' => ['agent', 'not_an_email'],
    'several addresses' => [['a@corp.hu', 'b@corp.hu'], 'several_addresses'],
]);

it('takes a multi-valued claim holding one address', function (): void {
    $match = EmailClaimMatch::resolve(new OidcTokens(['emailaddress' => ['Agent@corp.hu', 'agent@corp.hu']]), OidcProvider::Adfs->configFor(PrincipalType::User));

    expect($match->email)->toBe('agent@corp.hu');
});

it('records an unverified Entra e-mail claim as passed over', function (): void {
    config()->set('hdid.oidc.user.entra', ['tenant' => 'tenant-1', 'client_id' => 'web-app', 'client_secret' => 's']);

    $match = EmailClaimMatch::resolve(
        new OidcTokens(['email' => 'boss@corp.hu', 'preferred_username' => 'agent@corp.hu']),
        OidcProvider::Entra->configFor(PrincipalType::User),
    );

    expect($match->email)->toBe('agent@corp.hu')
        ->and($match->auditContext())->toBe(['email_claim' => 'preferred_username', 'email_token' => 'id_token', 'claims_passed_over' => ['email' => 'unverified']]);
});
