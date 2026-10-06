<?php

use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Auth\Role;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->withHeader('Referer', config('app.url'));
    Mail::fake();
});

function clientSignedInByLink(): Client
{
    $client = Client::factory()->synced()->create();
    signInWithLink(app(ClientPasswordlessLogin::class)->issueMagicLink($client))->assertOk();

    return $client;
}

/**
 * GET /me as the browser that signed in: with its session cookie (the test
 * client does not keep cookies for the database store) and a fresh guard,
 * as every production request has.
 */
function meAsBrowser(?string $sessionId = null): TestResponse
{
    Auth::forgetGuards();

    return $sessionId === null
        ? test()->getJson('/api/v1/client/me')
        : test()->withCookie(config('session.cookie'), $sessionId)->getJson('/api/v1/client/me');
}

it('ends the browser sessions of a client signed out everywhere, whatever the session store', function (string $driver): void {
    // Switch the store before anything signs in, so the guard and the
    // request share it, as in production.
    config()->set('session.driver', $driver);
    app('session')->setDefaultDriver($driver);
    app()->forgetInstance('session.store');
    Auth::forgetGuards();
    $client = clientSignedInByLink();
    $sessionId = $driver === 'database' ? DB::table('sessions')->value('id') : null;
    meAsBrowser($sessionId)->assertOk();
    if ($driver === 'database') {
        // The store cannot tell whose session this is: Laravel fills user_id from the staff guard only.
        expect(DB::table('sessions')->where('id', $sessionId)->value('user_id'))->toBeNull();
    }

    // "Sign out everywhere" pressed on another device.
    $client->revokeAccess();

    meAsBrowser($sessionId)->assertUnauthorized();
})->with(['database', 'array']);

it('ends a client browser session after the absolute lifetime, however active it is', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginSessionMaxHours, 2);
    clientSignedInByLink();

    $this->travel(119)->minutes();
    $this->getJson('/api/v1/client/me')->assertOk();

    $this->travel(2)->minutes();
    $this->getJson('/api/v1/client/me')->assertUnauthorized();
});

it('keeps the default absolute lifetime of a client session at 24 hours', function (): void {
    clientSignedInByLink();

    $this->travel(23)->hours();
    $this->getJson('/api/v1/client/me')->assertOk();

    $this->travel(2)->hours();
    $this->getJson('/api/v1/client/me')->assertUnauthorized();
});

it('still tells a closed client why it was signed out', function (): void {
    $client = clientSignedInByLink();
    $client->close('test');

    meAsBrowser()->assertForbidden()->assertJsonPath('reason', 'account_closed');
    meAsBrowser()->assertUnauthorized();
});

it('signs only the affected guard out of a shared browser session', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $staff = User::factory()->synced()->withRole(Role::Agent)->create();
    $client = clientSignedInByLink();
    Auth::guard('web')->login($staff);
    $this->get('/admin')->assertOk();

    // The client presses "sign out everywhere" on another device.
    $client->revokeAccess();
    Auth::forgetGuards();

    $this->get('/admin')->assertOk();
    $this->assertGuest('client');
    $this->assertAuthenticatedAs($staff, 'web');
});
