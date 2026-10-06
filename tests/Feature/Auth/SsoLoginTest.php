<?php

use App\Auth\Oidc\OidcProvider;
use App\Auth\Role;
use App\Enums\PrincipalType;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeOidc;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    config()->set('hdid.oidc.user.adfs', ['issuer' => FakeOidc::ISSUER, 'client_id' => 'user-app', 'client_secret' => 's']);
    config()->set('hdid.oidc.client.adfs', ['issuer' => FakeOidc::ISSUER, 'client_id' => 'client-app', 'client_secret' => 's']);
    app(Settings::class)->set(SettingKey::UserSsoAdfsEnabled, true);
    app(Settings::class)->set(SettingKey::ClientSsoAdfsEnabled, true);
});

/**
 * Drive redirect + callback and return the callback response.
 */
function completeSso(string $principal, string $provider, array $claims)
{
    FakeOidc::fake($claims);

    $redirect = test()->get("/auth/{$principal}/{$provider}/redirect");
    $redirect->assertRedirect();
    $location = $redirect->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $query);

    expect($query)->toHaveKeys(['state', 'nonce', 'code_challenge'])
        ->and($query['client_id'])->toBe($principal === 'user' ? 'user-app' : 'client-app');

    // The fake token endpoint signs whatever nonce the session holds.
    FakeOidc::$nonce = session('oidc.pending')['nonce'];

    return test()->get("/auth/{$principal}/{$provider}/callback?code=abc&state=".$query['state']);
}

it('signs a synced staff user in through ADFS and lands in the panel', function (): void {
    $user = User::factory()->synced()->withRole(Role::Agent)->create(['email' => 'agent@corp.hu']);

    $response = completeSso('user', 'adfs', ['aud' => 'user-app', 'email' => 'Agent@corp.hu']);

    $response->assertRedirect('/admin');
    $this->assertAuthenticatedAs($user, 'web');
    expect(AuditLog::query()->where('event', 'login.succeeded')->where('subject_id', $user->id)->exists())->toBeTrue()
        ->and($user->fresh()->last_login_at)->not->toBeNull();
});

it('rejects a staff user without external record', function (): void {
    User::factory()->withRole(Role::Agent)->create(['email' => 'agent@corp.hu']);

    $response = completeSso('user', 'adfs', ['aud' => 'user-app', 'email' => 'agent@corp.hu']);

    $response->assertRedirect(route('filament.admin.auth.login'));
    $this->assertGuest('web');
    expect(AuditLog::query()->where('event', 'login.rejected')->first()->context['reason'])->toBe('no_external_record');
});

it('rejects a staff user without any panel permission', function (): void {
    User::factory()->synced()->create(['email' => 'agent@corp.hu']);

    completeSso('user', 'adfs', ['aud' => 'user-app', 'email' => 'agent@corp.hu']);

    $this->assertGuest('web');
    expect(AuditLog::query()->where('event', 'login.rejected')->first()->context['reason'])->toBe('no_permission');
});

it('rejects a closed client and a client whose record went missing', function (): void {
    Client::factory()->synced()->closed()->create(['email' => 'c@x.hu']);
    completeSso('client', 'adfs', ['aud' => 'client-app', 'email' => 'c@x.hu'])->assertRedirect('/login?error=account_closed');
    $this->assertGuest('client');

    $missing = Client::factory()->synced()->create(['email' => 'm@x.hu']);
    $missing->externalRecord->update(['missing_since' => now()]);
    completeSso('client', 'adfs', ['aud' => 'client-app', 'email' => 'm@x.hu'])->assertRedirect('/login?error=external_record_missing');
});

it('signs a client in through ADFS onto the client guard', function (): void {
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);

    completeSso('client', 'adfs', ['aud' => 'client-app', 'email' => 'c@x.hu'])->assertRedirect('/');

    $this->assertAuthenticatedAs($client, 'client');
    $this->assertGuest('web');
});

it('rejects a token issued for another audience', function (): void {
    Client::factory()->synced()->create(['email' => 'c@x.hu']);

    completeSso('client', 'adfs', ['aud' => 'user-app', 'email' => 'c@x.hu'])->assertRedirect('/login?error=invalid_credentials');
    $this->assertGuest('client');
});

it('refuses a callback with a forged state', function (): void {
    FakeOidc::fake([]);
    $this->get('/auth/client/adfs/redirect');

    $this->get('/auth/client/adfs/callback?code=abc&state=forged')->assertRedirect('/login?error=invalid_credentials');
});

it('refuses a disabled provider', function (): void {
    app(Settings::class)->set(SettingKey::ClientSsoAdfsEnabled, false);

    $this->get('/auth/client/adfs/redirect')->assertRedirect('/login?error=method_disabled');
});

/** @return array{account: Client|User, state: string} */
function startConfiguredSso(PrincipalType $principal, OidcProvider $provider): array
{
    config()->set("hdid.oidc.{$principal->value}.{$provider->value}", [
        'issuer' => FakeOidc::ISSUER, 'tenant' => 'tenant-1',
        'client_id' => $principal->value.'-app', 'client_secret' => 's',
    ]);
    app(Settings::class)->set($provider->enabledSetting($principal), true);
    $account = $principal->modelClass()::factory()->synced()->create();
    if ($account instanceof User) {
        $account->assignRole(Role::Agent->value);
    }
    FakeOidc::fake([
        'aud' => $principal->value.'-app', 'tid' => 'tenant-1',
        'email' => $account->email, 'email_verified' => true,
    ], FakeOidc::ISSUER, $provider->configFor($principal)->discoveryUrl);
    Http::preventStrayRequests();

    test()->get("/auth/{$principal->value}/{$provider->value}/redirect")->assertRedirect();
    FakeOidc::$nonce = session('oidc.pending.nonce');

    return ['account' => $account, 'state' => session('oidc.pending.state')];
}

dataset('sso providers and principals', [
    'staff ADFS' => [PrincipalType::User, OidcProvider::Adfs],
    'staff Entra' => [PrincipalType::User, OidcProvider::Entra],
    'client ADFS' => [PrincipalType::Client, OidcProvider::Adfs],
    'client Entra' => [PrincipalType::Client, OidcProvider::Entra],
]);

it('rejects a callback disabled after redirect without exchanging the code', function (PrincipalType $principal, OidcProvider $provider): void {
    $login = startConfiguredSso($principal, $provider);
    (clone app(Settings::class))->set($provider->enabledSetting($principal), false);

    $this->get("/auth/{$principal->value}/{$provider->value}/callback?code=abc&state=".$login['state'])
        ->assertRedirect()->assertSessionMissing('oidc.pending');

    $this->assertGuest($principal->guard());
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://idp.test/token');
    expect($login['account']->fresh()->last_login_at)->toBeNull();
    $this->assertDatabaseMissing(AuditLog::class, ['event' => 'login.succeeded']);
    expect(AuditLog::query()->where('event', 'login.rejected')->sole()->context)
        ->toMatchArray(['reason' => 'method_disabled', 'principal' => $principal->value, 'provider' => $provider->value]);
})->with('sso providers and principals');

it('rechecks the provider after an in-flight code exchange', function (PrincipalType $principal, OidcProvider $provider): void {
    $login = startConfiguredSso($principal, $provider);
    Event::listen(RequestSending::class, function (RequestSending $event) use ($principal, $provider): void {
        if ($event->request->url() === 'https://idp.test/token') {
            (clone app(Settings::class))->set($provider->enabledSetting($principal), false);
        }
    });

    $this->get("/auth/{$principal->value}/{$provider->value}/callback?code=abc&state=".$login['state'])
        ->assertRedirect()->assertSessionMissing('oidc.pending');

    $this->assertGuest($principal->guard());
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://idp.test/token');
    expect($login['account']->fresh()->last_login_at)->toBeNull();
    $this->assertDatabaseMissing(AuditLog::class, ['event' => 'login.succeeded']);
    $rejected = AuditLog::query()->where('event', 'login.rejected')->sole();
    // The account is already known at the second check, so the entry names it.
    expect($rejected->context)->toMatchArray(['reason' => 'method_disabled', 'email' => $login['account']->email])
        ->and($rejected->subject_id)->toBe($login['account']->id);
})->with('sso providers and principals');

it('keeps an existing SSO session when its provider is disabled', function (PrincipalType $principal, OidcProvider $provider): void {
    $login = startConfiguredSso($principal, $provider);
    $this->get("/auth/{$principal->value}/{$provider->value}/callback?code=abc&state=".$login['state'])->assertRedirect();
    app(Settings::class)->set($provider->enabledSetting($principal), false);

    $this->get($principal === PrincipalType::User ? '/admin' : '/api/v1/client/me')->assertOk();

    $this->assertAuthenticatedAs($login['account'], $principal->guard());
})->with('sso providers and principals');

it('tells a rejected staff user the reason on the panel login page, in their language', function (): void {
    $this->withUnencryptedCookie('locale', 'hu');
    User::factory()->withRole(Role::Agent)->create(['email' => 'agent@corp.hu']);

    completeSso('user', 'adfs', ['aud' => 'user-app', 'email' => 'agent@corp.hu'])
        ->assertRedirect(route('filament.admin.auth.login'));

    $this->get(route('filament.admin.auth.login'))->assertOk()
        ->assertSee('A belépés nem sikerült.')
        ->assertSee('Ez a fiók nem szerepel az ügyféltörzsben.');
});

it('refuses an enabled provider without configuration instead of failing', function (PrincipalType $principal): void {
    $this->withUnencryptedCookie('locale', 'hu');
    config()->set("hdid.oidc.{$principal->value}.adfs", ['issuer' => null, 'client_id' => null, 'client_secret' => null]);
    Http::preventStrayRequests();

    $response = $this->get("/auth/{$principal->value}/adfs/redirect");

    expect(AuditLog::query()->where('event', 'login.rejected')->sole()->context)
        ->toMatchArray(['reason' => 'method_disabled', 'provider' => 'adfs', 'configured' => false]);
    if ($principal === PrincipalType::Client) {
        $response->assertRedirect('/login?error=method_disabled');

        return;
    }
    $response->assertRedirect(route('filament.admin.auth.login'));
    $this->get(route('filament.admin.auth.login'))->assertOk()->assertSee('Ez a belépési mód nem elérhető.');
})->with([PrincipalType::User, PrincipalType::Client]);

it('reports an unreachable identity provider as unavailable, not as an error page', function (string $failure): void {
    Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $discovery = Http::response([
        'issuer' => FakeOidc::ISSUER,
        'authorization_endpoint' => 'https://idp.test/authorize',
        'token_endpoint' => 'https://idp.test/token',
        'jwks_uri' => 'https://idp.test/jwks',
    ]);
    Http::fake([
        FakeOidc::DISCOVERY => match ($failure) {
            'discovery unreachable' => Http::failedConnection(),
            'discovery error' => Http::response('down', 502),
            default => $discovery,
        },
        'https://idp.test/token' => match ($failure) {
            'token endpoint unreachable' => Http::failedConnection(),
            'token endpoint error' => Http::response(['error' => 'temporarily_unavailable'], 503),
            default => fn () => Http::response([
                'id_token' => FakeOidc::idToken(['aud' => 'client-app', 'email' => 'c@x.hu', 'nonce' => session('oidc.pending.nonce')], FakeOidc::ISSUER),
                'access_token' => 'x',
            ]),
        },
        'https://idp.test/jwks' => $failure === 'jwks unreachable' ? Http::failedConnection() : Http::response('down', 503),
    ]);

    $redirect = $this->get('/auth/client/adfs/redirect');
    if (str_starts_with($failure, 'discovery')) {
        $redirect->assertRedirect('/login?error=provider_unavailable');

        return;
    }
    parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);

    $this->get('/auth/client/adfs/callback?code=abc&state='.$query['state'])->assertRedirect('/login?error=provider_unavailable');
    $this->assertGuest('client');
})->with(['discovery unreachable', 'discovery error', 'token endpoint unreachable', 'token endpoint error', 'jwks unreachable', 'jwks error']);

it('sets no remember-me cookie after single sign-on while remember-me is off', function (string $principal): void {
    $account = $principal === 'user'
        ? User::factory()->synced()->withRole(Role::Agent)->create(['email' => 'p@corp.hu'])
        : Client::factory()->synced()->create(['email' => 'p@corp.hu']);
    $rememberToken = $account->remember_token;

    $response = completeSso($principal, 'adfs', ['aud' => "{$principal}-app", 'email' => 'p@corp.hu']);

    expect(collect($response->headers->getCookies())->filter(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_')))->toBeEmpty()
        ->and($account->fresh()->remember_token)->toBe($rememberToken);
})->with(['user', 'client']);

it('remembers a single sign-on for the configured days once remember-me is switched on', function (string $principal): void {
    $type = $principal === 'user' ? 'User' : 'Client';
    app(Settings::class)->set(constant(SettingKey::class.'::'.$type.'LoginRememberEnabled'), true);
    app(Settings::class)->set(constant(SettingKey::class.'::'.$type.'LoginRememberDays'), 30);
    $principal === 'user'
        ? User::factory()->synced()->withRole(Role::Agent)->create(['email' => 'p@corp.hu'])
        : Client::factory()->synced()->create(['email' => 'p@corp.hu']);

    $response = completeSso($principal, 'adfs', ['aud' => "{$principal}-app", 'email' => 'p@corp.hu']);

    $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_'));
    expect($cookie)->not->toBeNull()
        ->and(abs($cookie->getExpiresTime() - (time() + 30 * 86400)))->toBeLessThan(60);
})->with(['user', 'client']);

it('ends a staff session after the absolute lifetime and when access is revoked', function (): void {
    $user = User::factory()->synced()->withRole(Role::Agent)->create(['email' => 'agent@corp.hu']);
    completeSso('user', 'adfs', ['aud' => 'user-app', 'email' => 'agent@corp.hu'])->assertRedirect('/admin');

    $this->travel(11 * 60 + 59)->minutes();
    $this->get('/admin')->assertOk();
    $this->travel(2)->minutes();
    $this->get('/admin')->assertRedirect('/admin/login');

    completeSso('user', 'adfs', ['aud' => 'user-app', 'email' => 'agent@corp.hu']);
    $this->get('/admin')->assertOk();
    $user->revokeAccess();
    Auth::forgetGuards();
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('audits a forged callback and an invalid ID token as rejected logins', function (): void {
    FakeOidc::fake([]);
    $this->get('/auth/client/adfs/redirect');
    $this->get('/auth/client/adfs/callback?code=abc&state=forged');
    completeSso('client', 'adfs', ['aud' => 'someone-else', 'email' => 'c@x.hu']);

    expect(AuditLog::query()->where('event', 'login.rejected')->get()->pluck('context.detail')->all())
        ->toBe(['state_or_code', 'invalid_token']);
});

it('keeps single sign-on open for a staff account whose password login is locked', function (): void {
    $user = User::factory()->synced()->withRole(Role::Agent)->create(['email' => 'agent@corp.hu', 'locked_until' => now()->addDay()]);

    completeSso('user', 'adfs', ['aud' => 'user-app', 'email' => 'agent@corp.hu'])->assertRedirect('/admin');

    $this->assertAuthenticatedAs($user, 'web');
    expect(AuditLog::query()->where('event', 'login.succeeded')->where('subject_id', $user->id)->exists())->toBeTrue();
});

it('signs a remembered browser in again after the absolute session limit while remember-me is on, but not after revocation', function (): void {
    app(Settings::class)->set(SettingKey::UserLoginRememberEnabled, true);
    $user = User::factory()->synced()->withRole(Role::Agent)->create(['email' => 'agent@corp.hu']);
    completeSso('user', 'adfs', ['aud' => 'user-app', 'email' => 'agent@corp.hu']);
    $guard = Auth::guard('web');
    $recaller = fn (): array => [$guard->getRecallerName() => encrypt(CookieValuePrefix::create($guard->getRecallerName(), app('encrypter')->getKey()).$user->getKey().'|'.$user->fresh()->remember_token.'|'.$guard->hashPasswordForCookie((string) $user->getAuthPassword()), false)];
    $cookie = $recaller();

    $this->travel(13)->hours();
    Auth::forgetGuards();
    $this->call('GET', '/admin', [], $cookie)->assertOk();
    $this->assertAuthenticatedAs($user, 'web');

    $user->revokeAccess();
    Auth::forgetGuards();
    $this->flushSession();
    $this->call('GET', '/admin', [], $cookie)->assertRedirect('/admin/login');
});
