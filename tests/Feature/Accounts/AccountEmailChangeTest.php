<?php

use App\Auth\AccountLogin;
use App\Auth\LoginRejectedException;
use App\Auth\Mfa\StaffMfa;
use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Auth\Role;
use App\Enums\PrincipalType;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Mail\MagicLinkMail;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\OneTimeCode;
use App\Models\SsoIdentity;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\AccountProvisioner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The e-mail is the account's identity. An administrator may change it by
 * hand only on a free account (not linked to an EMD record, no company
 * sign-in binding), after a confirmation that names what ends; the save
 * then signs the account out everywhere, and a staff account that the new
 * address hands to another person's EMD record loses what the previous
 * holder set up.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withHeader('Referer', config('app.url'));
    Mail::fake();
    $this->admin = User::factory()->withRole(Role::SuperAdmin)->create();
    Filament::setCurrentPanel('admin');
});

/**
 * Staff browser sessions in the database store, the only store that tells
 * whose session a row is (Laravel fills user_id from the staff guard).
 */
function staffSessionRow(User $user): void
{
    config()->set('session.driver', 'database');
    DB::table('sessions')->insert([
        'id' => 'staff-session-'.$user->id,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->getTimestamp(),
    ]);
}

it('locks the e-mail of an account linked to its EMD record or bound to a company sign-in, and a tampered form cannot change it', function (string $kind, string $lock, string $reason): void {
    $this->actingAs($this->admin);
    $account = match ($kind) {
        'user' => $lock === 'directory'
            ? User::factory()->synced()->withRole(Role::Agent)->create(['email' => 'bob@corp.hu'])
            : User::factory()->withRole(Role::Agent)->create(['email' => 'bob@corp.hu']),
        'client' => $lock === 'directory'
            ? Client::factory()->synced()->create(['email' => 'bob@client.hu'])
            : Client::factory()->create(['email' => 'bob@client.hu']),
    };
    if ($lock === 'sso') {
        SsoIdentity::factory()->forAccount($account)->create();
    }
    $email = $account->email;
    $epoch = (int) $account->session_epoch;

    Livewire::test($kind === 'user' ? EditUser::class : EditClient::class, ['record' => $account->getKey()])
        ->assertFormFieldIsDisabled('email')
        ->assertSee(__($reason, ['when' => '-']), escape: false)
        ->set('data.email', 'mas@corp.hu')
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('The e-mail was not changed'));

    $account->refresh();
    expect($account->email)->toBe($email)
        ->and((int) $account->session_epoch)->toBe($epoch)
        ->and(AuditLog::query()->where('event', 'account.access_revoked')->count())->toBe(0);
})->with([
    'staff linked to EMD' => ['user', 'directory', 'Comes from EMD; change it there. Last EMD sync: :when'],
    'staff with a sign-in binding' => ['user', 'sso', 'Cannot be changed while the company sign-in is bound to a directory identity: the address belongs to that identity. Clear the sign-in binding first if it really has to change.'],
    'client linked to EMD' => ['client', 'directory', 'Comes from EMD; change it there. Last EMD sync: :when'],
    'client with a sign-in binding' => ['client', 'sso', 'Cannot be changed while the company sign-in is bound to a directory identity: the address belongs to that identity. Clear the sign-in binding first if it really has to change.'],
]);

it('asks before saving a new e-mail of a free account, names what ends, and a plain submit only opens the question', function (): void {
    config()->set('hdid.client_login.require_emd', false);
    $client = Client::factory()->create(['email' => 'regi@client.hu']);
    app(ClientPasswordlessLogin::class)->issueMagicLink($client);
    app(AccountLogin::class)->issueToken($client, 'test', 'phone');
    $lastActive = $client->fresh()->last_login_at;
    $this->actingAs($this->admin);

    // Enter in the form (the public save()) does not save: it opens the question.
    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm(['email' => 'uj@client.hu'])
        ->call('save')
        ->assertActionMounted('save');
    expect($client->fresh()->email)->toBe('regi@client.hu');

    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm(['email' => 'uj@client.hu'])
        ->mountAction('save')
        ->assertActionMounted('save')
        ->assertMountedActionModalSee(__('Change the e-mail address?'))
        ->assertMountedActionModalSee('regi@client.hu')
        ->assertMountedActionModalSee('uj@client.hu')
        ->assertMountedActionModalSee(__(':n mobile app token(s) are deleted.', ['n' => 1]))
        ->assertMountedActionModalSee(__(':n live login link(s) and code(s) can no longer be used.', ['n' => 1]))
        ->assertMountedActionModalSee(__('Any open browser session ends (last activity :time).', ['time' => $lastActive->format('Y. m. d. H:i')]));
});

it('escapes the EMD record name it names in the question', function (): void {
    $client = Client::factory()->create(['email' => 'regi@client.hu']);
    ExternalRecord::factory()->client()->create(['external_id' => 781, 'email' => 'uj@client.hu', 'name' => '<script>hdidXssCanary()</script>']);
    $this->actingAs($this->admin);

    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm(['email' => 'uj@client.hu'])
        ->mountAction('save')
        ->assertMountedActionModalSee('<script>hdidXssCanary()</script>')
        ->assertMountedActionModalDontSee('<script>hdidXssCanary()</script>', escape: false);
});

it('says in the question when an e-mail change has nothing to revoke', function (): void {
    $client = Client::factory()->create(['email' => 'csendes@client.hu']);
    $this->actingAs($this->admin);

    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm(['email' => 'uj.csendes@client.hu'])
        ->mountAction('save')
        ->assertMountedActionModalSee(__('Nothing is signed in or waiting to be used at present, so nothing has to be revoked.'));
});

it('does not ask when only the letter case of the e-mail changes', function (): void {
    $client = Client::factory()->create(['email' => 'kis@client.hu']);
    $this->actingAs($this->admin);

    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm(['email' => 'Kis@Client.hu'])
        ->mountAction('save')
        ->assertHasNoFormErrors();

    expect($client->fresh()->email)->toBe('Kis@Client.hu')
        ->and(AuditLog::query()->where('event', 'account.access_revoked')->count())->toBe(0);
});

it('signs a client out everywhere once an administrator changes its e-mail: session, mobile token, unused login link, remember-me', function (): void {
    config()->set('hdid.client_login.require_emd', false);
    $client = Client::factory()->create(['email' => 'regi@client.hu']);
    $login = app(ClientPasswordlessLogin::class);
    signInWithLink($login->issueMagicLink($client))->assertOk();
    $unusedLink = $login->issueMagicLink($client);
    $bearer = app(AccountLogin::class)->issueToken($client, 'test', 'phone');
    $client->refresh()->forceFill(['remember_token' => 'remember-before-change'])->saveQuietly();
    $epoch = (int) $client->session_epoch;
    Auth::forgetGuards();
    $this->getJson('/api/v1/client/me')->assertOk();

    // The client request made the client guard the default one.
    $this->actingAs($this->admin, 'web');
    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm(['email' => 'uj@client.hu'])
        ->mountAction('save')
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified(__('Signed out everywhere: the e-mail changed'));

    $client->refresh();
    expect($client->email)->toBe('uj@client.hu')
        ->and((int) $client->session_epoch)->toBeGreaterThan($epoch)
        ->and($client->getRememberToken())->not->toBe('remember-before-change')
        ->and($client->tokens()->count())->toBe(0)
        ->and(OneTimeCode::query()->where('client_id', $client->id)->usable()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'account.access_revoked')->where('subject_id', $client->id)->sole()->context)
        ->toMatchArray(['reason' => 'email_changed_by_admin', 'from' => 'regi@client.hu', 'to' => 'uj@client.hu', 'tokens' => 1, 'secrets' => 1]);

    Auth::forgetGuards();
    $this->getJson('/api/v1/client/me')->assertUnauthorized();
    Auth::forgetGuards();
    $this->withToken($bearer)->postJson('/api/v1/client/mobile-code')->assertUnauthorized();
    Auth::forgetGuards();
    $this->flushHeaders()->withHeader('Referer', config('app.url'));
    signInWithLink($unusedLink)->assertForbidden()->assertJsonPath('reason', 'invalid_credentials');
    $this->assertGuest('client');
});

it('signs a staff member out everywhere once an administrator changes the e-mail, and tells what ended', function (): void {
    $user = User::factory()->withRole(Role::Agent)->create(['email' => 'elirt@corp.hu', 'remember_token' => 'remember-before-change']);
    app(AccountLogin::class)->issueToken($user, 'test', 'tablet');
    staffSessionRow($user);
    $epoch = (int) $user->session_epoch;
    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm(['email' => 'javitott@corp.hu'])
        ->mountAction('save')
        ->assertMountedActionModalSee(__(':n open browser session(s) end.', ['n' => 1]))
        ->assertMountedActionModalSee(__(':n mobile app token(s) are deleted.', ['n' => 1]))
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified(__('Signed out everywhere: the e-mail changed'));

    $user->refresh();
    expect($user->email)->toBe('javitott@corp.hu')
        ->and((int) $user->session_epoch)->toBeGreaterThan($epoch)
        ->and($user->getRememberToken())->not->toBe('remember-before-change')
        ->and($user->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'account.access_revoked')->where('subject_id', $user->id)->sole()->context)
        ->toMatchArray(['reason' => 'email_changed_by_admin', 'from' => 'elirt@corp.hu', 'to' => 'javitott@corp.hu', 'sessions' => 1, 'tokens' => 1]);
});

it('lets the local bootstrap record of an administrator follow a new e-mail, so the account can still sign in', function (): void {
    $this->artisan('hdid:make-admin', ['email' => 'helyi@corp.hu', '--password' => 'a-long-password-1'])->assertSuccessful();
    $installer = User::query()->where('email', 'helyi@corp.hu')->sole();
    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $installer->id])
        ->assertFormFieldIsEnabled('email')
        ->fillForm(['email' => 'uj.cim@corp.hu'])
        ->mountAction('save')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $installer->refresh();
    expect($installer->email)->toBe('uj.cim@corp.hu')
        ->and($installer->externalRecord->isLocal())->toBeTrue()
        ->and($installer->externalRecord->email)->toBe('uj.cim@corp.hu')
        ->and(Hash::check('a-long-password-1', $installer->password))->toBeTrue()
        ->and(app(AccountLogin::class)->assertEligible($installer, PrincipalType::User, 'password')->is($installer))->toBeTrue();
});

it('removes the previous holder\'s password and second factors when a new e-mail hands a staff account to another person\'s EMD record', function (): void {
    $this->artisan('hdid:make-admin', ['email' => 'telepito@corp.hu', '--password' => 'a-long-password-1'])->assertSuccessful();
    $account = User::query()->where('email', 'telepito@corp.hu')->sole();
    $account->forceFill(['mfa_app_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_email_enabled_at' => now(), 'remember_token' => 'remember-before-change'])->save();
    staffSessionRow($account);
    $local = $account->externalRecord;
    $jane = ExternalRecord::factory()->user()->create(['external_id' => 777, 'email' => 'jane@corp.hu', 'name' => 'Jane Doe']);
    $epoch = (int) $account->session_epoch;
    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $account->id])
        ->fillForm(['email' => 'jane@corp.hu'])
        ->mountAction('save')
        ->assertMountedActionModalSee('Jane Doe')
        ->assertMountedActionModalSee('777')
        ->assertMountedActionModalSee(__('the password'))
        ->assertMountedActionModalSee(__('the second factors (:methods)', ['methods' => implode(', ', [__('Authenticator app'), __('E-mail code')])]))
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $account->refresh();
    expect($account->external_record_id)->toBe($jane->id)
        ->and(ExternalRecord::withTrashed()->find($local->id)->trashed())->toBeTrue()
        ->and($account->password)->toBeNull()
        ->and($account->mfa_app_secret)->toBeNull()
        ->and(app(StaffMfa::class)->hasAnyFactor($account))->toBeFalse()
        ->and((int) $account->session_epoch)->toBeGreaterThan($epoch)
        ->and($account->getRememberToken())->not->toBe('remember-before-change')
        ->and(DB::table('sessions')->where('user_id', $account->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'mfa.reset')->where('subject_id', $account->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'account.access_revoked')->where('subject_id', $account->id)->sole()->context)
        ->toMatchArray(['reason' => 'email_changed_by_admin', 'from' => 'telepito@corp.hu', 'to' => 'jane@corp.hu', 'takes_over_external_id' => 777, 'removed' => ['password', 'second_factors']]);
});

it('keeps a password typed in the same save when a new e-mail hands a staff account to another person', function (): void {
    $account = User::factory()->withRole(Role::Agent)->create(['email' => 'regi@corp.hu', 'password' => 'the-old-holders-password']);
    ExternalRecord::factory()->user()->create(['external_id' => 778, 'email' => 'uj@corp.hu', 'name' => 'Új Ember']);
    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $account->id])
        ->fillForm(['email' => 'uj@corp.hu', 'password' => 'the-new-holders-password'])
        ->mountAction('save')
        ->assertMountedActionModalSee(__('The password typed in this form becomes the new holder\'s password.'))
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(Hash::check('the-new-holders-password', $account->fresh()->password))->toBeTrue();
});

it('clears a company sign-in binding left on a staff account that a new e-mail hands to another person', function (): void {
    $account = User::factory()->withRole(Role::Agent)->create(['email' => 'regi@corp.hu']);
    SsoIdentity::factory()->forAccount($account)->create();
    ExternalRecord::factory()->user()->create(['external_id' => 779, 'email' => 'uj@corp.hu']);
    $account->forceFill(['email' => 'uj@corp.hu'])->save();

    $result = app(AccountProvisioner::class)->applyAdminEmailChange($account, 'regi@corp.hu', keepPassword: false);

    expect($result['removed'])->toContain('sso_binding')
        ->and($account->ssoIdentities()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'sso.subject_unbound')->where('subject_id', $account->id)->sole()->context)
        ->toMatchArray(['reason' => 'email_changed_by_admin']);
});

it('stops a confirmed e-mail change whose consequences changed while the question was open, and asks again', function (): void {
    $account = User::factory()->withRole(Role::Agent)->create(['email' => 'regi@corp.hu', 'password' => 'a-long-password-1']);
    $this->actingAs($this->admin);

    $page = Livewire::test(EditUser::class, ['record' => $account->id])
        ->fillForm(['email' => 'cel@corp.hu'])
        ->mountAction('save')
        // No record to hand the account to: nothing is removed, and the question says the password stays.
        ->assertMountedActionModalSee('What the holder set up stays for now: the password')
        ->assertMountedActionModalDontSee('what the previous holder set up is removed');

    // Meanwhile a sync brings another person's record with the new address.
    ExternalRecord::factory()->user()->create(['external_id' => 780, 'email' => 'cel@corp.hu', 'name' => 'Cél Személy']);

    $page->callMountedAction()
        ->assertNotified(__('The consequences changed: check them again'))
        ->assertActionMounted('save');
    expect($account->fresh()->email)->toBe('regi@corp.hu');

    $page->assertMountedActionModalSee('Cél Személy')
        ->callMountedAction()
        ->assertHasNoFormErrors();
    expect($account->fresh()->email)->toBe('cel@corp.hu')
        ->and($account->fresh()->password)->toBeNull();
});

it('says what stays of the holder when the new address hands a staff account to no EMD record yet, and what the notification tells', function (): void {
    $this->artisan('hdid:make-admin', ['email' => 'telepito@corp.hu', '--password' => 'a-long-password-1'])->assertSuccessful();
    $account = User::query()->where('email', 'telepito@corp.hu')->sole();
    $account->forceFill(['mfa_email_enabled_at' => now()])->save();
    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $account->id])
        ->fillForm(['email' => 'uj.cim@corp.hu'])
        ->mountAction('save')
        ->assertMountedActionModalSee('What the holder set up stays')
        ->assertMountedActionModalSee(__('the password'))
        ->assertMountedActionModalSee('E-mail')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $mailbox = app(NotificationMailbox::class);
    $mailbox->flush();
    $bodies = collect($mailbox->pull())->pluck('body')->filter()->implode(' ');
    // The local record follows the address, so the account signs in: the notification must not say it cannot.
    expect($bodies)->toContain('signs in with its local record')
        ->and($bodies)->not->toContain('cannot sign in until an EMD sync');
    expect(app(AccountLogin::class)->assertEligible($account->fresh(), PrincipalType::User, 'password')->getKey())->toBe($account->id);
});

it('refuses a login link mailed to the address the client had before, even before it was revoked', function (): void {
    $client = Client::factory()->synced()->create(['email' => 'regi@client.hu']);
    app(Settings::class)->set(SettingKey::ClientLoginMagicLinkEnabled, true);
    Mail::fake();
    app(ClientPasswordlessLogin::class)->requestMagicLink('regi@client.hu');
    $token = null;
    Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $mail) use (&$token): bool {
        $token = Str::after($mail->url, '#token=');

        return true;
    });

    // The window between the address change and the revocation of the links.
    $client->forceFill(['email' => 'uj@client.hu'])->saveQuietly();

    expect(fn () => app(ClientPasswordlessLogin::class)->consumeMagicLink($token))->toThrow(LoginRejectedException::class);
    expect(AuditLog::query()->where('event', 'login.rejected')->latest('id')->first()->context)->toMatchArray(['detail' => 'destination_changed']);
});
