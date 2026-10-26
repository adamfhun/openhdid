<?php

use App\Auth\Role;
use App\Enums\PhoneNumberSource;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSource;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Identification\PinService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientAnswer;
use App\Models\ClientPhoneNumber;
use App\Models\ExternalRecord;
use App\Models\SsoIdentity;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\AccountProvisioner;
use App\Sync\Contracts\SourceReader;
use App\Sync\ExternalRecordDto;
use App\Sync\SyncExternalRecords;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * G23b (decision of 2026-10-26): a free account whose address an
 * administrator changed by hand, once linked to an EMD record for the first
 * time, and a client the sync moves from one EMD record to another, are
 * taken as handed to another person: signed out everywhere, and what the
 * previous holder set up is removed, with an audit entry and a count on the
 * run. Relinking an account to its own record removes nothing.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create());
    app(Settings::class)->set(SettingKey::SyncUserDomains, ['corp.hu']);
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['client.hu']);
});

/**
 * @param  list<ExternalRecordDto>  $rows
 */
function handOverReader(array $rows): SourceReader
{
    return new class($rows) implements SourceReader
    {
        /**
         * @param  list<ExternalRecordDto>  $rows
         */
        public function __construct(private array $rows) {}

        public function source(): SyncSource
        {
            return SyncSource::Csv;
        }

        public function label(): ?string
        {
            return 'test';
        }

        public function read(): iterable
        {
            yield from $this->rows;
        }

        public function details(): array
        {
            return [];
        }
    };
}

function handOverRow(int $id, string $email): ExternalRecordDto
{
    return new ExternalRecordDto($id, 'Name '.$id, $email, 'Acme', [], [], 'Basic', null);
}

/**
 * A client with what identifies its holder: a PIN, two answers and a
 * number added by hand.
 */
function handOverHolderData(Client $client): void
{
    $client->forceFill(['pin_hash' => Hash::make('482913'), 'pin_lookup' => app(PinService::class)->lookupOf('482913'), 'pin_set_at' => now()])->save();
    ClientAnswer::factory()->count(2)->create(['client_id' => $client->id]);
}

it('removes the password, the second factors and the binding of a staff account at its first link after a hand-made e-mail change', function (): void {
    $account = User::factory()->withRole(Role::Agent)->create(['email' => 'regi@corp.hu', 'password' => Hash::make('the-old-holders-password')]);
    $account->forceFill(['mfa_email_enabled_at' => now()])->save();

    Livewire::test(EditUser::class, ['record' => $account->id])
        ->fillForm(['email' => 'uj.cim@corp.hu'])
        ->mountAction('save')
        ->assertMountedActionModalSee('What the holder set up stays for now')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $account->refresh();
    expect($account->email_changed_by_admin_at)->not->toBeNull()
        ->and($account->password)->not->toBeNull('nothing of the holder goes without a record');
    SsoIdentity::factory()->forAccount($account)->create();
    $epoch = (int) $account->session_epoch;

    $record = ExternalRecord::factory()->user()->create(['external_id' => 501, 'email' => 'uj.cim@corp.hu']);
    app(AccountProvisioner::class)->syncAccount($record);

    $account->refresh();
    expect($account->external_record_id)->toBe($record->id)
        ->and($account->email_changed_by_admin_at)->toBeNull()
        ->and($account->password)->toBeNull()
        ->and($account->mfa_email_enabled_at)->toBeNull()
        ->and($account->ssoIdentities()->count())->toBe(0)
        ->and((int) $account->session_epoch)->toBeGreaterThan($epoch);

    $entry = AuditLog::query()->where('event', 'account.access_revoked')->where('context->reason', AccountProvisioner::REASON_LINKED_AFTER_EMAIL_CHANGE)->sole();
    expect($entry->subject_id)->toBe($account->id)
        ->and($entry->context['external_id'])->toBe(501)
        ->and($entry->context['removed'])->toBe(['password', 'second_factors', 'sso_binding']);
});

it('deletes the PIN, the answers and the numbers added by hand of a client at its first link after a hand-made e-mail change, and counts it on the run', function (): void {
    $client = Client::factory()->create(['email' => 'elozo@client.hu']);
    ClientPhoneNumber::factory()->create(['client_id' => $client->id, 'number_e164' => '+36301110001', 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);
    handOverHolderData($client);

    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm(['email' => 'senki@client.hu'])
        ->mountAction('save')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($client->fresh()->hasPin())->toBeTrue()
        ->and($client->fresh()->email_changed_by_admin_at)->not->toBeNull();

    $run = app(SyncExternalRecords::class)->run(handOverReader([handOverRow(4242, 'senki@client.hu')]));

    $client->refresh();
    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and($client->externalRecord->external_id)->toBe(4242)
        ->and($client->email_changed_by_admin_at)->toBeNull()
        ->and($client->hasPin())->toBeFalse()
        ->and($client->answers()->count())->toBe(0)
        ->and($client->phoneNumbers()->where('source', '!=', PhoneNumberSource::Sync->value)->count())->toBe(0)
        ->and($run->stats['holder_data_removed'])->toBe(1)
        ->and($run->stats['holder_data_removed_samples'][0])->toMatchArray(['external_id' => 4242, 'kind' => 'client', 'reason' => AccountProvisioner::REASON_LINKED_AFTER_EMAIL_CHANGE, 'removed' => ['pin', 'answers', 'manual_phones']])
        ->and(AuditLog::query()->where('event', 'client.holder_data_removed')->sole()->context)->toMatchArray(['reason' => AccountProvisioner::REASON_LINKED_AFTER_EMAIL_CHANGE, 'pin' => true, 'answers' => 2, 'phones' => 1]);
});

it('deletes the holder data of a client the sync moves to another EMD record while the old record is still in the source, and counts it on the run', function (): void {
    $sync = app(SyncExternalRecords::class);
    $sync->run(handOverReader([handOverRow(1001, 'a@client.hu')]));
    $client = Client::query()->where('email', 'a@client.hu')->sole();
    ClientPhoneNumber::factory()->create(['client_id' => $client->id, 'number_e164' => '+36301110002', 'source' => PhoneNumberSource::ClientSelf]);
    handOverHolderData($client);
    $epoch = (int) $client->session_epoch;

    // Two live records, two people: the address now comes with another record,
    // and the old record goes on under another address (later in the same run).
    $run = $sync->run(handOverReader([handOverRow(1002, 'a@client.hu'), handOverRow(1001, 'b@client.hu')]));

    $client->refresh();
    expect($client->externalRecord->external_id)->toBe(1002)
        ->and((int) $client->session_epoch)->toBeGreaterThan($epoch)
        ->and($client->hasPin())->toBeFalse()
        ->and($client->answers()->count())->toBe(0)
        ->and($client->phoneNumbers()->count())->toBe(0)
        ->and(Client::query()->where('email', 'b@client.hu')->sole()->externalRecord->external_id)->toBe(1001)
        ->and($run->stats['holder_data_removed'])->toBe(1)
        ->and($run->stats['reissued'])->toBe(0)
        ->and($run->stats['holder_data_removed_samples'][0])->toMatchArray(['external_id' => 1002, 'reason' => AccountProvisioner::REASON_SYNC_REBOUND, 'removed' => ['pin', 'answers', 'manual_phones']])
        ->and(AuditLog::query()->where('event', 'client.holder_data_removed')->sole()->context)->toMatchArray(['reason' => AccountProvisioner::REASON_SYNC_REBOUND, 'takes_over_external_id' => 1002, 'from_external_id' => 1001])
        ->and(AuditLog::query()->where('event', 'client.pin_cleared')->sole()->context)->toMatchArray(['reason' => AccountProvisioner::REASON_SYNC_REBOUND])
        ->and(AuditLog::query()->where('event', 'account.reissued')->exists())->toBeFalse();
});

it('keeps the PIN, the answers and the numbers of a client whose record the directory reissues under a new external id, and counts the reissue on the run', function (): void {
    $sync = app(SyncExternalRecords::class);
    $sync->run(handOverReader([handOverRow(1001, 'a@client.hu')]));
    $client = Client::query()->where('email', 'a@client.hu')->sole();
    ClientPhoneNumber::factory()->create(['client_id' => $client->id, 'number_e164' => '+36301110002', 'source' => PhoneNumberSource::ClientSelf]);
    handOverHolderData($client);
    $epoch = (int) $client->session_epoch;

    // The old record is gone from the source: the same person under a new id (decision of 2026-10-26).
    $run = $sync->run(handOverReader([handOverRow(1002, 'a@client.hu')]));

    $client->refresh();
    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and($client->externalRecord->external_id)->toBe(1002)
        ->and((int) $client->session_epoch)->toBeGreaterThan($epoch, 'the sign-ins still end')
        ->and($client->hasPin())->toBeTrue()
        ->and($client->answers()->count())->toBe(2)
        ->and($client->phoneNumbers()->where('number_e164', '+36301110002')->exists())->toBeTrue()
        ->and($run->stats['holder_data_removed'])->toBe(0)
        ->and($run->stats['reissued'])->toBe(1)
        ->and($run->stats['reissued_samples'][0])->toMatchArray(['external_id' => 1002, 'from_external_id' => 1001, 'kind' => 'client', 'email' => 'a@client.hu'])
        ->and(AuditLog::query()->where('event', 'client.holder_data_removed')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'account.reissued')->sole()->context)->toMatchArray(['from_external_id' => 1001, 'external_id' => 1002])
        ->and(AuditLog::query()->where('event', 'account.access_revoked')->where('context->reason', 'rebound')->exists())->toBeTrue();

    // A trial run reports the reissue too, and writes nothing.
    $trial = $sync->run(handOverReader([handOverRow(1003, 'a@client.hu')]), dryRun: true);
    expect($trial->stats['reissued'])->toBe(1)
        ->and($client->fresh()->externalRecord->external_id)->toBe(1002)
        ->and(AuditLog::query()->where('event', 'account.reissued')->count())->toBe(1);
});

it('removes nothing when an account is linked to its own record again', function (): void {
    $sync = app(SyncExternalRecords::class);
    $sync->run(handOverReader([handOverRow(1001, 'a@client.hu')]));
    $client = Client::query()->where('email', 'a@client.hu')->sole();
    handOverHolderData($client);

    $run = $sync->run(handOverReader([handOverRow(1001, 'a@client.hu')]));

    // A staff account bootstrapped by hdid:make-admin, its address never changed by hand.
    $this->artisan('hdid:make-admin', ['email' => 'telepito@corp.hu', '--password' => 'a-long-password-1'])->assertSuccessful();
    $installer = User::query()->where('email', 'telepito@corp.hu')->sole();
    $record = ExternalRecord::factory()->user()->create(['external_id' => 7, 'email' => 'telepito@corp.hu']);
    app(AccountProvisioner::class)->syncAccount($record);
    app(AccountProvisioner::class)->syncAccount($record->fresh());

    expect($client->fresh()->hasPin())->toBeTrue()
        ->and($client->answers()->count())->toBe(2)
        ->and($run->stats['holder_data_removed'])->toBe(0)
        ->and($installer->fresh()->external_record_id)->toBe($record->id)
        ->and(Hash::check('a-long-password-1', $installer->fresh()->password))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'client.holder_data_removed')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('context->reason', AccountProvisioner::REASON_LINKED_AFTER_EMAIL_CHANGE)->exists())->toBeFalse();
});

it('tells on the page that saves the link what the hand-over removed, and warns under the e-mail field before', function (): void {
    $account = User::factory()->withRole(Role::Agent)->create(['email' => 'regi@corp.hu', 'password' => Hash::make('the-old-holders-password')]);

    Livewire::test(EditUser::class, ['record' => $account->id])
        ->fillForm(['email' => 'uj.cim@corp.hu'])
        ->mountAction('save')
        ->callMountedAction();

    expect(EditUser::handOverNote($account->fresh()))->toContain('changed by hand');

    ExternalRecord::factory()->user()->create(['external_id' => 502, 'email' => 'uj.cim@corp.hu', 'name' => 'Másik Személy']);

    Livewire::test(EditUser::class, ['record' => $account->id])
        ->fillForm(['name' => 'Új név'])
        ->mountAction('save')
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified(__('Handed to another person: EMD record :id', ['id' => 502]));

    expect($account->fresh()->password)->toBeNull()
        ->and(EditUser::handOverNote($account->fresh()))->toBeNull();
});
