<?php

use App\Auth\LoginRejectedException;
use App\Auth\LoginRejection;
use App\Auth\Mfa\StaffMfa;
use App\Auth\Oidc\OidcProvider;
use App\Auth\Oidc\SsoSubject;
use App\Auth\Oidc\SsoSubjectBinding;
use App\Auth\Role;
use App\Enums\PrincipalType;
use App\Filament\Admin\Pages\SystemStatus;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\SsoIdentity;
use App\Models\User;
use App\Settings\Settings;
use App\System\CheckStatus;
use App\System\HealthChecks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\FakeOidc;

const BINDING_ENTRA_ISSUER = 'https://login.microsoftonline.com/11111111-1111-4111-8111-111111111111/v2.0';

const BINDING_ENTRA_DISCOVERY = BINDING_ENTRA_ISSUER.'/.well-known/openid-configuration';

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    FakeOidc::reset();

    foreach (PrincipalType::cases() as $principal) {
        config()->set("hdid.oidc.{$principal->value}.entra", ['tenant' => '11111111-1111-4111-8111-111111111111', 'client_id' => "{$principal->value}-app", 'client_secret' => 's', 'mobile_client_id' => 'mobile-app']);
        config()->set("hdid.oidc.{$principal->value}.adfs", ['issuer' => FakeOidc::ISSUER, 'client_id' => "{$principal->value}-app", 'client_secret' => 's']);
        app(Settings::class)->set(OidcProvider::Entra->enabledSetting($principal), true);
        app(Settings::class)->set(OidcProvider::Adfs->enabledSetting($principal), true);
    }
});

afterEach(fn () => FakeOidc::reset());

/**
 * Drive a browser sign-in (redirect + callback) whose ID token carries these
 * claims; the audience, the tenant and, unless given, a per-person subject
 * are added. Both providers answer from the same fake issuer so a test can
 * use both.
 *
 * @param  array<string, mixed>  $claims
 */
function signInWith(PrincipalType $principal, OidcProvider $provider, array $claims): TestResponse
{
    $claims += ['aud' => "{$principal->value}-app"];
    if ($provider === OidcProvider::Entra) {
        $claims += ['tid' => '11111111-1111-4111-8111-111111111111', 'email_verified' => true];
    }

    FakeOidc::fake($claims, FakeOidc::ISSUER, $provider->configFor($principal)->discoveryUrl);

    test()->get("/auth/{$principal->value}/{$provider->value}/redirect")->assertRedirect();
    FakeOidc::$nonce = session('oidc.pending.nonce');

    return test()->get("/auth/{$principal->value}/{$provider->value}/callback?code=abc&state=".session('oidc.pending.state'));
}

function staffMember(string $email = 'agent@corp.hu'): User
{
    return User::factory()->synced()->withRole(Role::Agent)->create(['email' => $email]);
}

function portalClient(string $email = 'c@x.hu'): Client
{
    return Client::factory()->synced()->create(['email' => $email]);
}

/**
 * The newest entry of an event: ids are time-ordered (UUID v7), the
 * timestamp alone ties within a second.
 */
function lastAudit(string $event): array
{
    return AuditLog::query()->where('event', $event)->orderByDesc('created_at')->orderByDesc('id')->first()->context;
}

function lastRejection(): array
{
    return lastAudit('login.rejected');
}

it('binds a staff account to the Entra object id at its first sign-in and audits it', function (): void {
    $user = staffMember();

    signInWith(PrincipalType::User, OidcProvider::Entra, ['email' => 'agent@corp.hu', 'oid' => 'oid-agent'])->assertRedirect('/admin');

    $this->assertAuthenticatedAs($user, 'web');
    $identity = $user->ssoIdentities()->sole();
    expect($identity->provider)->toBe(OidcProvider::Entra)
        ->and($identity->subject)->toBe('oid-agent')
        ->and($identity->issuer)->toBe(FakeOidc::ISSUER)
        ->and($identity->client_id)->toBe('user-app')
        ->and($identity->claim)->toBe('oid')
        ->and($identity->bound_via->value)->toBe('email')
        ->and($identity->last_used_at)->not->toBeNull();

    $bound = AuditLog::query()->where('event', SsoSubjectBinding::EVENT_BOUND)->sole();
    expect($bound->subject_id)->toBe($user->id)
        ->and($bound->context)->toMatchArray(['provider' => 'entra', 'subject_claim' => 'oid', 'via' => 'email', 'registration_replaced' => false])
        ->and(json_encode($bound->context))->not->toContain('oid-agent')
        ->and(AuditLog::query()->where('event', 'login.succeeded')->sole()->context)->toMatchArray(['sso_subject' => 'bound']);
});

it('lets the bound identity in again and refuses the same address from another identity', function (): void {
    $client = portalClient();
    signInWith(PrincipalType::Client, OidcProvider::Entra, ['email' => 'c@x.hu', 'oid' => 'oid-one'])->assertRedirect('/');
    $this->post('/api/v1/client/logout');
    $this->flushSession();

    signInWith(PrincipalType::Client, OidcProvider::Entra, ['email' => 'c@x.hu', 'oid' => 'oid-one'])->assertRedirect('/');
    $this->assertAuthenticatedAs($client, 'client');
    expect(lastAudit('login.succeeded'))->toMatchArray(['sso_subject' => 'matched'])
        ->and($client->ssoIdentities()->count())->toBe(1);
    $this->post('/api/v1/client/logout');
    $this->flushSession();

    // The same address (a reassigned UPN, a rewritten mail attribute) from another object id.
    signInWith(PrincipalType::Client, OidcProvider::Entra, ['email' => 'c@x.hu', 'oid' => 'oid-two'])->assertRedirect('/login?error=sso_subject_mismatch');
    $this->assertGuest('client');

    $rejection = lastRejection();
    expect($rejection)->toMatchArray(['reason' => 'sso_subject_mismatch', 'method' => 'sso.entra', 'provider' => 'entra', 'subject_claim' => 'oid', 'email' => 'c@x.hu'])
        ->and(json_encode($rejection))->not->toContain('oid-two')->not->toContain('oid-one');

    $identity = $client->ssoIdentities()->sole();
    expect($identity->subject)->toBe('oid-one')
        ->and($identity->mismatch_count)->toBe(1)
        ->and($identity->last_mismatch_at)->not->toBeNull()
        ->and($client->fresh()->isClosed())->toBeFalse()
        ->and($client->fresh()->isLoginLocked())->toBeFalse();
});

it('refuses an identity that is already bound to another account of the same kind', function (): void {
    $first = portalClient('first@x.hu');
    portalClient('second@x.hu');
    signInWith(PrincipalType::Client, OidcProvider::Entra, ['email' => 'first@x.hu', 'oid' => 'oid-shared'])->assertRedirect('/');
    $this->post('/api/v1/client/logout');
    $this->flushSession();

    signInWith(PrincipalType::Client, OidcProvider::Entra, ['email' => 'second@x.hu', 'oid' => 'oid-shared'])->assertRedirect('/login?error=sso_subject_taken');

    $this->assertGuest('client');
    expect(lastRejection())->toMatchArray(['reason' => 'sso_subject_taken', 'bound_principal_type' => 'client', 'bound_principal_id' => $first->id])
        ->and(SsoIdentity::query()->count())->toBe(1)
        ->and($first->ssoIdentities()->sole()->mismatch_count)->toBe(1);
});

it('takes the subject over from a closed or deleted account and lets the reopened one find it taken', function (): void {
    $old = portalClient('old@x.hu');
    signInWith(PrincipalType::Client, OidcProvider::Entra, ['email' => 'old@x.hu', 'oid' => 'oid-person'])->assertRedirect('/');
    $this->post('/api/v1/client/logout');
    $this->flushSession();
    $old->close('sync.missing');

    // The person came back with a new EMD record and account, same directory identity.
    $new = portalClient('new@x.hu');
    signInWith(PrincipalType::Client, OidcProvider::Entra, ['email' => 'new@x.hu', 'oid' => 'oid-person'])->assertRedirect('/');
    $this->assertAuthenticatedAs($new, 'client');
    expect($new->ssoIdentities()->sole()->subject)->toBe('oid-person')
        ->and($old->ssoIdentities()->count())->toBe(0)
        ->and(lastAudit('login.succeeded'))->toMatchArray(['sso_subject' => 'bound']);
    $released = AuditLog::query()->where('event', SsoSubjectBinding::EVENT_UNBOUND)->sole();
    expect($released->subject_id)->toBe($old->id)->and($released->actor_id)->toBe($new->id)
        ->and($released->context)->toMatchArray(['reason' => 'holder_closed', 'provider' => 'entra', 'released_to_principal_type' => 'client', 'released_to_principal_id' => $new->id]);
    $this->post('/api/v1/client/logout');
    $this->flushSession();

    // Reopened later: the subject is taken now, an administrator decides.
    $old->reopen();
    signInWith(PrincipalType::Client, OidcProvider::Entra, ['email' => 'old@x.hu', 'oid' => 'oid-person'])->assertRedirect('/login?error=sso_subject_taken');
    expect(lastRejection())->toMatchArray(['reason' => 'sso_subject_taken', 'bound_principal_id' => $new->id]);

    // A deleted holder yields the same way.
    $gone = staffMember('gone@corp.hu');
    SsoIdentity::factory()->forAccount($gone, OidcProvider::Entra, FakeOidc::ISSUER, 'user-app', 'oid-staff')->create();
    $gone->delete();
    $fresh = staffMember('fresh@corp.hu');
    signInWith(PrincipalType::User, OidcProvider::Entra, ['email' => 'fresh@corp.hu', 'oid' => 'oid-staff'])->assertRedirect('/admin');
    expect($fresh->ssoIdentities()->sole()->subject)->toBe('oid-staff')
        ->and(AuditLog::query()->where('event', SsoSubjectBinding::EVENT_UNBOUND)->where('subject_id', $gone->id)->sole()->context)->toMatchArray(['reason' => 'holder_deleted']);
});

it('lets only one of two simultaneous first sign-ins bind a subject', function (): void {
    $first = portalClient('first@x.hu');
    $second = portalClient('second@x.hu');
    $config = OidcProvider::Entra->configFor(PrincipalType::Client);
    $subject = SsoSubject::fromClaims(['iss' => FakeOidc::ISSUER, 'oid' => 'oid-race'], $config);

    // The other sign-in wins the unique index right before this insert.
    SsoIdentity::creating(function () use ($first, $subject): void {
        if (SsoIdentity::query()->doesntExist()) {
            DB::table('sso_identities')->insert([
                'id' => (string) Str::uuid(), 'principal_type' => 'client', 'principal_id' => $first->id, 'provider' => 'entra',
                'issuer' => $subject->issuer, 'client_id' => $subject->clientId, 'subject' => $subject->subject, 'lookup' => $subject->lookup(PrincipalType::Client),
                'claim' => 'oid', 'bound_via' => 'email', 'bound_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    expect(fn () => app(SsoSubjectBinding::class)->assert($second, $subject, $config, 'sso.entra'))
        ->toThrow(LoginRejectedException::class, __(LoginRejection::SsoSubjectTaken->message()));
    expect(SsoIdentity::query()->count())->toBe(1)
        ->and(SsoIdentity::query()->sole()->principal_id)->toBe($first->id)
        ->and(lastRejection())->toMatchArray(['reason' => 'sso_subject_taken']);
});

it('keeps staff and client bindings apart so one person may hold both kinds of account', function (): void {
    $user = staffMember('both@corp.hu');
    $client = portalClient('both@corp.hu');

    signInWith(PrincipalType::User, OidcProvider::Entra, ['email' => 'both@corp.hu', 'oid' => 'oid-same'])->assertRedirect('/admin');
    signInWith(PrincipalType::Client, OidcProvider::Entra, ['email' => 'both@corp.hu', 'oid' => 'oid-same'])->assertRedirect('/');

    expect($user->ssoIdentities()->sole()->subject)->toBe('oid-same')
        ->and($client->ssoIdentities()->sole()->subject)->toBe('oid-same')
        ->and(SsoIdentity::query()->count())->toBe(2);
});

it('refuses a token without a stable subject unless the deployment allows signing in unbound', function (): void {
    $client = portalClient();
    FakeOidc::$withoutSubject = true;

    signInWith(PrincipalType::Client, OidcProvider::Entra, ['email' => 'c@x.hu'])->assertRedirect('/login?error=no_subject_claim');
    $this->assertGuest('client');
    expect(lastRejection())->toMatchArray(['reason' => 'no_subject_claim', 'subject_claim' => 'oid']);

    config()->set('hdid.oidc.require_subject', false);
    signInWith(PrincipalType::Client, OidcProvider::Entra, ['email' => 'c@x.hu'])->assertRedirect('/');
    $this->assertAuthenticatedAs($client, 'client');
    expect($client->ssoIdentities()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'login.succeeded')->sole()->context)->toMatchArray(['sso_subject' => 'missing']);
});

it('binds ADFS by its pairwise subject and renews the binding when the client registration changes', function (): void {
    $user = staffMember();

    signInWith(PrincipalType::User, OidcProvider::Adfs, ['email' => 'agent@corp.hu', 'sub' => 'sub-for-app-1'])->assertRedirect('/admin');
    $identity = $user->ssoIdentities()->sole();
    expect($identity->provider)->toBe(OidcProvider::Adfs)->and($identity->claim)->toBe('sub')->and($identity->client_id)->toBe('user-app');
    auth('web')->logout();
    $this->flushSession();

    // Re-registered application: a new client id, so every pairwise subject differs.
    config()->set('hdid.oidc.user.adfs.client_id', 'user-app-2');
    signInWith(PrincipalType::User, OidcProvider::Adfs, ['email' => 'agent@corp.hu', 'sub' => 'sub-for-app-2', 'aud' => 'user-app-2'])->assertRedirect('/admin');

    $this->assertAuthenticatedAs($user, 'web');
    $renewed = $user->ssoIdentities()->sole();
    expect($renewed->client_id)->toBe('user-app-2')->and($renewed->subject)->toBe('sub-for-app-2')
        ->and(SsoIdentity::query()->count())->toBe(1)
        ->and(lastAudit(SsoSubjectBinding::EVENT_BOUND))->toMatchArray(['registration_replaced' => true]);
});

it('survives an e-mail change, closure, reopening, access revocation and an MFA reset', function (): void {
    $user = staffMember();
    signInWith(PrincipalType::User, OidcProvider::Entra, ['email' => 'agent@corp.hu', 'oid' => 'oid-agent'])->assertRedirect('/admin');
    auth('web')->logout();
    $this->flushSession();

    $user->forceFill(['email' => 'renamed@corp.hu'])->save();
    $user->externalRecord->update(['email' => 'renamed@corp.hu']);
    $user->revokeAccess();
    $user->close('test');
    $user->reopen();
    app(StaffMfa::class)->reset($user);

    expect($user->ssoIdentities()->sole()->subject)->toBe('oid-agent');

    signInWith(PrincipalType::User, OidcProvider::Entra, ['email' => 'renamed@corp.hu', 'oid' => 'oid-agent'])->assertRedirect('/admin');
    $this->assertAuthenticatedAs($user, 'web');
    expect(lastAudit('login.succeeded'))->toMatchArray(['sso_subject' => 'matched']);
});

it('clears the binding from the users list under the user policy and binds anew at the next sign-in', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $user = staffMember();
    $superAdmin = User::factory()->synced()->withRole(Role::SuperAdmin)->create(['email' => 'root@corp.hu']);
    SsoIdentity::factory()->forAccount($user, subject: 'oid-old')->create();
    SsoIdentity::factory()->forAccount($superAdmin, subject: 'oid-root')->create();
    $unbound = staffMember('unbound@corp.hu');
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('unbindSso', $unbound)
        ->assertActionDisabled(TestAction::make('unbindSso')->table($superAdmin))
        ->callTableAction('unbindSso', $user)
        ->assertNotified();

    expect($user->ssoIdentities()->count())->toBe(0)
        ->and($superAdmin->ssoIdentities()->count())->toBe(1);
    $audit = AuditLog::query()->where('event', SsoSubjectBinding::EVENT_UNBOUND)->sole();
    expect($audit->actor_id)->toBe($admin->id)->and($audit->subject_id)->toBe($user->id)->and($audit->context)->toMatchArray(['provider' => 'entra']);

    auth('web')->logout();
    $this->flushSession();
    signInWith(PrincipalType::User, OidcProvider::Entra, ['email' => 'agent@corp.hu', 'oid' => 'oid-new'])->assertRedirect('/admin');
    expect($user->ssoIdentities()->sole()->subject)->toBe('oid-new');
});

it('clears a client binding from the record page and shows the binding there', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = portalClient();
    SsoIdentity::factory()->forAccount($client, clientId: 'client-app', subject: 'oid-client')->create(['last_mismatch_at' => now(), 'mismatch_count' => 2]);
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    Livewire::test(ViewClient::class, ['record' => $client->getRouteKey()])
        ->assertSee(__('Microsoft login'))
        ->assertSee('the binding refused 2 sign-in(s)')
        ->callAction(TestAction::make('unbindSso')->schemaComponent('sso_bindings'))
        ->assertNotified();

    expect($client->ssoIdentities()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', SsoSubjectBinding::EVENT_UNBOUND)->sole()->subject_id)->toBe($client->id);

    Livewire::test(EditUser::class, ['record' => staffMember('other@corp.hu')->getRouteKey()])
        ->assertSee(__('Not bound yet: the first company sign-in binds the account.'));
});

it('clears bindings from the console, for one account or a whole registration', function (): void {
    $user = staffMember('both@corp.hu');
    $client = portalClient('both@corp.hu');
    $other = portalClient('other@x.hu');
    SsoIdentity::factory()->forAccount($user, subject: 'oid-u')->create();
    SsoIdentity::factory()->forAccount($user, OidcProvider::Adfs, FakeOidc::ISSUER, 'user-app', 'sub-u')->create();
    SsoIdentity::factory()->forAccount($client, clientId: 'client-app', subject: 'oid-c')->create();
    SsoIdentity::factory()->forAccount($other, OidcProvider::Adfs, FakeOidc::ISSUER, 'client-app', 'sub-o')->create();

    $this->artisan('hdid:sso-unbind', ['email' => 'both@corp.hu', '--provider' => 'entra'])->assertSuccessful();
    expect($user->ssoIdentities()->pluck('provider')->map->value->all())->toBe(['adfs'])
        ->and($client->ssoIdentities()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'account.sso_unbound_by_command')->count())->toBe(2)
        ->and(AuditLog::query()->where('event', SsoSubjectBinding::EVENT_UNBOUND)->first()->context)->toMatchArray(['via' => 'console']);

    $this->artisan('hdid:sso-unbind', ['--all' => true, '--provider' => 'adfs', '--force' => true])->assertSuccessful();
    expect(SsoIdentity::query()->count())->toBe(0);

    $this->artisan('hdid:sso-unbind', ['email' => 'nobody@x.hu'])->assertFailed();
    $this->artisan('hdid:sso-unbind', ['email' => 'both@corp.hu', '--provider' => 'google'])->assertFailed();
    $this->artisan('hdid:sso-unbind')->assertFailed();
});

it('keeps an Entra binding across a client registration change and still refuses another object id', function (): void {
    $user = staffMember();
    signInWith(PrincipalType::User, OidcProvider::Entra, ['email' => 'agent@corp.hu', 'oid' => 'oid-agent'])->assertRedirect('/admin');
    auth('web')->logout();
    $this->flushSession();

    // A new app registration in the same tenant: the object id is the same person.
    config()->set('hdid.oidc.user.entra.client_id', 'user-app-2');
    signInWith(PrincipalType::User, OidcProvider::Entra, ['email' => 'agent@corp.hu', 'oid' => 'oid-agent', 'aud' => 'user-app-2'])->assertRedirect('/admin');
    $this->assertAuthenticatedAs($user, 'web');
    expect(lastAudit('login.succeeded'))->toMatchArray(['sso_subject' => 'matched'])
        ->and($user->ssoIdentities()->sole()->client_id)->toBe('user-app');
    auth('web')->logout();
    $this->flushSession();

    signInWith(PrincipalType::User, OidcProvider::Entra, ['email' => 'agent@corp.hu', 'oid' => 'oid-other', 'aud' => 'user-app-2'])->assertRedirect(route('filament.admin.auth.login'));
    $this->assertGuest('web');
    expect(lastRejection())->toMatchArray(['reason' => 'sso_subject_mismatch']);

    // Another tenant's issuer is another registration: the binding does not carry over.
    $other = portalClient('tenant2@x.hu');
    SsoIdentity::factory()->forAccount($other, OidcProvider::Entra, 'https://login.microsoftonline.com/22222222-2222-4222-8222-222222222222/v2.0', 'client-app', 'oid-agent')->create();
    expect(SsoIdentity::query()->count())->toBe(2);
});

it('treats a lost race for the same account as a matched sign-in, and for another subject as a mismatch', function (): void {
    $client = portalClient();
    $config = OidcProvider::Entra->configFor(PrincipalType::Client);
    $subject = SsoSubject::fromClaims(['iss' => FakeOidc::ISSUER, 'oid' => 'oid-race'], $config);
    $insert = function (string $oid) use ($client, $subject): void {
        DB::table('sso_identities')->insert([
            'id' => (string) Str::uuid(), 'principal_type' => 'client', 'principal_id' => $client->id, 'provider' => 'entra',
            'issuer' => $subject->issuer, 'client_id' => $subject->clientId, 'subject' => $oid, 'lookup' => SsoSubject::lookupOf(PrincipalType::Client, OidcProvider::Entra, $subject->issuer, $subject->clientId, $oid),
            'claim' => 'oid', 'bound_via' => 'email', 'bound_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    // The same person's other browser bound the account a moment earlier.
    SsoIdentity::creating(function () use ($insert): void {
        if (SsoIdentity::query()->doesntExist()) {
            $insert('oid-race');
        }
    });
    $identity = app(SsoSubjectBinding::class)->assert($client, $subject, $config, 'sso.entra');
    expect($identity->wasRecentlyCreated)->toBeFalse()->and(SsoIdentity::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'login.rejected')->exists())->toBeFalse();

    // A different identity won the first binding of this account at the same moment.
    SsoIdentity::query()->delete();
    SsoIdentity::flushEventListeners();
    SsoIdentity::creating(function () use ($insert): void {
        if (SsoIdentity::query()->doesntExist()) {
            $insert('oid-else');
        }
    });
    expect(fn () => app(SsoSubjectBinding::class)->assert($client, $subject, $config, 'sso.entra'))
        ->toThrow(LoginRejectedException::class);
    expect(lastRejection())->toMatchArray(['reason' => 'sso_subject_mismatch'])
        ->and(SsoIdentity::query()->sole()->subject)->toBe('oid-else');
});

it('binds the mobile token exchange to the same identity as the browser sign-in', function (): void {
    $client = portalClient();
    FakeOidc::fake([], BINDING_ENTRA_ISSUER, BINDING_ENTRA_DISCOVERY);

    $first = FakeOidc::idToken(['aud' => 'mobile-app', 'tid' => '11111111-1111-4111-8111-111111111111', 'preferred_username' => 'c@x.hu', 'oid' => 'oid-phone'], BINDING_ENTRA_ISSUER);
    $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => $first, 'device_name' => 'iPhone'])->assertOk();
    $identity = $client->ssoIdentities()->sole();
    expect($identity->subject)->toBe('oid-phone')->and($identity->client_id)->toBe('client-app')
        ->and(AuditLog::query()->where('event', 'login.succeeded')->sole()->context)->toMatchArray(['method' => 'sso.entra.mobile', 'sso_subject' => 'bound', 'device' => 'iPhone']);

    $again = FakeOidc::idToken(['aud' => 'mobile-app', 'tid' => '11111111-1111-4111-8111-111111111111', 'preferred_username' => 'c@x.hu', 'oid' => 'oid-phone', 'iat' => time() + 1], BINDING_ENTRA_ISSUER);
    $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => $again, 'device_name' => 'iPad'])->assertOk();

    $foreign = FakeOidc::idToken(['aud' => 'mobile-app', 'tid' => '11111111-1111-4111-8111-111111111111', 'preferred_username' => 'c@x.hu', 'oid' => 'oid-other', 'iat' => time() + 2], BINDING_ENTRA_ISSUER);
    $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => $foreign, 'device_name' => 'x'])
        ->assertForbidden()->assertJsonPath('reason', 'sso_subject_mismatch');
    expect($client->ssoIdentities()->sole()->mismatch_count)->toBe(1);
});

it('finds the account by the employee id claim when the deployment says so', function (): void {
    config()->set('hdid.oidc.user.match', 'employee_id');
    $user = staffMember();
    $user->externalRecord->update(['external_id' => 4242]);

    // The token's address plays no part in this mode; the EMD record's does.
    signInWith(PrincipalType::User, OidcProvider::Entra, ['email' => 'alias@corp.hu', 'employeeId' => '4242', 'oid' => 'oid-agent'])->assertRedirect('/admin');
    $this->assertAuthenticatedAs($user, 'web');
    expect(AuditLog::query()->where('event', 'login.succeeded')->sole()->context)
        ->toMatchArray(['employee_id_claim' => 'employeeId', 'employee_id_token' => 'id_token', 'sso_subject' => 'bound'])
        ->not->toHaveKey('email')
        ->and($user->ssoIdentities()->sole()->bound_via->value)->toBe('employee_id');
    auth('web')->logout();
    $this->flushSession();

    signInWith(PrincipalType::User, OidcProvider::Entra, ['email' => 'agent@corp.hu', 'oid' => 'oid-agent'])->assertRedirect(route('filament.admin.auth.login'));
    $this->assertGuest('web');
    expect(lastRejection())->toMatchArray(['reason' => 'no_employee_id_claim', 'employee_id_claim' => 'employeeId', 'employee_id_problem' => 'missing']);

    signInWith(PrincipalType::User, OidcProvider::Entra, ['email' => 'agent@corp.hu', 'employeeId' => '9999', 'oid' => 'oid-agent']);
    expect(lastRejection())->toMatchArray(['reason' => 'no_account', 'external_id' => 9999]);

    // A locally created record (hdid:make-admin) never comes from the directory.
    $user->externalRecord->update(['external_id' => ExternalRecord::LOCAL_ID_BASE + 1]);
    signInWith(PrincipalType::User, OidcProvider::Entra, ['email' => 'agent@corp.hu', 'employeeId' => (string) (ExternalRecord::LOCAL_ID_BASE + 1), 'oid' => 'oid-agent']);
    expect(lastRejection())->toMatchArray(['reason' => 'no_account']);

    // The EMD rule still runs: the record's address must be the account's.
    $user->externalRecord->update(['external_id' => 4242, 'email' => 'someone-else@corp.hu']);
    signInWith(PrincipalType::User, OidcProvider::Entra, ['email' => 'agent@corp.hu', 'employeeId' => '4242', 'oid' => 'oid-agent']);
    expect(lastRejection())->toMatchArray(['reason' => 'external_record_mismatch']);
});

it('reads the employee id from a configured claim and a claim-type URI, in the ID token or the ADFS access token', function (): void {
    config()->set('hdid.oidc.user.match', 'employee_id');
    config()->set('hdid.oidc.user.employee_id_claim', 'employeenumber');
    $user = staffMember();
    $user->externalRecord->update(['external_id' => 777]);

    signInWith(PrincipalType::User, OidcProvider::Adfs, ['upn' => 'agent@corp.local', 'http://schemas.corp.hu/claims/employeenumber' => '777', 'sub' => 'sub-agent'])->assertRedirect('/admin');
    $this->assertAuthenticatedAs($user, 'web');
    expect(AuditLog::query()->where('event', 'login.succeeded')->sole()->context)->toMatchArray(['employee_id_claim' => 'http://schemas.corp.hu/claims/employeenumber', 'employee_id_token' => 'id_token']);
    auth('web')->logout();
    $this->flushSession();

    FakeOidc::$accessClaims = ['aud' => 'https://hdid.test/', 'appid' => 'user-app', 'employeenumber' => 777];
    signInWith(PrincipalType::User, OidcProvider::Adfs, ['upn' => 'agent@corp.local', 'sub' => 'sub-agent'])->assertRedirect('/admin');
    expect(lastAudit('login.succeeded'))->toMatchArray(['employee_id_claim' => 'employeenumber', 'employee_id_token' => 'access_token']);
});

it('treats an unknown match mode as a provider that is not configured', function (): void {
    config()->set('hdid.oidc.client.match', 'surname');

    expect(OidcProvider::Entra->configFor(PrincipalType::Client)->isConfigured())->toBeFalse()
        ->and(OidcProvider::Adfs->configFor(PrincipalType::Client)->isConfigured())->toBeFalse()
        ->and(OidcProvider::Entra->configFor(PrincipalType::User)->isConfigured())->toBeTrue()
        ->and(app(HealthChecks::class)->oidc()->status)->toBe(CheckStatus::Fail);

    $this->get('/auth/client/entra/redirect')->assertRedirect('/login?error=method_disabled');
});

it('shows the refused sign-ins on the status page with a link to the accounts', function (): void {
    $checks = app(HealthChecks::class);
    expect($checks->ssoBindings()->status)->toBe(CheckStatus::Ok);

    $client = portalClient();
    SsoIdentity::factory()->forAccount($client, clientId: 'client-app', subject: 'oid-c')->create(['last_mismatch_at' => now()->subDays(2), 'mismatch_count' => 1]);
    $check = $checks->ssoBindings();
    expect($check->status)->toBe(CheckStatus::Warn)->and($check->detail)->toContain('1 refused');

    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');
    expect(app(SystemStatus::class)->linkFor($check)['url'])->toContain('/admin/accounts/users');

    SsoIdentity::query()->update(['last_mismatch_at' => now()->subDays(HealthChecks::SSO_MISMATCH_WINDOW_DAYS + 1)]);
    expect($checks->ssoBindings()->status)->toBe(CheckStatus::Ok);
});
