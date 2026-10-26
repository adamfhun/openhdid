<?php

use App\Auth\Role;
use App\Enums\PhoneNumberSource;
use App\Enums\PhoneVerificationSource;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientAnswer;
use App\Models\ClientPhoneNumber;
use App\Models\ExternalRecord;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\NotificationMailbox;
use Livewire\Livewire;

/**
 * An administrator's e-mail change that hands a free client account to
 * another person's EMD record asks first, naming the record and what of the
 * previous holder is deleted; once confirmed, the PIN (with its lockout
 * state), the security answers and the phone numbers added by hand go, the
 * directory's numbers follow the sync.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create());
    Filament::setCurrentPanel('admin');

    $this->client = Client::factory()->withPin('482913')->create([
        'email' => 'elozo@client.hu',
        'pin_failed_attempts' => 2,
        'pin_locked_until' => now()->addMinutes(10),
        'pin_lockout_count' => 1,
    ]);
    ClientAnswer::factory()->count(2)->create(['client_id' => $this->client->id, 'answer' => 'the previous holder answer']);
    ClientAnswer::factory()->create(['client_id' => $this->client->id])->delete();
    ClientPhoneNumber::factory()->create(['client_id' => $this->client->id, 'number_e164' => '+36301110001', 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);
    ClientPhoneNumber::factory()->create(['client_id' => $this->client->id, 'number_e164' => '+36301110002', 'source' => PhoneNumberSource::ClientSelf]);
    $this->directoryPhone = ClientPhoneNumber::factory()->create([
        'client_id' => $this->client->id,
        'number_e164' => '+36301110003',
        'source' => PhoneNumberSource::Sync,
        'verified_at' => now(),
        'verified_via' => PhoneVerificationSource::Directory,
    ]);
    $this->record = ExternalRecord::factory()->client()->create([
        'external_id' => 4242,
        'email' => 'uj.szemely@client.hu',
        'name' => 'Új Személy',
        'phones' => ['+36301110003', '+36301110004'],
    ]);
});

/**
 * The bodies of the notifications sent so far, by title.
 *
 * @return array<string, ?string>
 */
function takeoverNotifications(): array
{
    $mailbox = app(NotificationMailbox::class);
    $mailbox->flush();

    return collect($mailbox->pull())->mapWithKeys(fn (array $notification): array => [$notification['title'] => $notification['body'] ?? null])->all();
}

it('does not hand a free client to another person\'s EMD record without the confirmation, and the question names the record and what is deleted', function (): void {
    // Enter in the form (the public save()) only opens the question.
    Livewire::test(EditClient::class, ['record' => $this->client->id])
        ->fillForm(['email' => 'uj.szemely@client.hu'])
        ->call('save')
        ->assertActionMounted('save');

    $client = $this->client->fresh();
    expect($client->email)->toBe('elozo@client.hu')
        ->and($client->external_record_id)->toBeNull()
        ->and($client->hasPin())->toBeTrue()
        ->and($client->answers()->count())->toBe(2)
        ->and($client->phoneNumbers()->count())->toBe(3);

    Livewire::test(EditClient::class, ['record' => $this->client->id])
        ->fillForm(['email' => 'uj.szemely@client.hu'])
        ->mountAction('save')
        ->assertMountedActionModalSee('Új Személy')
        ->assertMountedActionModalSee('4242')
        ->assertMountedActionModalSee(__('The PIN is deleted, with its lockout state.'))
        ->assertMountedActionModalSee(__(':n security answer(s) are deleted.', ['n' => 2]))
        ->assertMountedActionModalSee(__(':n phone number(s) added by hand are deleted: :numbers.', ['n' => 2, 'numbers' => '+36301110001, +36301110002']))
        ->assertMountedActionModalSee(__('The name, packages and phone numbers then come from EMD, and the e-mail can only change there.'));
});

it('deletes the previous holder\'s PIN, security answers and hand-added numbers once the hand-over is confirmed, keeps the EMD numbers, and audits the counts', function (): void {
    Livewire::test(EditClient::class, ['record' => $this->client->id])
        ->fillForm(['email' => 'uj.szemely@client.hu'])
        ->mountAction('save')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $client = $this->client->fresh();
    expect($client->email)->toBe('uj.szemely@client.hu')
        ->and($client->external_record_id)->toBe($this->record->id)
        ->and($client->pin_hash)->toBeNull()
        ->and($client->pin_lookup)->toBeNull()
        ->and($client->pin_failed_attempts)->toBe(0)
        ->and($client->pin_locked_until)->toBeNull()
        ->and($client->pin_lockout_count)->toBe(0)
        ->and(ClientAnswer::withTrashed()->where('client_id', $client->id)->count())->toBe(0)
        ->and(ClientPhoneNumber::withTrashed()->where('client_id', $client->id)->whereIn('number_e164', ['+36301110001', '+36301110002'])->count())->toBe(0)
        ->and($client->phoneNumbers()->orderBy('number_e164')->pluck('number_e164')->all())->toBe(['+36301110003', '+36301110004'])
        ->and($client->phoneNumbers()->where('number_e164', '+36301110003')->sole()->id)->toBe($this->directoryPhone->id);

    $removed = AuditLog::query()->where('event', 'client.holder_data_removed')->where('subject_id', $client->id)->sole();
    expect($removed->context)->toBe(['reason' => 'email_changed_by_admin', 'takes_over_external_id' => 4242, 'pin' => true, 'answers' => 2, 'phones' => 2])
        ->and(AuditLog::query()->where('event', 'account.access_revoked')->where('subject_id', $client->id)->sole()->context)
        ->toMatchArray(['reason' => 'email_changed_by_admin', 'from' => 'elozo@client.hu', 'to' => 'uj.szemely@client.hu', 'takes_over_external_id' => 4242, 'removed' => ['pin', 'answers', 'manual_phones']])
        ->and(AuditLog::query()->where('event', 'client.pin_cleared')->where('subject_id', $client->id)->sole()->context)->toBe(['reason' => 'email_changed_by_admin']);

    // Counts only: neither the numbers nor an answer reach the audit log.
    $logged = json_encode(AuditLog::query()->where('subject_id', $client->id)->pluck('context')->all());
    expect($logged)->not->toContain('+36301110001')
        ->and($logged)->not->toContain('the previous holder answer');

    $notified = takeoverNotifications();
    expect($notified)->toHaveKey(__('Signed out everywhere: the e-mail changed'))
        ->and($notified[__('Signed out everywhere: the e-mail changed')])
        ->toContain(__('Removed from the previous holder: :items.', ['items' => implode(', ', [__('the PIN'), __(':n security answer(s)', ['n' => 2]), __(':n phone number(s) added by hand', ['n' => 2])])]));
});

it('says in the question what the previous holder of a free client did not leave', function (): void {
    $client = Client::factory()->create(['email' => 'ures@client.hu']);
    ExternalRecord::factory()->client()->create(['external_id' => 4343, 'email' => 'masik@client.hu', 'name' => 'Másik Személy']);

    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm(['email' => 'masik@client.hu'])
        ->mountAction('save')
        ->assertMountedActionModalSee('Másik Személy')
        ->assertMountedActionModalSee(__('No PIN is set.'))
        ->assertMountedActionModalSee(__('No security answers are recorded.'))
        ->assertMountedActionModalSee(__('No phone number was added by hand.'))
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(AuditLog::query()->where('event', 'client.holder_data_removed')->where('subject_id', $client->id)->sole()->context)
        ->toBe(['reason' => 'email_changed_by_admin', 'takes_over_external_id' => 4343, 'pin' => false, 'answers' => 0, 'phones' => 0]);
});

it('stops a confirmed hand-over when the previous holder\'s data changed while the question was open, and asks again', function (): void {
    $page = Livewire::test(EditClient::class, ['record' => $this->client->id])
        ->fillForm(['email' => 'uj.szemely@client.hu'])
        ->mountAction('save')
        ->assertMountedActionModalSee(__(':n security answer(s) are deleted.', ['n' => 2]));

    // Meanwhile the client answers one more question in the portal.
    ClientAnswer::factory()->create(['client_id' => $this->client->id]);

    $page->callMountedAction()
        ->assertActionMounted('save');

    $client = $this->client->fresh();
    expect($client->email)->toBe('elozo@client.hu')
        ->and($client->hasPin())->toBeTrue()
        ->and($client->answers()->count())->toBe(3)
        ->and(AuditLog::query()->where('event', 'client.holder_data_removed')->count())->toBe(0)
        ->and(takeoverNotifications()[__('The consequences changed: check them again')] ?? null)
        ->toBe(__('Something the e-mail change depends on changed while the question was open (an EMD record with the new address, the PIN, the security answers or the phone numbers added by hand, the stored address). The question now shows what saving does; confirm again to save.'));

    $page->assertMountedActionModalSee(__(':n security answer(s) are deleted.', ['n' => 3]))
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($this->client->fresh()->email)->toBe('uj.szemely@client.hu')
        ->and(ClientAnswer::withTrashed()->where('client_id', $this->client->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'client.holder_data_removed')->sole()->context['answers'])->toBe(3);
});

it('deletes nothing of the client when the new address has no EMD record', function (): void {
    Livewire::test(EditClient::class, ['record' => $this->client->id])
        ->fillForm(['email' => 'csak.uj.cim@client.hu'])
        ->mountAction('save')
        ->assertMountedActionModalDontSee(__('The PIN is deleted, with its lockout state.'))
        ->assertMountedActionModalDontSee(__('No PIN is set.'))
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $client = $this->client->fresh();
    expect($client->email)->toBe('csak.uj.cim@client.hu')
        ->and($client->external_record_id)->toBeNull()
        ->and($client->hasPin())->toBeTrue()
        ->and($client->answers()->count())->toBe(2)
        ->and($client->phoneNumbers()->count())->toBe(3)
        ->and(AuditLog::query()->where('event', 'client.holder_data_removed')->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'account.access_revoked')->where('subject_id', $client->id)->sole()->context)->not->toHaveKey('removed');
});

it('deletes nothing of the client when it is linked to the EMD record of its own address', function (): void {
    $own = ExternalRecord::factory()->client()->create(['external_id' => 4444, 'email' => 'elozo@client.hu', 'phones' => ['+36301110003']]);

    // Only the letter case changes: the same identity, no question.
    Livewire::test(EditClient::class, ['record' => $this->client->id])
        ->fillForm(['email' => 'Elozo@client.hu'])
        ->mountAction('save')
        ->assertHasNoFormErrors();

    $client = $this->client->fresh();
    expect($client->external_record_id)->toBe($own->id)
        ->and($client->hasPin())->toBeTrue()
        ->and($client->answers()->count())->toBe(2)
        ->and($client->phoneNumbers()->orderBy('number_e164')->pluck('number_e164')->all())->toBe(['+36301110001', '+36301110002', '+36301110003'])
        ->and(AuditLog::query()->where('event', 'client.holder_data_removed')->count())->toBe(0);
});

it('says the PIN, the answers and the numbers added by hand stay when the new address hands the client to no EMD record', function (): void {
    Livewire::test(EditClient::class, ['record' => $this->client->id])
        ->fillForm(['email' => 'senki@client.hu'])
        ->mountAction('save')
        ->assertMountedActionModalSee('What the holder set up stays')
        ->assertMountedActionModalSee(__('the PIN'))
        ->assertMountedActionModalSee(__(':n security answer(s)', ['n' => 2]))
        ->assertMountedActionModalSee(__(':n phone number(s) added by hand', ['n' => 2]))
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $client = $this->client->fresh();
    expect($client->email)->toBe('senki@client.hu')
        ->and($client->hasPin())->toBeTrue('nothing is deleted without a record to hand the account to');
});
