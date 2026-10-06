<?php

use App\Auth\Role;
use App\Enums\PhoneNumberSource;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Pages\Auth\Login;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\FakeSmsSender;
use App\Sms\SmsSender;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    RateLimiter::clear('livewire-rate-limiter:'.sha1(Login::class.'|authenticate|127.0.0.1'));
});

function passwordAttempt(string $email, string $password)
{
    // Filament limits attempts per network address; this test is about the account.
    RateLimiter::clear('livewire-rate-limiter:'.sha1(Login::class.'|authenticate|127.0.0.1'));
    Auth::guard('web')->logout();

    return Livewire::test(Login::class)->fillForm(['email' => $email, 'password' => $password])->call('authenticate');
}

it('locks the password login after five wrong passwords in a row for two hours, audited', function (): void {
    $user = User::factory()->synced()->withRole(Role::Agent)->create(['password' => 'right-password-1']);

    foreach (range(1, 5) as $attempt) {
        passwordAttempt($user->email, 'wrong-password')->assertHasFormErrors(['email']);
    }

    expect($user->fresh()->isLoginLocked())->toBeTrue()
        ->and($user->fresh()->locked_until->diffInMinutes(now()->addMinutes(120)))->toBeLessThan(1);
    passwordAttempt($user->email, 'right-password-1')->assertHasFormErrors(['email'])->assertSee('locked');
    $this->assertGuest('web');

    $rejected = AuditLog::query()->where('event', 'login.rejected')->where('subject_id', $user->id)->get();
    expect($rejected->where('context.reason', 'invalid_credentials'))->toHaveCount(5)
        ->and($rejected->where('context.reason', 'account_locked'))->toHaveCount(1)
        ->and($rejected->pluck('context.failed_attempts')->filter()->values()->all())->toBe([1, 2, 3, 4, 5])
        ->and(AuditLog::query()->where('event', 'account.locked')->sole()->context)->toMatchArray(['method' => 'password', 'failed_attempts' => 5]);

    $this->travel(121)->minutes();
    passwordAttempt($user->email, 'right-password-1')->assertHasNoFormErrors();
    $this->assertAuthenticatedAs($user, 'web');
});

it('counts only wrong passwords in a row and audits unknown addresses too', function (): void {
    $user = User::factory()->synced()->withRole(Role::Agent)->create(['password' => 'right-password-1']);

    foreach (range(1, 4) as $attempt) {
        passwordAttempt($user->email, 'wrong-password');
    }
    passwordAttempt($user->email, 'right-password-1')->assertHasNoFormErrors();
    passwordAttempt($user->email, 'wrong-password');

    expect($user->fresh()->failed_login_attempts)->toBe(1)->and($user->fresh()->isLoginLocked())->toBeFalse();

    passwordAttempt('nobody@corp.hu', 'whatever');
    expect(AuditLog::query()->where('event', 'login.rejected')->whereNull('subject_id')->sole()->context)
        ->toMatchArray(['principal' => 'user', 'method' => 'password', 'reason' => 'invalid_credentials', 'email' => 'nobody@corp.hu']);
});

it('follows the configured attempts and lockout time', function (): void {
    app(Settings::class)->set(SettingKey::UserLoginLockoutMaxAttempts, 2);
    app(Settings::class)->set(SettingKey::UserLoginLockoutMinutes, 30);
    $user = User::factory()->synced()->withRole(Role::Agent)->create(['password' => 'right-password-1']);

    passwordAttempt($user->email, 'wrong-password');
    passwordAttempt($user->email, 'wrong-password');

    expect($user->fresh()->locked_until->diffInMinutes(now()->addMinutes(30)))->toBeLessThan(1);
});

it('lets an administrator unlock a staff account, audited', function (): void {
    $admin = User::factory()->synced()->withRole(Role::Admin)->create();
    $locked = User::factory()->synced()->withRole(Role::Agent)->create(['locked_until' => now()->addDay(), 'failed_login_attempts' => 0]);
    $open = User::factory()->synced()->withRole(Role::Agent)->create();
    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $open->id])->assertActionVisible('unlockLogin')->assertActionDisabled('unlockLogin');
    Livewire::test(EditUser::class, ['record' => $locked->id])->callAction('unlockLogin');

    expect($locked->fresh()->isLoginLocked())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'account.unlocked')->sole())
        ->subject_id->toBe($locked->id)
        ->actor_id->toBe($admin->id);
});

it('hides the unlock from staff without the management permission', function (): void {
    $this->actingAs(User::factory()->synced()->withRole(Role::Supervisor)->create());

    Livewire::test(ViewClient::class, ['record' => Client::factory()->synced()->create(['locked_until' => now()->addDay()])->id])
        ->assertActionHidden('unlockLogin');
});

it('locks the SMS login of a client after five wrong codes without naming the lock, sends it no more codes, and lets the helpdesk unlock it', function (): void {
    $this->withHeader('Referer', config('app.url'))->withoutMiddleware(ThrottleRequests::class);
    $sms = new FakeSmsSender;
    app()->instance(SmsSender::class, $sms);
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    // The code outlives the five guesses, so the lock itself is what refuses the right code.
    app(Settings::class)->set(SettingKey::ClientLoginOtpMaxAttempts, 10);
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);

    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();
    preg_match('/(\d{6})/', $sms->lastTo('+36301234567'), $code);
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'c@x.hu', 'code' => '000000'])->assertForbidden()->assertJsonPath('reason', 'invalid_credentials');
    }

    expect($client->fresh()->isLoginLocked())->toBeTrue();

    // Locked: the answer stays neutral, the audit names the lock, and no code is sent.
    $this->travel(2)->minutes();
    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'c@x.hu'])->assertOk();
    expect($sms->sent)->toHaveCount(1);
    $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'c@x.hu', 'code' => $code[1]])
        ->assertForbidden()->assertJsonPath('reason', 'invalid_credentials');
    $this->assertGuest('client');
    expect(AuditLog::query()->where('event', 'login.rejected')->where('context->reason', 'account_locked')->get()->map(fn (AuditLog $log) => $log->context['detail'] ?? null)->all())->toBe(['code_request', null]);

    $this->actingAs(User::factory()->synced()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');
    Livewire::test(ViewClient::class, ['record' => $client->id])->callAction('unlockLogin');
    Auth::guard('web')->logout();

    $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'c@x.hu', 'code' => $code[1]])->assertOk();
    $this->assertAuthenticatedAs($client, 'client');
    expect(AuditLog::query()->where('event', 'account.locked')->sole()->context['method'])->toBe('otp_sms');
});

it('never counts a code posted for a client that has no live code, so a stranger can neither lock the SMS login nor tell the account from an unknown address', function (): void {
    $this->withHeader('Referer', config('app.url'))->withoutMiddleware(ThrottleRequests::class);
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);

    foreach (range(1, 6) as $attempt) {
        $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'c@x.hu', 'code' => '000000'])->assertForbidden()->assertJsonPath('reason', 'invalid_credentials');
        $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'nobody@x.hu', 'code' => '000000'])->assertForbidden()->assertJsonPath('reason', 'invalid_credentials');
    }

    expect($client->fresh()->isLoginLocked())->toBeFalse()
        ->and($client->fresh()->failed_login_attempts)->toBe(0)
        ->and(AuditLog::query()->where('event', 'account.locked')->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'login.rejected')->where('context->detail', 'no_live_code')->count())->toBe(6)
        ->and(AuditLog::query()->where('event', 'login.rejected')->where('context->detail', 'unknown_email')->count())->toBe(6);
});

it('rate-limits the locked and the disabled branches of the panel login like a wrong password', function (): void {
    $user = User::factory()->synced()->withRole(Role::Agent)->create(['password' => 'right-password-1', 'locked_until' => now()->addDay()]);

    foreach (range(1, 5) as $attempt) {
        Auth::guard('web')->logout();
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'right-password-1'])->call('authenticate')->assertHasFormErrors(['email']);
    }
    Auth::guard('web')->logout();
    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'right-password-1'])->call('authenticate')
        ->assertHasNoFormErrors()->assertNotified();

    expect(AuditLog::query()->where('event', 'login.rejected')->where('context->reason', 'account_locked')->count())->toBe(5);
});

it('audits a correct password on a closed staff account as closed, without counting it as a wrong password', function (): void {
    $user = User::factory()->synced()->withRole(Role::Agent)->closed()->create(['password' => 'right-password-1']);

    foreach (range(1, 5) as $attempt) {
        passwordAttempt($user->email, 'right-password-1')->assertHasFormErrors(['email']);
    }

    expect($user->fresh()->isLoginLocked())->toBeFalse()
        ->and($user->fresh()->failed_login_attempts)->toBe(0)
        ->and(AuditLog::query()->where('event', 'login.rejected')->where('subject_id', $user->id)->get()->pluck('context.reason')->unique()->all())->toBe(['account_closed']);
});
