<?php

use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Auth\Role;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

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

it('ends the client and staff sessions another installation sharing the session store started, and its own sign-in still works', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    config()->set('app.url', 'https://hdid.example.test');
    Auth::forgetGuards();
    $staff = User::factory()->synced()->withRole(Role::Agent)->create();
    clientSignedInByLink();
    Auth::guard('web')->login($staff);
    meAsBrowser()->assertOk();
    Auth::forgetGuards();
    $this->get('/admin')->assertOk();

    // A copy of this installation (a cloned staging one: the same APP_KEY, another
    // address, so another installation id) reads the same session store.
    config()->set('app.url', 'https://staging.example.test');
    meAsBrowser()->assertUnauthorized();
    Auth::forgetGuards();
    $this->get('/admin')->assertRedirect('/admin/login');
    $this->assertGuest('web')->assertGuest('client');

    // The installation's own sign-in is not affected.
    config()->set('app.url', 'https://hdid.example.test');
    clientSignedInByLink();
    meAsBrowser()->assertOk();
});

it('keeps the sessions of one installation on all its servers, however their APP_URL writes the address', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    config()->set('app.url', 'https://hdid.example.test');
    Auth::forgetGuards();
    $staff = User::factory()->synced()->withRole(Role::Agent)->create();
    clientSignedInByLink();
    Auth::guard('web')->login($staff);

    // The second server of a two-host installation, its .env written a little differently.
    foreach (['https://HDID.example.test/', 'https://hdid.example.test:443', 'https://hdid.example.test:443/'] as $url) {
        config()->set('app.url', $url);
        meAsBrowser()->assertOk();
        Auth::forgetGuards();
        $this->get('/admin')->assertOk();
    }
});

it('signs a session without the installation stamp out once, as one from before the upgrade', function (): void {
    clientSignedInByLink();
    meAsBrowser()->assertOk();

    // What a session of an earlier release holds: the login, its start and the epoch, but no installation.
    session()->forget('auth_session.installation.client');

    meAsBrowser()->assertUnauthorized();
    clientSignedInByLink();
    meAsBrowser()->assertOk();
});

/**
 * Switches the session store before anything signs in, so the guard and the
 * request share it, as in production. The file store writes into a folder of
 * its own, removed after the test, not into the installation's storage.
 */
function useSessionStore(string $driver): void
{
    if ($driver === 'file') {
        $folder = sys_get_temp_dir().'/hdid-test-sessions-'.Str::random(12);
        File::ensureDirectoryExists($folder);
        config()->set('session.files', $folder);
        test()->beforeApplicationDestroyed(fn () => File::deleteDirectory($folder));
    }

    config()->set('session.driver', $driver);
    app('session')->setDefaultDriver($driver);
    app()->forgetInstance('session.store');
    Auth::forgetGuards();
}

/**
 * A staff member signed in through the session, as the login form does: the
 * sign-in stamps the session of the current request, so a request runs first.
 */
function staffSignedInBySession(User $user): void
{
    test()->get('/admin/login')->assertOk();
    Auth::guard('web')->login($user);
}

it('ends a closed staff member\'s session on the routes outside the panel, whatever the session store', function (string $driver): void {
    useSessionStore($driver);
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->synced()->withRole(Role::Admin)->create();
    staffSignedInBySession($admin);
    $this->get('/docs/ivr')->assertOk();

    // Closed by the sync or by a colleague while the browser stays open.
    $admin->close('admin');
    Auth::forgetGuards();

    $this->get('/docs/ivr')->assertForbidden();
    Auth::forgetGuards();
    $this->get('/docs/mobile')->assertForbidden();
    Auth::forgetGuards();
    $this->post(route('tier.switch', 'premium'))->assertRedirect(route('filament.admin.auth.login'));

    expect($admin->fresh()->active_tier)->toBeNull();
    $this->assertGuest('web');
})->with(['array', 'file', 'database']);

it('sends a closed staff member with a live session from the panel to the login page', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $agent = User::factory()->synced()->withRole(Role::Agent)->create();
    staffSignedInBySession($agent);
    $this->get('/admin')->assertOk();

    $agent->close('admin');
    Auth::forgetGuards();

    $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    $this->assertGuest('web');
});

it('tells a closed staff member on the login page, in their language, why the session ended, once', function (): void {
    $this->withUnencryptedCookie('locale', 'hu');
    $this->seed(RolesAndPermissionsSeeder::class);
    $agent = User::factory()->synced()->withRole(Role::Agent)->create();
    staffSignedInBySession($agent);
    $this->get('/admin')->assertOk();

    $agent->close('admin');
    Auth::forgetGuards();

    $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));

    // The login page pulls the waiting notification with a Livewire request once loaded.
    $this->get(route('filament.admin.auth.login'))->assertOk();
    $notices = Livewire::test(Notifications::class)->call('pullNotificationsFromSession')
        ->assertSee('Kijelentkezve.')
        ->assertSee('Ez a fiók le lett zárva.')
        ->html();

    // One notice, not one per session check or redirect on the way.
    expect(substr_count($notices, 'Ez a fiók le lett zárva.'))->toBe(1);

    Auth::forgetGuards();
    $this->get(route('filament.admin.auth.login'))->assertOk();
    Livewire::test(Notifications::class)->call('pullNotificationsFromSession')
        ->assertDontSee('Ez a fiók le lett zárva.');
});

it('says nothing of a closure when a staff session ends for another reason', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $agent = User::factory()->synced()->withRole(Role::Agent)->create();
    staffSignedInBySession($agent);
    $this->get('/admin')->assertOk();

    // Signed out everywhere from another device: the session ends, the account stays open.
    $agent->revokeAccess();
    Auth::forgetGuards();

    $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    $this->get(route('filament.admin.auth.login'))->assertOk();
    Livewire::test(Notifications::class)->call('pullNotificationsFromSession')
        ->assertDontSee(__('This account has been closed.'));
});

it('signs a closed staff member out of a shared browser session and keeps the client signed in', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $staff = User::factory()->synced()->withRole(Role::Agent)->create();
    $client = clientSignedInByLink();
    Auth::guard('web')->login($staff);
    $this->get('/admin')->assertOk();

    $staff->close('admin');
    Auth::forgetGuards();

    $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    $this->assertGuest('web');
    meAsBrowser()->assertOk()->assertJsonPath('data.id', $client->id);
});

it('refuses the interface documents to a closed administrator even without a session login', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->synced()->withRole(Role::Admin)->create();
    $this->actingAs($admin)->get('/docs/ivr')->assertOk();

    $admin->close('admin');

    $this->actingAs($admin->fresh())->get('/docs/ivr')->assertForbidden();
});
