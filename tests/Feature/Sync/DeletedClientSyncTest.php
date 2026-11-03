<?php

use App\Auth\Role;
use App\Enums\DirectoryLinkOutcome;
use App\Enums\SyncSource;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\SyncRun;
use App\Models\SyncRunChange;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\AccountProvisioner;
use App\Sync\Contracts\SourceReader;
use App\Sync\ExternalRecordDto;
use App\Sync\SyncExternalRecords;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * A deleted client stays deleted (owner decision Q11): the sync neither
 * restores nor reopens it when its EMD record (or its address) comes back,
 * nor creates a second account for it; the run reports the returning
 * record, and the account's deletion records the administrator's reason.
 */

beforeEach(function (): void {
    app(Settings::class)->set(SettingKey::SyncUserDomains, ['staff.hu']);
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['x.hu']);
});

/**
 * @param  list<ExternalRecordDto>  $rows
 */
function deletedSyncRun(array $rows, bool $dryRun = false): SyncRun
{
    return app(SyncExternalRecords::class)->run(new class($rows) implements SourceReader
    {
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
    }, dryRun: $dryRun);
}

function deletedSyncRow(string $id, string $email): ExternalRecordDto
{
    return new ExternalRecordDto((int) $id, 'Name '.$id, $email, 'Acme', [], [], 'Basic', null);
}

/** A client synced from record 1, then deleted by an administrator. */
function deletedSyncedClient(string $email = 'a@x.hu'): Client
{
    deletedSyncRun([deletedSyncRow('1', $email)]);
    $client = Client::query()->where('email', $email)->sole();
    $client->closeForRemoval();
    $client->delete();

    return $client;
}

it('records an administrator reason when a client the sync already closed is deleted, without a failure notice', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');
    $client = Client::factory()->synced()->closed('sync.missing')->create();

    Livewire::test(ListClients::class)->callTableAction(DeleteAction::class, $client)
        ->assertNotified(__('filament-actions::delete.single.notifications.deleted.title'));

    $deleted = Client::withTrashed()->find($client->id);
    expect($deleted->trashed())->toBeTrue()
        ->and($deleted->closed_reason)->toBe('admin')
        ->and(AuditLog::query()->where('event', 'account.close_reason_changed')->sole()->context)->toMatchArray(['previous_reason' => 'sync.missing', 'via' => 'delete']);
});

it('neither restores nor reopens a deleted client when its EMD record returns, and reports it on the run', function (): void {
    $client = deletedSyncedClient();

    $run = deletedSyncRun([deletedSyncRow('1', 'a@x.hu')]);

    $kept = Client::withTrashed()->find($client->id);
    expect($kept->trashed())->toBeTrue()
        ->and($kept->isClosed())->toBeTrue()
        ->and($run->stats['deleted_returned'])->toBe(1)
        ->and($run->stats['deleted_returned_samples'][0])->toMatchArray(['external_id' => 1, 'email' => 'a@x.hu'])
        ->and(AuditLog::query()->where('event', 'account.reopened')->exists())->toBeFalse()
        ->and(SyncRunChange::query()->get()->pluck('changes')->flatten()->implode(' '))->not->toContain('restored');
});

it('does not create a second account for the EMD record of a deleted client whose e-mail changed', function (): void {
    $client = deletedSyncedClient();

    $run = deletedSyncRun([deletedSyncRow('1', 'renamed@x.hu')]);

    expect(Client::withTrashed()->count())->toBe(1)
        ->and(Client::withTrashed()->find($client->id)->trashed())->toBeTrue()
        ->and($run->stats['deleted_returned'])->toBe(1);
});

it('reports a deleted client whose address arrives on another EMD record instead of taking it over', function (): void {
    $client = deletedSyncedClient();

    $run = deletedSyncRun([deletedSyncRow('2', 'a@x.hu')]);

    expect(Client::withTrashed()->find($client->id)->trashed())->toBeTrue()
        ->and(Client::withTrashed()->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'account.rebound')->exists())->toBeFalse()
        ->and($run->stats['deleted_returned'])->toBe(1);
});

it('counts a returning record of a deleted client in a dry run without writing anything', function (): void {
    $client = deletedSyncedClient();

    $run = deletedSyncRun([deletedSyncRow('1', 'a@x.hu')], dryRun: true);

    expect($run->stats['deleted_returned'])->toBe(1)
        ->and(Client::withTrashed()->find($client->id)->trashed())->toBeTrue();
});

it('reports a conflict and binds nobody when a deleted client\'s record meets an open, unbound client with its address', function (): void {
    $client = deletedSyncedClient('a@x.hu');
    $client->forceFill(['email' => 'old-a@x.hu'])->saveQuietly();
    $open = Client::factory()->create(['email' => 'a@x.hu']);

    $run = deletedSyncRun([deletedSyncRow('1', 'a@x.hu')]);

    expect($open->fresh()->external_record_id)->toBeNull()
        ->and(Client::withTrashed()->find($client->id)->trashed())->toBeTrue()
        ->and($run->stats['deleted_returned'])->toBe(1);
});

it('does not report a link on save to the EMD record of a deleted client', function (): void {
    deletedSyncedClient('a@x.hu');
    Client::withTrashed()->where('email', 'a@x.hu')->update(['email' => 'gone-a@x.hu']);
    $fresh = Client::factory()->create(['email' => 'a@x.hu']);

    $outcome = app(AccountProvisioner::class)->linkNow($fresh);

    expect($outcome)->toBe(DirectoryLinkOutcome::DeletedHolder)
        ->and($fresh->fresh()->external_record_id)->toBeNull()
        ->and(AuditLog::query()->where('event', 'account.linked')->exists())->toBeFalse()
        ->and($outcome->message($fresh))->toContain(__('deleted'));
});
