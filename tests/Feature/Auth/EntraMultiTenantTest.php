<?php

use App\Auth\Oidc\EntraTenants;
use App\Auth\Oidc\OidcProvider;
use App\Auth\Oidc\SsoSubjectBinding;
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
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeOidc;

const MT_TEMPLATE_ISSUER = 'https://login.microsoftonline.com/{tenantid}/v2.0';

const MT_ORG_DISCOVERY = 'https://login.microsoftonline.com/organizations/v2.0/.well-known/openid-configuration';

function tenantIssuer(string $tenant): string
{
    return "https://login.microsoftonline.com/{$tenant}/v2.0";
}

/**
 * The client portal's Entra provider with this tenant configuration.
 */
function configureClientEntra(?string $single, ?string $list = null): void
{
    config()->set('hdid.oidc.client.entra', ['tenant' => $single, 'tenants' => $list, 'client_id' => 'client-app', 'client_secret' => 's', 'mobile_client_id' => 'mobile-app']);
    app(Settings::class)->set(SettingKey::ClientSsoEntraEnabled, true);
}

/**
 * A browser sign-in whose token was issued by the given tenant; the fake
 * discovery document carries whatever issuer the configured endpoint would.
 *
 * @param  array<string, mixed>  $claims
 */
function signInFromTenant(string $tenant, array $claims): TestResponse
{
    $config = OidcProvider::Entra->configFor(PrincipalType::Client);
    $discoveryIssuer = $config->isMultiTenant() ? MT_TEMPLATE_ISSUER : tenantIssuer($tenant);

    FakeOidc::fake($claims + ['aud' => 'client-app', 'tid' => $tenant, 'email_verified' => true], tenantIssuer($tenant), $config->discoveryUrl, $discoveryIssuer);

    test()->get('/auth/client/entra/redirect')->assertRedirect();
    FakeOidc::$nonce = session('oidc.pending.nonce');

    return test()->get('/auth/client/entra/callback?code=abc&state='.session('oidc.pending.state'));
}

function mtLastRejection(): array
{
    return AuditLog::query()->where('event', 'login.rejected')->orderByDesc('created_at')->orderByDesc('id')->first()->context;
}

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    FakeOidc::reset();
});

afterEach(fn () => FakeOidc::reset());

it('parses the tenant list with domain filters and reports what is wrong with it', function (): void {
    $parsed = EntraTenants::parse(null, '11111111-1111-4111-8111-111111111111, 22222222-2222-4222-8222-222222222222:Partner.hu|partner.com ,33333333-3333-4333-8333-333333333333:');
    expect(array_map(fn ($t) => $t->describe(), $parsed->tenants))->toBe(['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222:partner.hu|partner.com', '33333333-3333-4333-8333-333333333333'])
        ->and($parsed->problem)->toBeNull()->and($parsed->note)->toBeNull()
        ->and($parsed->tenants[1]->allowsAddress('Someone@Partner.HU'))->toBeTrue()
        ->and($parsed->tenants[1]->allowsAddress('someone@other.hu'))->toBeFalse()
        ->and($parsed->tenants[0]->allowsAddress('anyone@anywhere.hu'))->toBeTrue();

    expect(EntraTenants::parse('11111111-1111-4111-8111-111111111111', null)->tenants[0]->describe())->toBe('11111111-1111-4111-8111-111111111111');

    $both = EntraTenants::parse('11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222');
    expect(array_map(fn ($t) => $t->id, $both->tenants))->toBe(['22222222-2222-4222-8222-222222222222', '11111111-1111-4111-8111-111111111111'])->and($both->note)->not->toBeNull();

    expect(EntraTenants::parse(null, 'organizations,11111111-1111-4111-8111-111111111111')->problem)->toContain('organizations')
        ->and(EntraTenants::parse(null, '11111111-1111-4111-8111-111111111111,,22222222-2222-4222-8222-222222222222')->problem)->toBeNull()
        ->and(EntraTenants::parse(null, ':partner.hu')->problem)->toContain('GUID')
        ->and(EntraTenants::parse(null, 'contoso.onmicrosoft.com')->problem)->toContain('GUID')
        ->and(EntraTenants::parse('tenant-1', null)->problem)->toContain('GUID')
        ->and(EntraTenants::parse(null, '11111111-1111-4111-8111-111111111111:not a domain')->problem)->toContain('not a domain')
        ->and(EntraTenants::parse('common', null)->problem)->toContain('common')
        ->and(EntraTenants::parse(null, null)->tenants)->toBe([]);
});

it('serves one tenant from its own endpoint and several from the shared one, refusing a broken list', function (): void {
    configureClientEntra('11111111-1111-4111-8111-111111111111');
    $single = OidcProvider::Entra->configFor(PrincipalType::Client);
    expect($single->isConfigured())->toBeTrue()->and($single->isMultiTenant())->toBeFalse()
        ->and($single->discoveryUrl)->toContain('/11111111-1111-4111-8111-111111111111/v2.0/');

    configureClientEntra(null, '11111111-1111-4111-8111-111111111111,22222222-2222-4222-8222-222222222222:partner.hu');
    $multi = OidcProvider::Entra->configFor(PrincipalType::Client);
    expect($multi->isConfigured())->toBeTrue()->and($multi->isMultiTenant())->toBeTrue()
        ->and($multi->discoveryUrl)->toBe(MT_ORG_DISCOVERY)
        ->and($multi->allowsTenant('22222222-2222-4222-8222-222222222222'))->toBeTrue()->and($multi->allowsTenant('33333333-3333-4333-8333-333333333333'))->toBeFalse();

    configureClientEntra(null, 'organizations');
    expect(OidcProvider::Entra->configFor(PrincipalType::Client)->isConfigured())->toBeFalse();
    $check = app(HealthChecks::class)->oidc();
    expect($check->status)->toBe(CheckStatus::Fail)->and($check->detail)->toContain('organizations');

    // Staff stay single-tenant: a list key there is not read.
    config()->set('hdid.oidc.user.entra', ['tenant' => '11111111-1111-4111-8111-111111111111', 'tenants' => '11111111-1111-4111-8111-111111111111,22222222-2222-4222-8222-222222222222', 'client_id' => 'user-app', 'client_secret' => 's']);
    expect(OidcProvider::Entra->configFor(PrincipalType::User)->isMultiTenant())->toBeFalse();
});

it('signs clients in from every listed tenant and refuses a tenant that is not listed', function (): void {
    configureClientEntra(null, '11111111-1111-4111-8111-111111111111,22222222-2222-4222-8222-222222222222');
    $one = Client::factory()->synced()->create(['email' => 'one@x.hu']);
    $two = Client::factory()->synced()->create(['email' => 'two@y.hu']);

    signInFromTenant('22222222-2222-4222-8222-222222222222', ['email' => 'two@y.hu', 'oid' => 'oid-two'])->assertRedirect('/');
    $this->assertAuthenticatedAs($two, 'client');
    expect($two->ssoIdentities()->sole()->issuer)->toBe(tenantIssuer('22222222-2222-4222-8222-222222222222'))
        ->and(AuditLog::query()->where('event', 'login.succeeded')->sole()->context)->toMatchArray(['tenant' => '22222222-2222-4222-8222-222222222222']);
    $this->post('/api/v1/client/logout');
    $this->flushSession();

    signInFromTenant('11111111-1111-4111-8111-111111111111', ['email' => 'one@x.hu', 'oid' => 'oid-one'])->assertRedirect('/');
    $this->assertAuthenticatedAs($one, 'client');
    $this->post('/api/v1/client/logout');
    $this->flushSession();

    // A tenant anyone can create: signed by Microsoft, right audience, still refused.
    signInFromTenant('33333333-3333-4333-8333-333333333333', ['email' => 'one@x.hu', 'oid' => 'oid-one'])->assertRedirect('/login?error=invalid_credentials');
    $this->assertGuest('client');
    expect(mtLastRejection())->toMatchArray(['reason' => 'invalid_credentials', 'detail' => 'invalid_token', 'error' => 'ID token tenant not allowed.']);

    // The templated issuer is only accepted for the token's own tenant.
    FakeOidc::fake(['aud' => 'client-app', 'tid' => '11111111-1111-4111-8111-111111111111', 'email' => 'one@x.hu', 'email_verified' => true, 'oid' => 'oid-one'], tenantIssuer('22222222-2222-4222-8222-222222222222'), MT_ORG_DISCOVERY, MT_TEMPLATE_ISSUER);
    $this->get('/auth/client/entra/redirect');
    FakeOidc::$nonce = session('oidc.pending.nonce');
    $this->get('/auth/client/entra/callback?code=abc&state='.session('oidc.pending.state'))->assertRedirect('/login?error=invalid_credentials');
    expect(mtLastRejection())->toMatchArray(['error' => 'ID token issuer mismatch.']);
});

it('lets a tenant with domains sign in only accounts of those domains, and a tenant without any address', function (): void {
    configureClientEntra(null, '11111111-1111-4111-8111-111111111111,22222222-2222-4222-8222-222222222222:partner.hu|partner.com');
    $partner = Client::factory()->synced()->create(['email' => 'p@partner.com']);
    $other = Client::factory()->synced()->create(['email' => 'o@other.hu']);

    signInFromTenant('22222222-2222-4222-8222-222222222222', ['email' => 'p@partner.com', 'oid' => 'oid-p'])->assertRedirect('/');
    $this->assertAuthenticatedAs($partner, 'client');
    $this->post('/api/v1/client/logout');
    $this->flushSession();

    signInFromTenant('22222222-2222-4222-8222-222222222222', ['email' => 'o@other.hu', 'oid' => 'oid-o'])->assertRedirect('/login?error=tenant_domain_not_allowed');
    $this->assertGuest('client');
    expect(mtLastRejection())->toMatchArray(['reason' => 'tenant_domain_not_allowed', 'tenant' => '22222222-2222-4222-8222-222222222222', 'allowed_domains' => ['partner.hu', 'partner.com'], 'email' => 'o@other.hu'])
        ->and($other->ssoIdentities()->count())->toBe(0);

    // The trusted tenant may sign in the same account.
    signInFromTenant('11111111-1111-4111-8111-111111111111', ['email' => 'o@other.hu', 'oid' => 'oid-o'])->assertRedirect('/');
    $this->assertAuthenticatedAs($other, 'client');
});

it('treats the same address from another allowed tenant as another identity, and renews only when the old tenant left the list', function (): void {
    configureClientEntra(null, '11111111-1111-4111-8111-111111111111,22222222-2222-4222-8222-222222222222');
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    signInFromTenant('11111111-1111-4111-8111-111111111111', ['email' => 'c@x.hu', 'oid' => 'oid-a'])->assertRedirect('/');
    $this->post('/api/v1/client/logout');
    $this->flushSession();

    signInFromTenant('22222222-2222-4222-8222-222222222222', ['email' => 'c@x.hu', 'oid' => 'oid-b'])->assertRedirect('/login?error=sso_subject_mismatch');
    $this->assertGuest('client');
    expect(mtLastRejection())->toMatchArray(['reason' => 'sso_subject_mismatch', 'detail' => 'other_tenant'])
        ->and($client->ssoIdentities()->sole()->issuer)->toBe(tenantIssuer('11111111-1111-4111-8111-111111111111'))
        ->and($client->ssoIdentities()->sole()->mismatch_count)->toBe(1);

    // The directory moved: 11111111-1111-4111-8111-111111111111 is gone from the list, the binding follows the person to 22222222-2222-4222-8222-222222222222.
    configureClientEntra(null, '22222222-2222-4222-8222-222222222222,33333333-3333-4333-8333-333333333333');
    signInFromTenant('22222222-2222-4222-8222-222222222222', ['email' => 'c@x.hu', 'oid' => 'oid-b'])->assertRedirect('/');
    $this->assertAuthenticatedAs($client, 'client');
    $identity = $client->ssoIdentities()->sole();
    expect($identity->issuer)->toBe(tenantIssuer('22222222-2222-4222-8222-222222222222'))->and($identity->subject)->toBe('oid-b')
        ->and(AuditLog::query()->where('event', SsoSubjectBinding::EVENT_BOUND)->orderByDesc('created_at')->orderByDesc('id')->first()->context)->toMatchArray(['registration_replaced' => true]);
});

it('applies the tenant list to the mobile token exchange', function (): void {
    configureClientEntra(null, '11111111-1111-4111-8111-111111111111,22222222-2222-4222-8222-222222222222:partner.hu');
    $client = Client::factory()->synced()->create(['email' => 'c@partner.hu']);
    FakeOidc::fake([], tenantIssuer('22222222-2222-4222-8222-222222222222'), MT_ORG_DISCOVERY, MT_TEMPLATE_ISSUER);

    $ok = FakeOidc::idToken(['aud' => 'mobile-app', 'tid' => '22222222-2222-4222-8222-222222222222', 'preferred_username' => 'c@partner.hu', 'oid' => 'oid-c'], tenantIssuer('22222222-2222-4222-8222-222222222222'));
    $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => $ok, 'device_name' => 'phone'])->assertOk();
    expect($client->ssoIdentities()->sole()->issuer)->toBe(tenantIssuer('22222222-2222-4222-8222-222222222222'));

    $foreign = FakeOidc::idToken(['aud' => 'mobile-app', 'tid' => '99999999-9999-4999-8999-999999999999', 'preferred_username' => 'c@partner.hu', 'oid' => 'oid-c', 'iat' => time() + 1], tenantIssuer('99999999-9999-4999-8999-999999999999'));
    $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => $foreign, 'device_name' => 'phone'])->assertUnprocessable();
    expect(mtLastRejection())->toMatchArray(['method' => 'sso.entra.mobile', 'error' => 'ID token tenant not allowed.']);

    $wrongDomain = Client::factory()->synced()->create(['email' => 'w@other.hu']);
    $filtered = FakeOidc::idToken(['aud' => 'mobile-app', 'tid' => '22222222-2222-4222-8222-222222222222', 'preferred_username' => 'w@other.hu', 'oid' => 'oid-w', 'iat' => time() + 2], tenantIssuer('22222222-2222-4222-8222-222222222222'));
    $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => $filtered, 'device_name' => 'phone'])
        ->assertForbidden()->assertJsonPath('reason', 'tenant_domain_not_allowed');
    expect($wrongDomain->ssoIdentities()->count())->toBe(0);
});

it('tolerates both tenant keys filled but says so on the status page', function (): void {
    configureClientEntra('11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222');
    $config = OidcProvider::Entra->configFor(PrincipalType::Client);
    expect($config->isConfigured())->toBeTrue()->and($config->allowsTenant('11111111-1111-4111-8111-111111111111'))->toBeTrue()->and($config->allowsTenant('22222222-2222-4222-8222-222222222222'))->toBeTrue();

    $check = app(HealthChecks::class)->oidc();
    expect($check->status)->toBe(CheckStatus::Warn)->and($check->detail)->toContain('2 tenant(s)')->toContain('Both the single tenant and the tenant list are set');

    configureClientEntra(null, '11111111-1111-4111-8111-111111111111,22222222-2222-4222-8222-222222222222:partner.hu');
    $check = app(HealthChecks::class)->oidc();
    expect($check->status)->toBe(CheckStatus::Ok)->and($check->detail)->toContain('22222222-2222-4222-8222-222222222222:partner.hu');

    // The same domain under two tenants is tolerated but flagged.
    configureClientEntra(null, '11111111-1111-4111-8111-111111111111:partner.hu,22222222-2222-4222-8222-222222222222:partner.hu|other.hu');
    $config = OidcProvider::Entra->configFor(PrincipalType::Client);
    expect($config->isConfigured())->toBeTrue()->and($config->tenantNote)->toContain('partner.hu');
    $check = app(HealthChecks::class)->oidc();
    expect($check->status)->toBe(CheckStatus::Warn)->and($check->detail)->toContain('several tenants');

    // A typo in a tenant id is caught before the first sign-in.
    configureClientEntra(null, '11111111-1111-4111-8111-111111111111,2222-2222');
    expect(OidcProvider::Entra->configFor(PrincipalType::Client)->isConfigured())->toBeFalse()
        ->and(app(HealthChecks::class)->oidc()->status)->toBe(CheckStatus::Fail);

    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $this->get('/admin/system/status')->assertOk();
});
