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
