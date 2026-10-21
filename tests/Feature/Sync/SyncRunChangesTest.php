<?php

use App\Auth\Role;
use App\Enums\SyncChangeKind;
use App\Enums\SyncSource;
use App\Filament\Admin\Resources\SyncRunChanges\Pages\ManageSyncRunChanges;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\SyncRun;
use App\Models\SyncRunChange;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\Contracts\SourceReader;
use App\Sync\ExternalRecordDto;
use App\Sync\SyncExternalRecords;
use App\System\Retention;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * @param  list<ExternalRecordDto>  $rows
 */
function changesReaderOf(array $rows): SourceReader
{
    return new class($rows) implements SourceReader
    {
        public function __construct(private array $rows) {}

        public function source(): SyncSource
        {
            return SyncSource::Csv;
        }

        public function label(): ?string
        {
            return 'test.csv';
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

function changesRow(string $id, string $email, string $company = 'Acme', array $phones = [], ?string $implicit = 'Premium'): ExternalRecordDto
{
    return new ExternalRecordDto((int) $id, 'Name '.$id, $email, $company, $phones, [], $implicit, null);
}

beforeEach(function (): void {
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['x.hu']);
});

it('keeps the record diffs of a run on the run, one row per record, and the lifecycle events in the audit log', function (): void {
    app(Settings::class)->set(SettingKey::SyncMissedRunsBeforeClose, 1);
    $sync = app(SyncExternalRecords::class);

    $first = $sync->run(changesReaderOf([changesRow('1', 'a@x.hu', phones: ['+36301111111']), changesRow('2', 'b@x.hu')]));
    $client = Client::query()->where('email', 'a@x.hu')->sole();

    expect($first->stats['changes'])->toBe(2)
        ->and(SyncRunChange::query()->where('sync_run_id', $first->id)->count())->toBe(2);

    $created = SyncRunChange::query()->where('external_id', 1)->sole();
    $events = collect($created->changes)->pluck('event');
    expect($created->kind)->toBe(SyncChangeKind::Created)
        ->and($created->name)->toBe('Name 1')
        ->and($created->email)->toBe('a@x.hu')
        ->and($created->subject_type)->toBe($client->getMorphClass())
        ->and($created->subject_id)->toBe($client->id)
        ->and($created->subject?->is($client))->toBeTrue()
        ->and($events)->toContain('external_record.created', 'client.created', 'client_phone_number.created');

    // The audit log keeps the account events and one run summary, not the diffs.
    expect(AuditLog::query()->where('event', 'account.provisioned')->count())->toBe(2)
        ->and(AuditLog::query()->where('event', 'sync.completed')->count())->toBe(1)
        ->and(AuditLog::query()->whereIn('event', ['external_record.created', 'client.created', 'client_phone_number.created', 'external_record.updated', 'client.updated'])->count())->toBe(0);

    // A package change touches the record and the client: still one row.
    $second = $sync->run(changesReaderOf([changesRow('1', 'a@x.hu', phones: ['+36301111111'], implicit: 'Basic'), changesRow('2', 'b@x.hu')]));
    $updated = SyncRunChange::query()->where('sync_run_id', $second->id)->sole();
    expect($updated->kind)->toBe(SyncChangeKind::Updated)
        ->and($updated->external_id)->toBe(1)
        ->and(collect($updated->changes)->pluck('event')->all())->toBe(['external_record.updated', 'client.updated'])
        ->and($updated->changes[1]['context']['to'])->toMatchArray(['implicit_package' => 'Basic']);

    // A missing record closes the account: the row says so, and account.closed stays audited.
    $third = $sync->run(changesReaderOf([changesRow('2', 'b@x.hu')]));
    $closed = SyncRunChange::query()->where('sync_run_id', $third->id)->sole();
    expect($closed->kind)->toBe(SyncChangeKind::Closed)
        ->and($closed->external_id)->toBe(1)
        ->and(AuditLog::query()->where('event', 'account.closed')->where('subject_id', $client->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'client.updated')->count())->toBe(0);

    // The record returns: the account reopens.
    $fourth = $sync->run(changesReaderOf([changesRow('1', 'a@x.hu', phones: ['+36301111111'], implicit: 'Basic'), changesRow('2', 'b@x.hu')]));
    expect(SyncRunChange::query()->where('sync_run_id', $fourth->id)->sole()->kind)->toBe(SyncChangeKind::Reopened)
        ->and(AuditLog::query()->where('event', 'account.reopened')->where('subject_id', $client->id)->exists())->toBeTrue();
});

it('reports but does not keep the change rows of a trial run', function (): void {
    $run = app(SyncExternalRecords::class)->run(changesReaderOf([changesRow('1', 'a@x.hu'), changesRow('2', 'b@x.hu')]), dryRun: true);

    expect($run->stats['changes'])->toBe(2)
        ->and(SyncRunChange::query()->count())->toBe(0);
});

it('audits model changes again once the run is over', function (): void {
    app(SyncExternalRecords::class)->run(changesReaderOf([changesRow('1', 'a@x.hu')]));
    $client = Client::query()->where('email', 'a@x.hu')->sole();

    $client->update(['notes' => 'after the run']);

    expect(AuditLog::query()->where('event', 'client.updated')->where('subject_id', $client->id)->exists())->toBeTrue()
        ->and(SyncRunChange::query()->count())->toBe(1);
});

it('lists, searches, filters and exports the changes for sync managers only', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $sync = app(SyncExternalRecords::class);
    $first = $sync->run(changesReaderOf([changesRow('1', 'anna@x.hu'), changesRow('2', 'bela@x.hu')]));
    $second = $sync->run(changesReaderOf([changesRow('1', 'anna@x.hu', 'Other Co'), changesRow('2', 'bela@x.hu')]));

    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $this->get('/admin/system/sync-changes')->assertOk()->assertSee('anna@x.hu');

    Livewire::test(ManageSyncRunChanges::class)
        ->assertCanSeeTableRecords(SyncRunChange::query()->get())
        ->searchTable('bela')
        ->assertCanSeeTableRecords(SyncRunChange::query()->where('email', 'bela@x.hu')->get())
        ->assertCanNotSeeTableRecords(SyncRunChange::query()->where('email', 'anna@x.hu')->get())
        ->searchTable('')
        ->filterTable('sync_run_id', $second->id)
        ->assertCanSeeTableRecords(SyncRunChange::query()->where('sync_run_id', $second->id)->get())
        ->assertCanNotSeeTableRecords(SyncRunChange::query()->where('sync_run_id', $first->id)->get())
        ->assertSee('external_record.updated: company')
        ->callAction('export')
        ->assertFileDownloaded();

    $this->flushSession();
    $this->actingAs(User::factory()->withRole(Role::Agent)->create())->get('/admin/system/sync-changes')->assertForbidden();
});

it('deletes the change records after the short-lived retention period and with their run', function (): void {
    app(Settings::class)->set(SettingKey::RetentionShortLivedYears, 2);
    $run = SyncRun::factory()->create();
    $old = SyncRunChange::factory()->create(['sync_run_id' => $run->id]);
    SyncRunChange::query()->whereKey($old->id)->update(['created_at' => now()->subYears(3)]);
    $recent = SyncRunChange::factory()->create(['sync_run_id' => $run->id]);

    $removed = app(Retention::class)->prune();

    expect($removed['sync_run_changes'])->toBe(1)
        ->and(SyncRunChange::query()->find($old->id))->toBeNull()
        ->and(SyncRunChange::query()->find($recent->id))->not->toBeNull();

    $run->forceDelete();
    expect(SyncRunChange::query()->find($recent->id))->toBeNull('the rows go with their run');
});
