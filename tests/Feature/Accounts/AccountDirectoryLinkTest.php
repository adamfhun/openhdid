<?php

use App\Auth\Role;
use App\Enums\DirectoryLinkOutcome;
use App\Enums\PhoneNumberSource;
use App\Filament\Admin\Resources\Clients\Pages\CreateClient;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\AccountProvisioner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\Support\FakeOidc;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');
    $this->agentRole = RoleModel::findByName(Role::Agent->value)->id;
});

/**
 * The page tells the link outcome in the body of its created/saved notification.
 */
function notifiedBodies(): array
{
    return collect(session()->get('filament.notifications', []))->pluck('body')->all();
}

function enableStaffSso(): void
{
    config()->set('hdid.oidc.user.adfs', ['issuer' => FakeOidc::ISSUER, 'client_id' => 'user-app', 'client_secret' => 's']);
    app(Settings::class)->set(SettingKey::UserSsoAdfsEnabled, true);
}

it('requires a password for a new staff account only while no staff sso can be used', function (): void {
    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Jelszavas Jenő', 'email' => 'jeno@corp.hu', 'roles' => [$this->agentRole]])
        ->assertSee(__('Required: no staff SSO provider is switched on and configured, so a password is the only way to sign in.'))
        ->call('create')
        ->assertHasFormErrors(['password' => 'required']);

    enableStaffSso();

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Egyszeri Erika', 'email' => 'erika@corp.hu', 'roles' => [$this->agentRole]])
        ->assertSee(__('Only needed for password login; leave empty for SSO-only accounts.'))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::query()->where('email', 'erika@corp.hu')->sole()->password)->toBeNull();
});

it('links a new staff account to its present directory record at once and says so', function (): void {
    $record = ExternalRecord::factory()->user()->create(['external_id' => 4711, 'email' => 'uj.kolléga@corp.hu']);

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Új Kolléga', 'email' => 'Uj.Kolléga@corp.hu', 'password' => 'a-long-password-1', 'roles' => [$this->agentRole]])
        ->call('create')
        ->assertHasNoFormErrors();
    expect(notifiedBodies())->toContain(__('Linked to EMD record :id at once.', ['id' => 4711]));

    $user = User::query()->where('external_record_id', $record->id)->sole();
    expect($user->email)->toBe('uj.kolléga@corp.hu')
        ->and(AuditLog::query()->where('event', 'account.linked')->where('subject_id', $user->id)->sole()->context)
        ->toMatchArray(['external_id' => 4711]);
});

it('creates a client with the directory data of its record', function (): void {
    $record = ExternalRecord::factory()->client()->create([
        'external_id' => 5150, 'email' => 'ugyfel@client.hu', 'name' => 'Nyilvántartási Név',
        'implicit_package' => 'Premium', 'explicit_package' => null, 'phones' => ['+36301234567'],
    ]);

    Livewire::test(CreateClient::class)
        ->fillForm(['name' => 'Kézzel Írt Név', 'email' => 'ugyfel@client.hu', 'explicit_package' => 'Basic'])
        ->call('create')
        ->assertHasNoFormErrors();

    $client = Client::query()->where('email', 'ugyfel@client.hu')->sole();
    expect($client->external_record_id)->toBe($record->id)
        ->and($client->name)->toBe('Nyilvántartási Név')
        ->and($client->implicit_package)->toBe('Premium')
        ->and($client->explicit_package)->toBeNull()
        ->and($client->phoneNumbers()->sole()->only(['number_e164', 'source']))->toBe(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Sync]);
});

it('leaves the account unlinked and tells why', function (string $setup, DirectoryLinkOutcome $expected): void {
    match ($setup) {
        'none' => ExternalRecord::factory()->user()->create(['email' => 'mas@corp.hu']),
        'other kind' => ExternalRecord::factory()->client()->create(['email' => 'cel@corp.hu']),
        'missing' => ExternalRecord::factory()->user()->missing()->create(['email' => 'cel@corp.hu']),
        'local only' => ExternalRecord::factory()->user()->create(['email' => 'cel@corp.hu', 'external_id' => ExternalRecord::LOCAL_ID_BASE + 1]),
        'taken' => User::factory()->create(['email' => 'regi@corp.hu', 'external_record_id' => ExternalRecord::factory()->user()->create(['email' => 'cel@corp.hu'])]),
        'ambiguous' => ExternalRecord::factory()->user()->count(2)->create(['email' => 'cel@corp.hu']),
    };
    $user = User::factory()->create(['email' => 'cel@corp.hu']);

    expect(app(AccountProvisioner::class)->linkNow($user))->toBe($expected)
        ->and($user->fresh()->external_record_id)->toBeNull()
        ->and(AuditLog::query()->where('event', 'account.linked')->count())->toBe(0);
})->with([
    'no record' => ['none', DirectoryLinkOutcome::NoRecord],
    'other kind' => ['other kind', DirectoryLinkOutcome::OtherKind],
    'missing' => ['missing', DirectoryLinkOutcome::Missing],
    'only a local record' => ['local only', DirectoryLinkOutcome::NoRecord],
    'taken' => ['taken', DirectoryLinkOutcome::Taken],
    'ambiguous' => ['ambiguous', DirectoryLinkOutcome::Ambiguous],
]);

it('warns on the created page when no record could be linked', function (): void {
    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Senki Soma', 'email' => 'senki@corp.hu', 'password' => 'a-long-password-1', 'roles' => [$this->agentRole]])
        ->call('create')
        ->assertHasNoFormErrors();
    expect(notifiedBodies())->toContain(__('Not linked to EMD yet: no EMD record carries this e-mail. The account cannot sign in until an EMD sync brings a record with the same e-mail and links it.'));
});

it('links an unlinked account when its e-mail is corrected, and leaves linked accounts alone', function (): void {
    $record = ExternalRecord::factory()->user()->create(['external_id' => 6060, 'email' => 'helyes@corp.hu']);
    $user = User::factory()->withRole(Role::Agent)->create(['email' => 'elirt@corp.hu']);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm(['email' => 'helyes@corp.hu'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect(notifiedBodies())->toContain(__('Linked to EMD record :id at once.', ['id' => 6060]));
    expect($user->fresh()->external_record_id)->toBe($record->id);

    $synced = Client::factory()->synced()->create();
    ExternalRecord::factory()->client()->create(['email' => 'other@client.hu']);
    Livewire::test(EditClient::class, ['record' => $synced->id])->fillForm(['notes' => 'Megjegyzés.'])->call('save')->assertHasNoFormErrors();

    expect(app(AccountProvisioner::class)->linkNow($synced->fresh()))->toBeNull()
        ->and(AuditLog::query()->where('event', 'account.linked')->count())->toBe(1);
});

it('replaces the local bootstrap record of an admin with the directory record', function (): void {
    $this->artisan('hdid:make-admin', ['email' => 'boot@corp.hu', '--password' => 'a-long-password-1'])->assertSuccessful();
    $admin = User::query()->where('email', 'boot@corp.hu')->sole();
    $local = $admin->externalRecord;
    $record = ExternalRecord::factory()->user()->create(['email' => 'boot@corp.hu']);

    expect(app(AccountProvisioner::class)->linkNow($admin))->toBe(DirectoryLinkOutcome::Linked)
        ->and($admin->fresh()->external_record_id)->toBe($record->id)
        ->and(ExternalRecord::withTrashed()->find($local->id)->trashed())->toBeTrue();
});
