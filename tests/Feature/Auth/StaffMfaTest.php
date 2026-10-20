<?php

use App\Auth\Mfa\MfaException;
use App\Auth\Mfa\StaffMfa;
use App\Auth\Role;
use App\Enums\MfaMethod;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Pages\Auth\MfaChallenge;
use App\Filament\Pages\Auth\MfaSetup;
use App\Http\Middleware\EnsureStaffMfa;
use App\Messaging\Channel;
use App\Messaging\Messenger;
use App\Models\AuditLog;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\SettingRules;
use App\Settings\Settings;
use App\System\CheckStatus;
use App\System\HealthChecks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\FakeOidc;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Queue::fake();
    $settings = app(Settings::class);
    $settings->set(SettingKey::UserLoginMfaEnabled, true);
    $settings->set(SettingKey::UserLoginMfaMethods, ['app', 'sms', 'email']);
    $this->agent = User::factory()->synced()->withRole(Role::Agent)->create(['email' => 'agent@corp.hu']);
    $this->agent->externalRecord->update(['phones' => ['+36301234567', '+3612345678']]);
    $this->mfa = app(StaffMfa::class);
});

afterEach(fn () => FakeOidc::reset());

/** The code of the last SMS or e-mail StaffMfa queued (the job is faked, so the body is still there). */
function lastSentCode(): string
{
    preg_match('/\d{6}/', (string) OutboundMessage::query()->latest('id')->firstOrFail()->body, $match);

    return $match[0];
}

function withAppFactor(User $user): string
{
    $secret = app(Google2FA::class)->generateSecretKey(16);
    $user->forceFill(['mfa_app_secret' => $secret])->save();

    return $secret;
}

function totp(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

it('leaves the panel open without a second factor while two-factor sign-in is off', function (): void {
    app(Settings::class)->set(SettingKey::UserLoginMfaEnabled, false);

    $this->actingAs($this->agent)->get('/admin')->assertOk();
});

it('stops a session without a second factor at every panel page and staff route, the ones open before it too', function (): void {
    $this->actingAs($this->agent);

    $this->get('/admin')->assertRedirect(route('filament.admin.mfa.setup'));
    $this->get('/admin/clients')->assertRedirect(route('filament.admin.mfa.setup'));
    $this->post(route('tier.switch', 'premium'))->assertForbidden();

    withAppFactor($this->agent);
    $this->get('/admin/calls')->assertRedirect(route('filament.admin.mfa.challenge'));
    $this->get(route('filament.admin.mfa.challenge'))->assertOk()->assertDontSee('hdid-tier-pill');

    $admin = User::factory()->synced()->withRole(Role::Admin)->create();
    $this->actingAs($admin)->get(route('scramble.ivr.ui'))->assertForbidden();
});

it('stops Livewire updates of panel pages too: the check is persistent panel middleware', function (): void {
    expect(Livewire::getPersistentMiddleware())->toContain(EnsureStaffMfa::class);

    $request = Request::create('/livewire/update', 'POST');
    $request->headers->set('X-Livewire', 'true');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $this->agent);
    Auth::guard('web')->setUser($this->agent);

    expect(fn () => app(EnsureStaffMfa::class)->handle($request, fn () => response('passed')))->toThrow(AccessDeniedHttpException::class);
});

it('lets the session in with a valid authenticator code, once per code, and asks again after every new login', function (): void {
    $secret = withAppFactor($this->agent);
    $this->actingAs($this->agent);
    $code = totp($secret);

    Livewire::test(MfaChallenge::class)->assertSet('method', 'app')->set('code', $code)->call('verify')->assertHasNoErrors()->assertRedirect();
    $this->get('/admin')->assertOk();

    // A new login of any kind (form, SSO, remember-me cookie) starts without the second factor.
    Auth::guard('web')->login($this->agent);
    $this->get('/admin')->assertRedirect(route('filament.admin.mfa.challenge'));

    // The same code does not pass twice.
    Livewire::test(MfaChallenge::class)->set('code', $code)->call('verify')->assertHasErrors('code');
    $this->get('/admin')->assertRedirect(route('filament.admin.mfa.challenge'));
    expect(AuditLog::query()->where('event', 'mfa.passed')->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'mfa.failed')->count())->toBe(1);
});

it('locks the second factor after the configured number of wrong codes, apart from the password lock', function (): void {
    $secret = withAppFactor($this->agent);
    app(Settings::class)->set(SettingKey::UserLoginLockoutMaxAttempts, 3);
    $this->actingAs($this->agent);

    foreach (range(1, 3) as $attempt) {
        expect(fn () => $this->mfa->verify($this->agent, MfaMethod::App, '000000'))->toThrow(MfaException::class);
    }

    expect($this->mfa->isLocked($this->agent->fresh()))->toBeTrue()
        ->and($this->agent->fresh()->isLoginLocked())->toBeFalse()
        ->and(fn () => $this->mfa->verify($this->agent->fresh(), MfaMethod::App, totp($secret)))->toThrow(MfaException::class, 'locked')
        ->and(AuditLog::query()->where('event', 'account.mfa_locked')->count())->toBe(1);
});

it('wipes a sent sign-in code from the outbound message once it is out', function (): void {
    $message = app(Messenger::class)->sendRaw(Channel::Sms, '+36301234567', null, 'Belépési kód: 123456', carriesSecret: true);

    expect($message->carriesSecret())->toBeTrue();
    $message->redactSecret();
    expect($message->fresh()->body)->toBe('')->and($message->fresh()->isRedacted())->toBeTrue();
});

it('binds a sent code to the session that asked for it', function (): void {
    $this->agent->forceFill(['mfa_email_enabled_at' => now()])->save();
    $this->actingAs($this->agent);

    expect($this->mfa->sendCode($this->agent, MfaMethod::Email, StaffMfa::PURPOSE_CHALLENGE))->toBe('a***@corp.hu');
    $code = lastSentCode();

    // Another browser signed in with the same password has another session.
    session()->migrate(true);
    expect(fn () => $this->mfa->verify($this->agent, MfaMethod::Email, $code))->toThrow(MfaException::class)
        ->and($this->mfa->isSatisfied($this->agent))->toBeFalse();
});

it('passes with a sent code once, and refuses it again', function (): void {
    $this->agent->forceFill(['mfa_email_enabled_at' => now()])->save();
    $this->actingAs($this->agent);
    $this->mfa->sendCode($this->agent, MfaMethod::Email, StaffMfa::PURPOSE_CHALLENGE);
    $code = lastSentCode();

    $this->mfa->verify($this->agent, MfaMethod::Email, $code);
    expect($this->mfa->isSatisfied($this->agent))->toBeTrue();

    $this->mfa->forget();
    expect(fn () => $this->mfa->verify($this->agent, MfaMethod::Email, $code))->toThrow(MfaException::class);
});

it('needs the e-mail code too before a first authenticator app is registered, so a password alone is not enough', function (): void {
    $this->actingAs($this->agent);
    ['secret' => $secret, 'qr' => $qr] = $this->mfa->beginAppSetup($this->agent);
    expect($qr)->toStartWith('data:image/');

    expect(fn () => $this->mfa->confirmAppSetup($this->agent, totp($secret)))->toThrow(MfaException::class)
        ->and($this->agent->fresh()->mfa_app_secret)->toBeNull();

    $this->mfa->sendCode($this->agent, MfaMethod::Email, StaffMfa::PURPOSE_BOOTSTRAP);
    $this->mfa->confirmAppSetup($this->agent, totp($secret), lastSentCode());

    expect($this->agent->fresh()->mfa_app_secret)->toBe($secret)
        ->and($this->mfa->isSatisfied($this->agent))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'mfa.factor_added')->sole()->context)->toBe(['method' => 'app'])
        ->and(json_encode(AuditLog::query()->pluck('context')))->not->toContain($secret);
});

it('sends SMS codes only to a mobile number of the EMD record and drops the factor when the number leaves it', function (): void {
    $this->actingAs($this->agent);

    expect($this->mfa->smsNumbers($this->agent))->toBe(['+36301234567'])
        ->and(fn () => $this->mfa->sendCode($this->agent, MfaMethod::Sms, StaffMfa::PURPOSE_SETUP, '+36309999999'))->toThrow(MfaException::class)
        ->and(fn () => $this->mfa->sendCode($this->agent, MfaMethod::Sms, StaffMfa::PURPOSE_SETUP, '+3612345678'))->toThrow(MfaException::class);

    $this->mfa->sendCode($this->agent, MfaMethod::Sms, StaffMfa::PURPOSE_SETUP, '+36301234567');
    expect(OutboundMessage::query()->latest('id')->first()->only(['recipient', 'channel']))->toMatchArray(['recipient' => '+36301234567']);
    $this->mfa->confirmSentSetup($this->agent, MfaMethod::Sms, lastSentCode());
    expect($this->mfa->enrolledMethods($this->agent->fresh()))->toBe([MfaMethod::Sms]);

    $this->agent->externalRecord->update(['phones' => ['+36307777777']]);
    expect($this->mfa->enrolledMethods($this->agent->fresh()->load('externalRecord')))->toBe([]);
});

it('does not let a session without the second factor change the factors of an account that has one', function (): void {
    withAppFactor($this->agent);
    $this->actingAs($this->agent);

    expect(fn () => $this->mfa->beginAppSetup($this->agent))->toThrow(MfaException::class)
        ->and(fn () => $this->mfa->sendCode($this->agent, MfaMethod::Email, StaffMfa::PURPOSE_BOOTSTRAP))->toThrow(MfaException::class)
        ->and(fn () => $this->mfa->sendCode($this->agent, MfaMethod::Sms, StaffMfa::PURPOSE_SETUP, '+36301234567'))->toThrow(MfaException::class)
        ->and(fn () => $this->mfa->removeFactor($this->agent, MfaMethod::App))->toThrow(MfaException::class);
    Livewire::test(MfaSetup::class)->assertRedirect(route('filament.admin.mfa.challenge'));
    expect($this->agent->fresh()->mfa_app_secret)->not->toBeNull()
        ->and(OutboundMessage::query()->count())->toBe(0);
});

it('accepts the provider MFA proven by the verified SSO token while the setting allows it', function (bool $trust, array $amr, bool $passes): void {
    config()->set('hdid.oidc.user.adfs', ['issuer' => FakeOidc::ISSUER, 'client_id' => 'user-app', 'client_secret' => 's']);
    app(Settings::class)->set(SettingKey::UserSsoAdfsEnabled, true);
    app(Settings::class)->set(SettingKey::UserLoginMfaTrustProvider, $trust);
    withAppFactor($this->agent);
    FakeOidc::fake(['aud' => 'user-app', 'email' => 'agent@corp.hu', 'amr' => $amr]);

    $redirect = $this->get('/auth/user/adfs/redirect');
    parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
    FakeOidc::$nonce = session('oidc.pending')['nonce'];
    $this->get('/auth/user/adfs/callback?code=abc&state='.$query['state'])->assertRedirect('/admin');

    $this->assertAuthenticatedAs($this->agent, 'web');
    $passes
        ? $this->get('/admin')->assertOk()
        : $this->get('/admin')->assertRedirect(route('filament.admin.mfa.challenge'));
})->with([
    'Entra-style mfa' => [true, ['pwd', 'mfa'], true],
    'ADFS multipleauthn' => [true, ['http://schemas.microsoft.com/claims/multipleauthn'], true],
    'password only at the provider' => [true, ['pwd'], false],
    'provider MFA not trusted' => [false, ['pwd', 'mfa'], false],
]);

it('offers SMS in production only with a real gateway, and refuses switching on without a method', function (): void {
    $this->app['env'] = 'production';
    config()->set('hdid.sms.driver', 'log');

    expect(SettingRules::mfaErrors([SettingKey::UserLoginMfaMethods->value => ['sms']]))->toHaveKey(SettingKey::UserLoginMfaMethods->value)
        ->and(app(StaffMfa::class)->allowedMethods())->toBe([MfaMethod::App, MfaMethod::Email]);

    config()->set('hdid.sms.driver', 'ozeki');
    expect(SettingRules::mfaErrors([SettingKey::UserLoginMfaMethods->value => ['sms']]))->toBe([])
        ->and(SettingRules::mfaErrors([SettingKey::UserLoginMfaEnabled->value => true, SettingKey::UserLoginMfaMethods->value => []]))->not->toBe([]);
    $this->app['env'] = 'testing';
});

it('lets an administrator reset the second factor of a colleague, signing them out, but not of a super-admin', function (): void {
    withAppFactor($this->agent);
    $admin = User::factory()->synced()->withRole(Role::Admin)->create();
    $superAdmin = User::factory()->synced()->withRole(Role::SuperAdmin)->create();
    withAppFactor($superAdmin);
    $this->actingAs($admin);
    session()->put('hdid_mfa_passed', ['user' => $admin->id, 'epoch' => (int) $admin->session_epoch]);
    $epoch = (int) $this->agent->session_epoch;

    Livewire::test(ListUsers::class)->callTableAction('resetMfa', $this->agent);

    expect($this->agent->fresh()->mfa_app_secret)->toBeNull()
        ->and((int) $this->agent->fresh()->session_epoch)->toBeGreaterThan($epoch)
        ->and(AuditLog::query()->where('event', 'mfa.reset')->where('subject_id', $this->agent->id)->sole()->actor_id)->toBe($admin->id);

    Livewire::test(ListUsers::class)->assertTableActionHidden('resetMfa', $superAdmin);
    expect($superAdmin->fresh()->mfa_app_secret)->not->toBeNull();
});

it('clears a factor and switches two-factor sign-in off from the command line', function (): void {
    withAppFactor($this->agent);

    $this->artisan('hdid:mfa-reset', ['email' => 'agent@corp.hu'])->assertSuccessful();
    expect($this->agent->fresh()->mfa_app_secret)->toBeNull();

    $this->artisan('hdid:mfa-reset', ['--off' => true])->assertSuccessful();
    expect(app(Settings::class)->bool(SettingKey::UserLoginMfaEnabled))->toBeFalse();
});

it('shows on the system status who has no second factor yet', function (): void {
    app(Settings::class)->set(SettingKey::UserLoginMfaTrustProvider, false);
    expect(app(HealthChecks::class)->staffMfa()->status)->toBe(CheckStatus::Warn);

    User::query()->each(fn (User $user) => withAppFactor($user));
    expect(app(HealthChecks::class)->staffMfa()->status)->toBe(CheckStatus::Ok);

    app(Settings::class)->set(SettingKey::UserLoginMfaMethods, []);
    expect(app(HealthChecks::class)->staffMfa()->status)->toBe(CheckStatus::Fail);
});

it('spends a sent code after the allowed number of tries, even before the account lock', function (): void {
    app(Settings::class)->set(SettingKey::UserLoginLockoutMaxAttempts, 20);
    $this->agent->forceFill(['mfa_email_enabled_at' => now()])->save();
    $this->actingAs($this->agent);
    $this->mfa->sendCode($this->agent, MfaMethod::Email, StaffMfa::PURPOSE_CHALLENGE);
    $code = lastSentCode();

    foreach (range(1, StaffMfa::CODE_MAX_ATTEMPTS) as $attempt) {
        expect(fn () => $this->mfa->verify($this->agent, MfaMethod::Email, $code === '000000' ? '111111' : '000000'))->toThrow(MfaException::class);
    }

    expect(fn () => $this->mfa->verify($this->agent, MfaMethod::Email, $code))->toThrow(MfaException::class)
        ->and($this->mfa->isSatisfied($this->agent))->toBeFalse();
});

it('walks a staff member without a factor through the setup page and the challenge page through their methods', function (): void {
    $this->actingAs($this->agent);

    $setup = Livewire::test(MfaSetup::class)
        ->assertSee(MfaMethod::App->label())->assertSee(MfaMethod::Sms->label())->assertSee(MfaMethod::Email->label())
        ->call('start', 'app')
        ->assertSet('setting', 'app')
        ->assertSee(__('To prove the account is yours, we also send a code to your e-mail address; a password alone is not enough to register an app.'));
    expect($setup->get('qr'))->toStartWith('data:image/');

    $setup->call('start', 'email')->call('sendCode')->assertSet('sentTo', 'a***@corp.hu')
        ->set('code', lastSentCode())->call('confirm')->assertHasNoErrors();
    expect($this->mfa->isSatisfied($this->agent->fresh()))->toBeTrue();

    withAppFactor($this->agent);
    $this->mfa->forget();
    Livewire::test(MfaChallenge::class)
        ->assertSet('method', null)
        ->assertSee(MfaMethod::App->label())
        ->call('choose', 'email')->assertSet('method', 'email')
        ->call('choose', 'sms')->assertSet('method', null);
});
