<?php

use App\Auth\Role;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * A browser holding nothing but a remember-me cookie: the guard cache and
 * the session are reset so only the cookie can sign the account in. The
 * password part is what the guard derives from the account, unless given.
 *
 * @return array<string, string>
 */
function rememberCookieFor(string $guard, Authenticatable $account, string $rememberToken, ?string $passwordPart = null): array
{
    $sessionGuard = Auth::guard($guard);
    $name = $sessionGuard->getRecallerName();
    $value = $account->getAuthIdentifier().'|'.$rememberToken.'|'.($passwordPart ?? $sessionGuard->hashPasswordForCookie($account->getAuthPassword()));

    Auth::forgetGuards();
    test()->flushSession();

    return [$name => encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$value, false)];
}

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(Settings::class)->set(SettingKey::UserLoginRememberEnabled, true);
    app(Settings::class)->set(SettingKey::ClientLoginRememberEnabled, true);
});

it('signs a staff account without a password (single sign-on only) in again from its remember-me cookie', function (): void {
    $user = User::factory()->synced()->withRole(Role::Agent)->create(['email' => 'sso.only@corp.hu', 'password' => null]);
    $user->forceFill(['remember_token' => $token = Str::random(60)])->saveQuietly();

    $this->call('GET', '/admin', [], rememberCookieFor('web', $user, $token))->assertOk();

    $this->assertAuthenticatedAs($user->fresh(), 'web');
});

it('signs a client in again from its remember-me cookie', function (): void {
    $client = Client::factory()->synced()->create();
    $client->forceFill(['remember_token' => $token = Str::random(60)])->saveQuietly();

    $this->call('GET', '/api/v1/client/me', [], rememberCookieFor('client', $client, $token), [], ['HTTP_ACCEPT' => 'application/json'])
        ->assertOk()->assertJsonPath('data.id', $client->id);
});

it('refuses a remember-me cookie with a foreign password part for accounts without a password, without a server error', function (): void {
    $user = User::factory()->synced()->withRole(Role::Agent)->create(['email' => 'sso.only@corp.hu', 'password' => null]);
    $user->forceFill(['remember_token' => $userToken = Str::random(60)])->saveQuietly();
    $client = Client::factory()->synced()->create();
    $client->forceFill(['remember_token' => $clientToken = Str::random(60)])->saveQuietly();
    // For instance a cookie issued while the account still had a password.
    $stale = Auth::guard('web')->hashPasswordForCookie(bcrypt('a-former-password'));

    $this->call('GET', '/admin', [], rememberCookieFor('web', $user, $userToken, $stale))->assertRedirect('/admin/login');
    $this->assertGuest('web');

    $this->call('GET', '/api/v1/client/me', [], rememberCookieFor('client', $client, $clientToken, $stale), [], ['HTTP_ACCEPT' => 'application/json'])
        ->assertUnauthorized();
    $this->assertGuest('client');
});

it('refuses a remember-me cookie another installation with the same key issued, with or without a password', function (): void {
    $staff = User::factory()->synced()->withRole(Role::Agent)->create();
    $staff->forceFill(['remember_token' => $staffToken = Str::random(60)])->saveQuietly();
    $ssoOnly = User::factory()->synced()->withRole(Role::Agent)->create(['email' => 'sso.only@corp.hu', 'password' => null]);
    $ssoOnly->forceFill(['remember_token' => $ssoToken = Str::random(60)])->saveQuietly();
    $client = Client::factory()->synced()->create();
    $client->forceFill(['remember_token' => $clientToken = Str::random(60)])->saveQuietly();
    config()->set('app.url', 'https://hdid.example.test');

    // A clone of the database at another address with the same APP_KEY issued the cookies.
    config()->set('app.url', 'https://staging.example.test');
    Auth::forgetGuards();
    $cookies = [
        'staff' => rememberCookieFor('web', $staff, $staffToken),
        'sso' => rememberCookieFor('web', $ssoOnly, $ssoToken),
        'client' => rememberCookieFor('client', $client, $clientToken),
    ];

    config()->set('app.url', 'https://hdid.example.test');
    foreach (['staff', 'sso'] as $account) {
        Auth::forgetGuards();
        $this->flushSession();
        $this->call('GET', '/admin', [], $cookies[$account])->assertRedirect('/admin/login');
        $this->assertGuest('web');
    }

    Auth::forgetGuards();
    $this->flushSession();
    $this->call('GET', '/api/v1/client/me', [], $cookies['client'], [], ['HTTP_ACCEPT' => 'application/json'])->assertUnauthorized();
    $this->assertGuest('client');

    // The installation's own cookie still signs in.
    Auth::forgetGuards();
    $this->call('GET', '/api/v1/client/me', [], rememberCookieFor('client', $client, $clientToken), [], ['HTTP_ACCEPT' => 'application/json'])->assertOk();
});
