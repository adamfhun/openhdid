<?php

use App\Auth\Role;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSource;
use App\Filament\Admin\Resources\SyncRuns\Pages\ManageSyncRuns;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Support\InputLimits;
use App\Sync\Contracts\SourceReader;
use App\Sync\ExternalRecordDto;
use App\Sync\QueuedSyncs;
use App\Sync\Readers\SpreadsheetReader;
use App\Sync\SkippedRow;
use App\Sync\SourceLabel;
use App\Sync\SyncExternalRecords;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\Entry;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * An EMD cell longer than its column must not fail the whole run (on
 * MariaDB in strict mode a single one did), and an address that is no
 * usable e-mail address is a skipped row with its own reason. Text cells
 * are cut, not skipped: skipping would close the person's account.
 */
beforeEach(function (): void {
    app(Settings::class)->set(SettingKey::SyncUserDomains, ['staff.hu']);
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['x.hu']);
});

/**
 * A CSV file with the default headings and the given rows.
 *
 * @param  list<array<string, string>>  $rows
 */
function lengthTestCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'hdid-sync-').'.csv';
    $headings = array_keys($rows[0]);
    $lines = [implode(',', $headings)];

    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(fn (string $cell): string => '"'.str_replace('"', '""', $cell).'"', $row));
    }

    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
}

it('skips a row whose e-mail address is too long with its own reason and imports the others', function (): void {
    $long = str_repeat('a', 65).'@x.hu';
    $path = lengthTestCsv([
        ['external_id' => '1', 'name' => 'Anna', 'email' => 'anna@x.hu'],
        ['external_id' => '2', 'name' => 'Béla', 'email' => $long],
        ['external_id' => '3', 'name' => 'Cili', 'email' => 'not-an-address@'],
    ]);

    $run = app(SyncExternalRecords::class)->run(new SpreadsheetReader($path, []));

    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and($run->stats['incoming']['invalid_email'])->toBe(2)
        ->and($run->stats['incoming']['invalid_row'])->toBe(0)
        ->and(collect($run->skipped_rows)->pluck('reason')->all())->toBe([SkippedRow::REASON_INVALID_EMAIL, SkippedRow::REASON_INVALID_EMAIL])
        ->and(collect($run->skipped_rows)->max(fn (array $row): int => mb_strlen($row['sample']['email'] ?? '')))->toBeLessThanOrEqual(80)
        ->and(ExternalRecord::query()->pluck('external_id')->all())->toBe([1])
        ->and(trans('sync-skip.'.SkippedRow::REASON_INVALID_EMAIL, [], 'hu'))->not->toBe('sync-skip.'.SkippedRow::REASON_INVALID_EMAIL, 'the run page names the reason');

    unlink($path);
});

it('cuts text cells longer than their column and reports which records were cut', function (): void {
    $path = lengthTestCsv([
        ['external_id' => '7', 'name' => str_repeat('N', 300), 'email' => 'long@x.hu', 'company' => str_repeat('C', 256), 'title' => 'Short'],
    ]);

    $run = app(SyncExternalRecords::class)->run(new SpreadsheetReader($path, []));
    $record = ExternalRecord::query()->sole();

    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and(mb_strlen($record->name))->toBe(InputLimits::NAME)
        ->and(mb_strlen($record->company))->toBe(InputLimits::NAME)
        ->and($record->title)->toBe('Short')
        ->and(mb_strlen(Client::query()->sole()->name))->toBe(InputLimits::NAME)
        ->and($run->stats['truncated'])->toBe(1)
        ->and($run->stats['truncated_samples'])->toBe([['external_id' => 7, 'fields' => 'name, company']]);

    unlink($path);
});

it('turns a mapped row into a record or a skipped row with the reason', function (): void {
    $mapping = ['external_id' => 'id', 'email' => 'mail'];

    expect(ExternalRecordDto::fromRowOrSkip(['id' => '5', 'mail' => 'Anna <Anna@X.hu>'], $mapping))->toBeInstanceOf(ExternalRecordDto::class)
        ->and(ExternalRecordDto::fromRowOrSkip(['id' => 'x', 'mail' => 'a@x.hu'], $mapping)->reason)->toBe(SkippedRow::REASON_INVALID_ROW)
        ->and(ExternalRecordDto::fromRowOrSkip(['id' => '5', 'mail' => ''], $mapping)->reason)->toBe(SkippedRow::REASON_INVALID_ROW)
        ->and(ExternalRecordDto::fromRowOrSkip(['id' => '5', 'mail' => 'a@'.str_repeat('b', 64).'.hu'], $mapping)->reason)->toBe(SkippedRow::REASON_INVALID_EMAIL);
});

it('labels a run by its source address without the query, the credentials and beyond the column', function (): void {
    expect(SourceLabel::of('https://user:secret@emd.test:8443/export/'.str_repeat('p', 300).'?token=abc#frag'))
        ->toBe(mb_substr('https://emd.test:8443/export/'.str_repeat('p', 300), 0, InputLimits::NAME))
        ->and(SourceLabel::of('export.csv'))->toBe('export.csv')
        ->and(SourceLabel::of(null))->toBeNull();

    $reader = new class implements SourceReader
    {
        public function source(): SyncSource
        {
            return SyncSource::Api;
        }

        public function label(): ?string
        {
            return 'https://emd.test/export?api_key=secret-value&'.str_repeat('x=1&', 100);
        }

        public function read(): iterable
        {
            yield new ExternalRecordDto(1, 'Anna', 'anna@x.hu', null);
        }

        public function details(): array
        {
            return [];
        }
    };

    expect(app(SyncExternalRecords::class)->run($reader)->file_name)->toBe('https://emd.test/export');
});

it('labels a queued EMD API run without the query of the configured address', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Queue::fake();
    Storage::fake('local');
    config()->set([
        'queue.default' => 'database', 'queue.connections.database.retry_after' => 1260,
        'hdid.sync.driver' => 'spreadsheet',
        'hdid.sync.api_url' => 'https://emd.test/export?key=secret-value&pad='.str_repeat('p', 300),
    ]);

    $run = app(QueuedSyncs::class)->start(User::factory()->withRole(Role::Admin)->create(), true);

    expect(SyncRun::query()->sole()->file_name)->toBe('https://emd.test/export')
        ->and($run->file_name)->toBe('https://emd.test/export');
});

it('shows the invalid addresses and the cut records on the run page', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');
    $path = lengthTestCsv([
        ['external_id' => '1', 'name' => str_repeat('N', 300), 'email' => 'anna@x.hu'],
        ['external_id' => '2', 'name' => 'Béla', 'email' => str_repeat('a', 65).'@x.hu'],
    ]);
    $run = app(SyncExternalRecords::class)->run(new SpreadsheetReader($path, []));
    unlink($path);

    $page = Livewire::test(ManageSyncRuns::class)->mountAction(TestAction::make('view')->table($run))->instance();
    $entries = [];
    foreach ($page->getSchema($page->getMountedActionSchemaName())->getFlatComponents(withHidden: true) as $entry) {
        if ($entry instanceof Entry) {
            $entries[$entry->getName()] = [$entry->isVisible(), $entry->getState()];
        }
    }

    expect($entries['stats.incoming.invalid_email'])->toBe([true, 1])
        ->and($entries['stats.truncated'])->toBe([true, 1])
        ->and($entries['stats.truncated_samples'][0])->toBeTrue();
});

it('keeps a known record and its account when its address, taken by earlier imports, fails the stricter check', function (): void {
    app(Settings::class)->set(SettingKey::SyncMissedRunsBeforeClose, 2);
    $fine = ['external_id' => '1', 'name' => 'Anna', 'email' => 'anna@x.hu'];
    $sync = app(SyncExternalRecords::class);

    $path = lengthTestCsv([$fine, ['external_id' => '2', 'name' => 'Béla', 'email' => 'bela@x.hu']]);
    $sync->run(new SpreadsheetReader($path, []));
    unlink($path);
    // An address an earlier version took without checking.
    ExternalRecord::query()->where('external_id', 2)->update(['email' => 'bela.@x.hu']);
    Client::query()->where('email', 'bela@x.hu')->update(['email' => 'bela.@x.hu']);

    foreach (range(1, 3) as $ignored) {
        $path = lengthTestCsv([$fine, ['external_id' => '2', 'name' => 'Béla', 'email' => 'bela.@x.hu']]);
        $run = $sync->run(new SpreadsheetReader($path, []));
        unlink($path);
    }

    $record = ExternalRecord::query()->where('external_id', 2)->sole();
    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and($run->stats['incoming']['invalid_email'])->toBe(1)
        ->and($run->stats['invalid_email_kept'])->toBe(1)
        ->and($run->stats['invalid_email_kept_samples'][0])->toMatchArray(['external_id' => 2, 'email' => 'bela.@x.hu', 'kept' => 'bela.@x.hu'])
        ->and($run->stats['closed'])->toBe(0)
        ->and($record->missed_runs)->toBe(0)
        ->and($record->missing_since)->toBeNull()
        ->and(Client::query()->where('external_record_id', $record->id)->sole()->isClosed())->toBeFalse('the account is not closed as missing');

    // A new record with such an address is still only skipped.
    $path = lengthTestCsv([$fine, ['external_id' => '2', 'name' => 'Béla', 'email' => 'bela.@x.hu'], ['external_id' => '3', 'name' => 'Cili', 'email' => 'cili..x@x.hu']]);
    $run = $sync->run(new SpreadsheetReader($path, []));
    unlink($path);
    expect(ExternalRecord::query()->where('external_id', 3)->exists())->toBeFalse()
        ->and($run->stats['invalid_email_kept'])->toBe(1);
});
