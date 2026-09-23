<?php

use App\Auth\LoginRejection;
use App\Auth\Role;
use App\Filament\Pages\Auth\Login;
use App\Models\AuditLog;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Tests\Support\FakeOidc;

it('shows the password form and no sso buttons by default', function (): void {
    $this->get('/admin/login')->assertOk()->assertSee('isPasswordRevealed')->assertDontSee('Company login');
});

it('puts company sign-on first and folds the password form behind a link', function (): void {
    config()->set('hdid.oidc.user.adfs', ['issuer' => FakeOidc::ISSUER, 'client_id' => 'user-app', 'client_secret' => 's']);
    app(Settings::class)->set(SettingKey::UserSsoAdfsEnabled, true);
    app(Settings::class)->set(SettingKey::UserSsoEntraEnabled, true); // enabled but not configured: hidden

    $this->get('/admin/login')->assertOk()
        ->assertSee('Company login (ADFS)')
        ->assertSee(route('sso.redirect', ['principal' => 'user', 'provider' => 'adfs']))
        ->assertSee('Sign in with a password instead')
        ->assertDontSee('isPasswordRevealed')
        ->assertDontSee('Microsoft login');

    Livewire::test(Login::class)
        ->set('showPasswordForm', true)
        ->assertSee('isPasswordRevealed')
        ->assertDontSee('Sign in with a password instead');
});

it('offers no password link when passwords are disabled and sso is on', function (): void {
    config()->set('hdid.oidc.user.adfs', ['issuer' => FakeOidc::ISSUER, 'client_id' => 'user-app', 'client_secret' => 's']);
    app(Settings::class)->set(SettingKey::UserSsoAdfsEnabled, true);
    app(Settings::class)->set(SettingKey::UserLoginPasswordEnabled, false);

    $this->get('/admin/login')->assertOk()
        ->assertSee('Company login (ADFS)')
        ->assertDontSee('Sign in with a password instead')
        ->assertDontSee('isPasswordRevealed');
});

it('hides the password form when disabled', function (): void {
    app(Settings::class)->set(SettingKey::UserLoginPasswordEnabled, false);

    $this->get('/admin/login')->assertOk()->assertDontSee('isPasswordRevealed');
});

it('refuses a password login through the livewire action when passwords are disabled', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(Settings::class)->set(SettingKey::UserLoginPasswordEnabled, false);
    $user = User::factory()->withRole(Role::Admin)->create(['password' => 'secret-pass-1']);

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'secret-pass-1'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
    expect(AuditLog::query()->where('event', 'login.succeeded')->exists())->toBeFalse()
        ->and($user->fresh()->last_login_at)->toBeNull();
});

it('stamps the last login and audits a password login', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->synced()->withRole(Role::Admin)->create(['password' => 'secret-pass-1', 'last_login_at' => null]);

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'secret-pass-1'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect($user->fresh()->last_login_at)->not->toBeNull()
        ->and(AuditLog::query()->where('event', 'login.succeeded')->where('actor_id', $user->id)->value('context')['method'])->toBe('password');
});

it('applies the one login rule to the password path as well', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $noRecord = User::factory()->withRole(Role::Admin)->create(['password' => 'secret-pass-1']);

    Livewire::test(Login::class)
        ->fillForm(['email' => $noRecord->email, 'password' => 'secret-pass-1'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
    expect(AuditLog::query()->where('event', 'login.rejected')->where('context->reason', LoginRejection::NoExternalRecord->value)->exists())->toBeTrue();

    // A directory record that carries another e-mail is refused the same way.
    $mismatch = User::factory()->synced()->withRole(Role::Admin)->create(['password' => 'secret-pass-1']);
    $mismatch->externalRecord->forceFill(['email' => 'someone.else@staff.hu'])->save();

    Livewire::test(Login::class)
        ->fillForm(['email' => $mismatch->email, 'password' => 'secret-pass-1'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
    expect(AuditLog::query()->where('event', 'login.rejected')->where('context->reason', LoginRejection::ExternalRecordMismatch->value)->exists())->toBeTrue();
});
