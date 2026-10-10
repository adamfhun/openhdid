<?php

use App\Auth\AccountLogin;
use App\Auth\LoginRejectedException;
use App\Auth\Passwordless\OneTimeCodes;
use App\Auth\Role;
use App\Clients\ClientLinks;
use App\Enums\OneTimeCodePurpose;
use App\Enums\PhoneNumberSource;
use App\Enums\PhoneVerificationSource;
use App\Enums\PrincipalType;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSource;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Filament\Admin\Resources\ExternalRecords\ExternalRecordResource;
use App\Filament\Admin\Resources\ExternalRecords\Pages\ManageExternalRecords;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Identification\PinService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientPhoneNumber;
use App\Models\ExternalRecord;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\AccountProvisioner;
use App\Sync\Contracts\SourceReader;
use App\Sync\EmptySourceException;
use App\Sync\ExternalRecordDto;
use App\Sync\ReaderFactory;
use App\Sync\Readers\JsonApiReader;
use App\Sync\SkippedRow;
use App\Sync\SourceFormatException;
use App\Sync\SuspiciousSourceException;
use App\Sync\SyncExternalRecords;
use App\System\CheckStatus;
use App\System\HealthChecks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * @param  list<ExternalRecordDto>  $rows
 */
function readerOf(array $rows): SourceReader
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

function row(string $id, string $email, string $company = 'Acme', array $phones = [], ?string $name = null): ExternalRecordDto
{
    return new ExternalRecordDto((int) $id, $name ?? 'Name '.$id, $email, $company, $phones, ['dept' => 'x'], 'Basic', null);
}

beforeEach(function (): void {
    app(Settings::class)->set(SettingKey::SyncUserDomains, ['staff.hu']);
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['x.hu']);
});

it('creates external records and provisions clients with normalised sync phones', function (): void {
    $run = app(SyncExternalRecords::class)->run(readerOf([
        row('1', 'a@x.hu', 'Acme', ['06 30 123 4567', '+36 20 111 2222']),
    ]));

    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and($run->stats)->toMatchArray(['read' => 1, 'created' => 1, 'provisioned' => 1, 'missing' => 0]);

    $record = ExternalRecord::query()->where('external_id', 1)->first();
    expect($record->kind)->toBe(PrincipalType::Client)
        ->and($record->first_seen_run_id)->toBe($run->id)
        ->and($record->email_domain)->toBe('x.hu')
        ->and($record->last_seen_at)->not->toBeNull()
        ->and($record->attributes)->toBe(['dept' => 'x']);

    $client = Client::query()->where('email', 'a@x.hu')->first();
    expect($client)->not->toBeNull()
        ->and($client->implicit_package)->toBe('Basic')
        ->and($client->external_record_id)->toBe($record->id)
        ->and($client->phoneNumbers->pluck('number_e164')->sort()->values()->all())->toBe(['+36201112222', '+36301234567'])
        ->and($client->phoneNumbers->firstWhere('is_primary', true)->number_e164)->toBe('+36301234567');
});

it('links an existing user by email and never creates users', function (): void {
    $user = User::factory()->create(['email' => 'staff@staff.hu']);

    app(SyncExternalRecords::class)->run(readerOf([
        row('11', 'staff@staff.hu', 'Staff Ltd'),
        row('12', 'nobody@staff.hu', 'Staff Ltd'),
    ]));

    expect($user->fresh()->externalRecord->external_id)->toBe(11)
        ->and(User::query()->count())->toBe(1)
        ->and(Client::query()->count())->toBe(0);
});

it('classifies only explicitly listed e-mail domains', function (): void {
    app(Settings::class)->set(SettingKey::SyncUserDomains, ['staff.hu']);
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['@customer.hu']);
    User::factory()->create(['email' => 'joe@staff.hu']);

    $run = app(SyncExternalRecords::class)->run(readerOf([
        row('1', 'joe@staff.hu', 'Whatever'),
        row('2', 'ann@customer.hu', 'Whatever'),
        row('3', 'x@elsewhere.hu', 'Whatever'),
    ]));

    expect($run->stats['skipped'])->toBe(1)
        ->and(ExternalRecord::query()->where('external_id', 1)->first()->kind)->toBe(PrincipalType::User)
        ->and(ExternalRecord::query()->where('external_id', 2)->first()->kind)->toBe(PrincipalType::Client)
        ->and(Client::query()->where('email', 'ann@customer.hu')->exists())->toBeTrue();
});

it('skips rows whose domain is not listed', function (): void {
    app(Settings::class)->set(SettingKey::SyncClientDomains, []);

    $run = app(SyncExternalRecords::class)->run(readerOf([row('1', 'a@x.hu', 'Unknown Co')]));

    expect($run->stats['skipped'])->toBe(1)->and(ExternalRecord::query()->count())->toBe(0);
});

it('closes accounts that disappear and reopens them when they return', function (): void {
    app(Settings::class)->set(SettingKey::SyncMissedRunsBeforeClose, 1);
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('1', 'a@x.hu'), row('2', 'b@x.hu')]));

    $second = $sync->run(readerOf([row('1', 'a@x.hu')]));
    $b = Client::query()->where('email', 'b@x.hu')->first();

    expect($second->stats)->toMatchArray(['missing' => 1, 'closed' => 1])
        ->and($b->isClosed())->toBeTrue()
        ->and($b->closed_reason)->toBe('sync.missing')
        ->and($b->externalRecord->isMissing())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'account.closed')->where('subject_id', $b->id)->exists())->toBeTrue();

    $sync->run(readerOf([row('1', 'a@x.hu'), row('2', 'b@x.hu')]));

    expect($b->fresh()->isClosed())->toBeFalse()
        ->and($b->externalRecord->fresh()->isMissing())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'account.reopened')->exists())->toBeTrue();
});

it('honours the missed-runs grace threshold', function (): void {
    app(Settings::class)->set(SettingKey::SyncMissedRunsBeforeClose, 2);
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('1', 'a@x.hu'), row('2', 'b@x.hu')]));

    $sync->run(readerOf([row('1', 'a@x.hu')]));
    expect(Client::query()->where('email', 'b@x.hu')->first()->isClosed())->toBeFalse();

    $sync->run(readerOf([row('1', 'a@x.hu')]));
    expect(Client::query()->where('email', 'b@x.hu')->first()->isClosed())->toBeTrue();
});

it('keeps admin and self added phone numbers, replaces sync ones', function (): void {
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('1', 'a@x.hu', 'Acme', ['+36301111111'])]));
    $client = Client::query()->where('email', 'a@x.hu')->first();
    $client->phoneNumbers()->create(['number_e164' => '+36309999999', 'source' => PhoneNumberSource::Admin]);

    $sync->run(readerOf([row('1', 'a@x.hu', 'Acme', ['+36302222222'])]));

    expect($client->fresh()->phoneNumbers->pluck('number_e164')->sort()->values()->all())
        ->toBe(['+36302222222', '+36309999999']);
});

it('refuses an empty source and marks the run failed', function (): void {
    app(SyncExternalRecords::class)->run(readerOf([row('1', 'a@x.hu')]));

    expect(fn () => app(SyncExternalRecords::class)->run(readerOf([])))->toThrow(EmptySourceException::class)
        ->and(Client::query()->first()->isClosed())->toBeFalse()
        ->and(SyncRun::query()->latest('id')->first()->status)->toBe(SyncRunStatus::Failed);
});

it('reads a csv file through the artisan command', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'sync').'.csv';
    file_put_contents($path, "external_id,name,email,company,phone,dept\n7,Zed,zed@x.hu,Acme,+36301234567;06201234567,IT\nnot-a-number,Bad,bad@x.hu,Acme,,IT\n");

    $this->artisan('hdid:emd-sync', ['--file' => $path])->assertSuccessful();

    $client = Client::query()->where('email', 'zed@x.hu')->first();
    expect($client)->not->toBeNull()
        ->and($client->phoneNumbers)->toHaveCount(2)
        ->and($client->externalRecord->attributes)->toBe(['dept' => 'IT'])
        ->and(ExternalRecord::query()->count())->toBe(1, 'non-numeric external ids are skipped');

    unlink($path);
});

it('refuses a source that shrank below the configured share of the previous run', function (): void {
    app(Settings::class)->set(SettingKey::SyncMissedRunsBeforeClose, 1);
    app(Settings::class)->set(SettingKey::SyncMinRowsRatioPercent, 50);
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('1', 'a@x.hu'), row('2', 'b@x.hu'), row('3', 'c@x.hu'), row('4', 'd@x.hu')]));

    expect(fn () => $sync->run(readerOf([row('1', 'a@x.hu')])))->toThrow(SuspiciousSourceException::class)
        ->and(Client::query()->where('email', 'b@x.hu')->first()->isClosed())->toBeFalse()
        ->and(SyncRun::query()->latest('id')->first()->status)->toBe(SyncRunStatus::Failed);

    // Exactly half is still accepted, and 0 switches the guard off.
    expect($sync->run(readerOf([row('1', 'a@x.hu'), row('2', 'b@x.hu')]))->status)->toBe(SyncRunStatus::Completed);
    app(Settings::class)->set(SettingKey::SyncMinRowsRatioPercent, 0);
    expect($sync->run(readerOf([row('1', 'a@x.hu')]))->status)->toBe(SyncRunStatus::Completed);
});

it('brings a returning phone number back instead of colliding with the soft-deleted row', function (): void {
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('1', 'a@x.hu', 'Acme', ['+36301111111'])]));
    $client = Client::query()->where('email', 'a@x.hu')->firstOrFail();

    // The number leaves the directory: the row is soft-deleted, but the
    // (client, number) unique index still holds it.
    $sync->run(readerOf([row('1', 'a@x.hu')]));
    expect($client->fresh()->phoneNumbers)->toHaveCount(0)
        ->and(ClientPhoneNumber::withTrashed()->where('client_id', $client->id)->count())->toBe(1);

    $run = $sync->run(readerOf([row('1', 'a@x.hu', 'Acme', ['+36301111111'])]));

    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and($client->fresh()->phoneNumbers->pluck('number_e164')->all())->toBe(['+36301111111'])
        ->and(ClientPhoneNumber::withTrashed()->where('client_id', $client->id)->count())->toBe(1, 'the trashed row is restored, not inserted again');
});

it('refuses a run whose rows survive but stop classifying, so a domain change cannot close accounts', function (): void {
    app(Settings::class)->set(SettingKey::SyncMissedRunsBeforeClose, 1);
    app(Settings::class)->set(SettingKey::SyncMinRowsRatioPercent, 50);
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('1', 'a@x.hu'), row('2', 'b@x.hu'), row('3', 'c@x.hu'), row('4', 'd@x.hu')]));

    // The directory moves three of the four rows to a domain no list knows:
    // the raw row count is unchanged, only the usable rows collapse.
    expect(fn () => $sync->run(readerOf([row('1', 'a@x.hu'), row('2', 'b@moved.hu'), row('3', 'c@moved.hu'), row('4', 'd@moved.hu')])))
        ->toThrow(SuspiciousSourceException::class);

    expect(Client::query()->where('email', 'b@x.hu')->first()->isClosed())->toBeFalse()
        ->and(SyncRun::query()->latest('id')->first()->status)->toBe(SyncRunStatus::Failed);
});

it('refuses a source whose distinct identifiers shrank even when duplicated rows keep the row count', function (): void {
    app(Settings::class)->set(SettingKey::SyncMissedRunsBeforeClose, 1);
    app(Settings::class)->set(SettingKey::SyncMinRowsRatioPercent, 50);
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('1', 'a@x.hu'), row('2', 'b@x.hu'), row('3', 'c@x.hu'), row('4', 'd@x.hu')]));

    // A broken export repeats the same person four times: four rows, one identifier.
    expect(fn () => $sync->run(readerOf([row('1', 'a@x.hu'), row('1', 'a@x.hu'), row('1', 'a@x.hu'), row('1', 'a@x.hu')])))
        ->toThrow(SuspiciousSourceException::class);

    expect(SyncRun::query()->latest('id')->first()->status)->toBe(SyncRunStatus::Failed)
        ->and(Client::query()->where('email', 'b@x.hu')->first()->isClosed())->toBeFalse()
        ->and(Client::query()->where('email', 'c@x.hu')->first()->isClosed())->toBeFalse()
        ->and(Client::query()->where('email', 'd@x.hu')->first()->isClosed())->toBeFalse();
});

it('counts a repeated identifier once in the usable rows and reports the repeats as skipped', function (): void {
    $run = app(SyncExternalRecords::class)->run(readerOf([
        row('1', 'a@x.hu', name: 'First occurrence'),
        row('1', 'a@x.hu', name: 'Second occurrence'),
        row('2', 'b@x.hu'),
    ]));

    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and($run->stats['incoming']['clients'])->toBe(2)
        ->and($run->stats['incoming']['duplicate'])->toBe(1)
        ->and($run->stats['created'])->toBe(2)
        ->and($run->stats['updated'])->toBe(0, 'the repeat is not written over the first occurrence')
        ->and($run->stats['skipped'])->toBe(1)
        ->and($run->stats['preview'])->toHaveCount(2, 'a skipped duplicate is not previewed')
        ->and(collect($run->skipped_rows)->pluck('reason')->all())->toBe([SkippedRow::REASON_DUPLICATE])
        ->and($run->skipped_rows[0]['sample']['name'])->toBe('Second occurrence')
        ->and(Client::query()->where('email', 'a@x.hu')->first()->name)->toBe('First occurrence');
});

it('adopts the client behind an e-mail when the directory reissues the record', function (): void {
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('1', 'a@x.hu')]));
    $client = Client::query()->where('email', 'a@x.hu')->firstOrFail();

    // The same person comes back under a new external id, e-mail unchanged;
    // the unique e-mail index would break a second insert.
    $run = $sync->run(readerOf([row('9', 'a@x.hu')]));

    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and(Client::query()->where('email', 'a@x.hu')->count())->toBe(1)
        ->and($client->fresh()->externalRecord->external_id)->toBe(9)
        ->and(AuditLog::query()->where('event', 'account.rebound')->exists())->toBeTrue();
});

it('writes no audit row for the bookkeeping columns a run stamps on every record', function (): void {
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('1', 'a@x.hu'), row('2', 'b@x.hu')]));
    AuditLog::query()->delete();

    // An unchanged directory: only last_seen_at and last_seen_run_id move.
    $sync->run(readerOf([row('1', 'a@x.hu'), row('2', 'b@x.hu')]));

    expect(AuditLog::query()->where('event', 'external_record.updated')->count())->toBe(0);

    // A real change is still audited.
    $sync->run(readerOf([row('1', 'a@x.hu', 'New Company'), row('2', 'b@x.hu')]));
    expect(AuditLog::query()->where('event', 'external_record.updated')->count())->toBe(1);
});

it('reports a dry run without writing anything', function (): void {
    app(Settings::class)->set(SettingKey::SyncMissedRunsBeforeClose, 1);
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('1', 'a@x.hu'), row('2', 'b@x.hu')]));

    $run = $sync->run(readerOf([row('1', 'a@x.hu'), row('3', 'c@x.hu')]), dryRun: true);

    expect($run->dry_run)->toBeTrue()
        ->and($run->status)->toBe(SyncRunStatus::Completed)
        ->and($run->stats)->toMatchArray(['read' => 2, 'created' => 1, 'updated' => 1, 'missing' => 1, 'closed' => 1])
        ->and($run->stats['samples']['created'])->toBe(['Name 3 <c@x.hu>'])
        ->and($run->stats['samples']['closed'])->toBe(['Name 2 <b@x.hu>'])
        ->and(Client::query()->where('email', 'c@x.hu')->exists())->toBeFalse()
        ->and(Client::query()->where('email', 'b@x.hu')->first()->isClosed())->toBeFalse()
        ->and(ExternalRecord::query()->where('external_id', 2)->first()->missed_runs)->toBe(0)
        ->and(AuditLog::query()->where('event', 'sync.dry_run')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'account.closed')->exists())->toBeFalse();

    // A dry run is not a baseline for the shrink guard.
    $real = $sync->run(readerOf([row('1', 'a@x.hu'), row('2', 'b@x.hu')]));
    expect($real->status)->toBe(SyncRunStatus::Completed)->and($real->stats['missing'])->toBe(0);
});

it('keeps a sample of skipped rows with the reason', function (): void {
    app(Settings::class)->set(SettingKey::SyncClientDomains, []);
    app(Settings::class)->set(SettingKey::SyncClientDomains, []);

    $run = app(SyncExternalRecords::class)->run(readerOf([
        row('1', 'a@x.hu'),
        row('2', 'b@other.hu'),
        new SkippedRow(SkippedRow::REASON_INVALID_ROW, ['external_id' => 'abc']),
    ]));

    expect($run->stats)->toMatchArray(['read' => 3, 'skipped' => 3, 'created' => 0])
        ->and(collect($run->skipped_rows)->pluck('reason')->all())->toBe(['unclassified', 'unclassified', 'invalid_row'])
        ->and($run->skipped_rows[1]['sample']['email'])->toBe('b@other.hu');
});

it('counts provisioned clients without extra existence queries', function (): void {
    $sync = app(SyncExternalRecords::class);
    $first = $sync->run(readerOf([row('1', 'a@x.hu'), row('2', 's@staff.hu')]));
    $second = $sync->run(readerOf([row('1', 'a@x.hu'), row('2', 's@staff.hu')]));

    expect($first->stats['provisioned'])->toBe(1)->and($second->stats['provisioned'])->toBe(0);
});

it('refuses a spreadsheet whose header lacks the identifying columns', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'sync').'.csv';
    file_put_contents($path, "id,fullname\n7,Zed\n");

    expect(fn () => app(SyncExternalRecords::class)->run(app(ReaderFactory::class)->forFile($path)))
        ->toThrow(SourceFormatException::class, 'external_user_id, email');
    expect(SyncRun::query()->latest('id')->first()->status)->toBe(SyncRunStatus::Failed);
});

it('converts a csv from the configured character set', function (): void {
    app(Settings::class)->set(SettingKey::SyncCsvEncoding, 'Windows-1250');
    $path = tempnam(sys_get_temp_dir(), 'sync').'.csv';
    file_put_contents($path, iconv('UTF-8', 'CP1250', "external_id,name,email\n8,Kovács Árpád,arpad@x.hu\n"));

    app(SyncExternalRecords::class)->run(app(ReaderFactory::class)->forFile($path));

    expect(ExternalRecord::query()->where('external_id', 8)->first()->name)->toBe('Kovács Árpád');
});

it('treats a missing data path and a repeated page in the api response as errors', function (): void {
    $mapping = ['external_id' => 'external_id', 'name' => 'name', 'email' => 'email', 'company' => 'company', 'phones' => 'phone'];

    Http::fake(['https://dir.test/broken*' => Http::response(['unexpected' => []])]);
    $broken = new JsonApiReader(app(Factory::class), 'https://dir.test/broken', [], $mapping, 'data', 'page');
    expect(fn () => iterator_to_array($broken->read()))->toThrow(SourceFormatException::class, 'no "data" element');

    // The endpoint ignores the page parameter and serves page 1 forever.
    Http::fake(['https://dir.test/loop*' => Http::response(['data' => [['external_id' => 1, 'name' => 'A', 'email' => 'a@x.hu']]])]);
    $looping = new JsonApiReader(app(Factory::class), 'https://dir.test/loop', [], $mapping, 'data', 'page');
    expect(fn () => iterator_to_array($looping->read(), false))->toThrow(SourceFormatException::class, 'same rows for page 2 as for page 1');

    // Without a page parameter a single response is the whole directory.
    $single = new JsonApiReader(app(Factory::class), 'https://dir.test/loop', [], $mapping, 'data', null);
    expect(iterator_to_array($single->read(), false))->toHaveCount(1);
});

it('fails the sync instead of closing accounts when the api returns the same page for every page number', function (): void {
    config()->set('hdid.sync.driver', 'json');
    config()->set('hdid.sync.api_url', 'https://dir.test/people');
    $settings = app(Settings::class);
    $settings->set(SettingKey::SyncColumnMapping, ['external_id' => 'external_id', 'name' => 'name', 'email' => 'email']);
    $settings->set(SettingKey::SyncApiDataPath, 'data');
    $settings->set(SettingKey::SyncApiPageParam, 'page');
    $settings->set(SettingKey::SyncMissedRunsBeforeClose, 1);

    $firstPage = [
        ['external_id' => 1, 'name' => 'Anna', 'email' => 'anna@x.hu'],
        ['external_id' => 2, 'name' => 'Béla', 'email' => 'bela@x.hu'],
    ];
    $thirdPage = [['external_id' => 3, 'name' => 'Csaba', 'email' => 'csaba@x.hu']];

    $requestedPages = [];
    Http::fake(function (Request $request) use (&$requestedPages, $firstPage, $thirdPage) {
        $page = (int) ($request->data()['page'] ?? 0);
        $requestedPages[] = $page;

        // The endpoint ignores the page parameter for pages 1 and 2 and would
        // only hand out Csaba on page 3, which the reader never asks for.
        return Http::response(['data' => $page === 3 ? $thirdPage : $firstPage]);
    });

    $csaba = Client::factory()->synced()->create(['email' => 'csaba@x.hu']);
    $csaba->externalRecord->update(['external_id' => 3]);

    expect(fn () => app(SyncExternalRecords::class)->run(app(ReaderFactory::class)->forApi()))
        ->toThrow(SourceFormatException::class);

    expect($requestedPages)->toBe([1, 2], 'the reader stopped at the repeated second page');

    $run = SyncRun::query()->latest('started_at')->firstOrFail();
    expect($run->status)->toBe(SyncRunStatus::Failed)
        ->and($run->stats['closed'] ?? 0)->toBe(0)
        ->and($csaba->fresh()->isClosed())->toBeFalse('an account missing only from a truncated read must not be closed');
});

it('loads the directory identifier, job title and department as fixed fields, with the identifier limited to seven digits', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'sync').'.csv';
    file_put_contents($path, "external_user_id,name,email,company,title,department,phone,cost_center\n"
        ."1234567,Zed,zed@x.hu,Acme,Vezető,Pénzügy,+36301234567,CC-1\n"
        ."12345678,Long,long@x.hu,Acme,,,,CC-2\n"
        ."0,Zero,zero@x.hu,Acme,,Ügyfélszolgálat,,CC-3\n");

    $this->artisan('hdid:emd-sync', ['--file' => $path])->assertSuccessful();

    $zed = Client::query()->where('email', 'zed@x.hu')->firstOrFail();
    $zero = Client::query()->where('email', 'zero@x.hu')->firstOrFail();

    expect($zed->externalRecord->external_id)->toBe(1234567)
        ->and($zed->externalRecord->title)->toBe('Vezető')
        ->and($zed->externalRecord->department)->toBe('Pénzügy')
        ->and($zed->externalRecord->jobLabel())->toBe('Vezető · Pénzügy')
        ->and($zed->externalRecord->attributes)->toBe(['cost_center' => 'CC-1'], 'mapped columns never land among the other columns')
        ->and($zero->externalRecord->title)->toBeNull()
        ->and($zero->externalRecord->department)->toBe('Ügyfélszolgálat')
        ->and(Client::query()->where('email', 'long@x.hu')->exists())->toBeFalse('an eight-digit identifier is not a directory id')
        ->and(SyncRun::query()->latest('id')->first()->skipped_rows[0]['sample']['external_id'] ?? null)->toBe('12345678');

    unlink($path);
});

it('still accepts a file whose identifier column is headed external_id', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'sync').'.csv';
    file_put_contents($path, "external_id,name,email\n42,Old,old@x.hu\n");

    $this->artisan('hdid:emd-sync', ['--file' => $path])->assertSuccessful();

    expect(Client::query()->where('email', 'old@x.hu')->firstOrFail()->externalRecord->external_id)->toBe(42);

    unlink($path);
});

it('ignores obsolete domain settings and gives staff precedence for explicitly permitted overlap', function (): void {
    Setting::query()->create(['key' => 'sync.allowed_email_domains', 'value' => ['obsolete.hu']]);
    Setting::query()->create(['key' => 'sync.default_kind', 'value' => 'client']);
    $settings = app(Settings::class);
    $settings->forget();
    $settings->setMany([
        SettingKey::SyncUniqueDomains->value => false,
        SettingKey::SyncUserDomains->value => ['SHARED.HU'],
        SettingKey::SyncClientDomains->value => ['shared.hu', 'customer.hu'],
    ]);
    $run = app(SyncExternalRecords::class)->run(readerOf([
        row('1', 'STAFF@ShArEd.Hu'), row('2', 'a@CUSTOMER.HU'), row('3', 'a@unlisted.hu'),
    ]));
    expect(ExternalRecord::query()->where('external_id', 1)->sole()->kind)->toBe(PrincipalType::User)
        ->and(ExternalRecord::query()->where('external_id', 2)->sole()->kind)->toBe(PrincipalType::Client)
        ->and($run->stats['skipped'])->toBe(1);
});

it('imports nullable directory details and normalizes comma-separated phones from two mapped columns for both kinds', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'sync').'.csv';
    $mapping = ['external_id' => 'Azonosító', 'email' => 'E-mail', 'name' => 'Név',
        'login_name' => 'Belépési név', 'room' => 'Szoba', 'employment_status' => 'Jogviszony',
        'phones' => ['Mobil', 'Vezetékes']];
    app(Settings::class)->set(SettingKey::SyncColumnMapping, $mapping);
    file_put_contents($path, "Azonosító,E-mail,Név,Belépési név,Szoba,Jogviszony,Mobil,Vezetékes\n"
        .'1,a@x.hu,Ügyfél,alice,201,Aktív,"06 30 123 4567, 36301234567","06/1-234-5678, hibás"'."\n"
        .'2,a@staff.hu,Munkatárs,bob,202,Aktív,"0036201112222, +36 20 111 2222","+3612345678"'."\n");
    try {
        $sync = app(SyncExternalRecords::class);
        $run = $sync->run(app(ReaderFactory::class)->forFile($path));
        $client = ExternalRecord::query()->where('external_id', 1)->sole();
        $staff = ExternalRecord::query()->where('external_id', 2)->sole();
        expect($client->login_name)->toBe('alice')->and($client->room)->toBe('201')
            ->and($client->employment_status)->toBe('Aktív')
            ->and($client->attributes)->toBe([])
            ->and($client->phones)->toBe(['+36301234567', '+3612345678'])
            ->and($staff->phones)->toBe(['+36201112222', '+3612345678'])
            ->and($client->client->phoneNumbers->pluck('number_e164')->sort()->values()->all())->toBe(collect($client->phones)->sort()->values()->all())
            ->and($run->stats['invalid_phones'])->toBe(1)
            ->and($run->stats['phone_warnings'])->toBe([['external_id' => 1, 'number' => 'hibás']])
            ->and($run->stats['skipped'])->toBe(0);

        file_put_contents($path, "Azonosító,E-mail,Név,Belépési név,Szoba\n1,a@x.hu,Ügyfél, ,\n2,a@staff.hu,Munkatárs,,\n");
        $sync->run(app(ReaderFactory::class)->forFile($path), dryRun: true);
        expect($client->fresh()->login_name)->toBe('alice');
        $sync->run(app(ReaderFactory::class)->forFile($path));
        expect($client->fresh()->login_name)->toBeNull()->and($client->fresh()->room)->toBeNull()
            ->and($client->fresh()->employment_status)->toBeNull();
    } finally {
        unlink($path);
    }
});

it('reads an uploaded csv with the configured delimiter', function (): void {
    config()->set('hdid.sync.csv_delimiter', ';');
    $path = tempnam(sys_get_temp_dir(), 'hdid-sync').'.csv';
    file_put_contents($path, "external_user_id;name;email;company\n5;Semi Colon;semi@x.hu;Acme\n");

    $this->artisan('hdid:emd-sync', ['--file' => $path])->assertSuccessful();
    unlink($path);

    expect(Client::query()->where('email', 'semi@x.hu')->exists())->toBeTrue();
});

/**
 * The directory vouches for a number the client had only added by hand: it
 * becomes a verified directory number (it outranks unverified ones in the
 * caller lookup and the client can no longer remove it), the label stays.
 */
it('adopts a self-added number as a verified directory number once the directory carries it', function (): void {
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('1', 'a@x.hu')]));
    $client = Client::query()->where('email', 'a@x.hu')->firstOrFail();
    $phone = $client->phoneNumbers()->create(['number_e164' => '+36301111111', 'source' => PhoneNumberSource::ClientSelf, 'label' => 'mobil']);

    $sync->run(readerOf([row('1', 'a@x.hu', 'Acme', ['+36301111111'])]));

    expect($phone->fresh()->source)->toBe(PhoneNumberSource::Sync)
        ->and($phone->fresh()->verified_at)->not->toBeNull()
        ->and($phone->fresh()->label)->toBe('mobil')
        ->and($client->fresh()->phoneNumbers()->count())->toBe(1);
});

/**
 * Two directory rows with one e-mail are one client, not a client that is
 * rebound to the other row on every run: the first row wins, the repeat is
 * reported, and nothing bounces.
 */
it('imports one client for an e-mail that several directory rows share and reports the repeats', function (): void {
    $sync = app(SyncExternalRecords::class);
    $rows = fn (): array => [row('1', 'a@x.hu', name: 'Első Elek'), row('2', 'a@x.hu', name: 'Második Miklós'), row('3', 'b@x.hu')];

    $run = $sync->run(readerOf($rows()));

    $client = Client::query()->where('email', 'a@x.hu')->firstOrFail();
    expect($client->name)->toBe('Első Elek')
        ->and($client->externalRecord->external_id)->toBe(1)
        ->and(ExternalRecord::query()->where('external_id', 2)->exists())->toBeFalse()
        ->and($run->stats['incoming']['clients'])->toBe(2)
        ->and($run->stats['incoming'][SkippedRow::REASON_DUPLICATE_EMAIL])->toBe(1)
        ->and($run->skipped_rows[0]['reason'])->toBe(SkippedRow::REASON_DUPLICATE_EMAIL)
        ->and($run->skipped_rows[0]['sample']['external_id'])->toBe('2');

    $sync->run(readerOf($rows()));

    expect($client->fresh()->name)->toBe('Első Elek')
        ->and($client->fresh()->externalRecord->external_id)->toBe(1)
        ->and(AuditLog::query()->where('event', 'account.rebound')->exists())->toBeFalse();
});

it('keeps the pin when the directory replaces the only number in one run', function (): void {
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['x.hu']);
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('1', 'a@x.hu', 'Acme', ['+36301111111'])]));
    $client = Client::query()->where('email', 'a@x.hu')->firstOrFail();
    app(PinService::class)->setPin($client, '123456');

    $sync->run(readerOf([row('1', 'a@x.hu', 'Acme', ['+36309999999'])]));

    expect($client->fresh()->hasPin())->toBeTrue()
        ->and($client->fresh()->phoneNumbers->pluck('number_e164')->all())->toBe(['+36309999999'])
        ->and($client->fresh()->phoneNumbers->first()->verified_via)->toBe(PhoneVerificationSource::Directory);

    $sync->run(readerOf([row('1', 'a@x.hu', 'Acme', [])]));

    expect($client->fresh()->hasPin())->toBeFalse('the directory dropped the last number');
});

it('never closes the administrator created by hdid:make-admin before the directory was connected', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->artisan('hdid:make-admin', ['email' => 'root@local.example', '--password' => 'a-long-password-1'])->assertSuccessful();
    $admin = User::query()->where('email', 'root@local.example')->firstOrFail();
    $sync = app(SyncExternalRecords::class);

    $sync->run(readerOf([row('1', 'a@x.hu')]));
    $second = $sync->run(readerOf([row('1', 'a@x.hu')]));

    expect($admin->fresh()->isClosed())->toBeFalse()
        ->and($admin->externalRecord->isLocal())->toBeTrue()
        ->and($admin->externalRecord->fresh()->isMissing())->toBeFalse()
        ->and($second->stats)->toMatchArray(['missing' => 0, 'closed' => 0]);
});

it('hands a bootstrapped administrator over to the directory row with its e-mail, audited', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->artisan('hdid:make-admin', ['email' => 'root@staff.hu', '--password' => 'a-long-password-1'])->assertSuccessful();
    $admin = User::query()->where('email', 'root@staff.hu')->firstOrFail();
    $localRecordId = $admin->external_record_id;
    $sync = app(SyncExternalRecords::class);

    $sync->run(readerOf([row('7', 'root@staff.hu', 'Acme')]));
    $sync->run(readerOf([row('7', 'root@staff.hu', 'Acme')]));
    $admin = $admin->fresh();

    expect($admin->isClosed())->toBeFalse()
        ->and($admin->externalRecord->external_id)->toBe(7)
        ->and(ExternalRecord::query()->where('email', 'root@staff.hu')->count())->toBe(1, 'the local bootstrap record is gone')
        ->and(ExternalRecord::query()->withTrashed()->whereKey($localRecordId)->first()->trashed())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'account.rebound')->where('subject_id', $admin->id)->sole()->context)->toMatchArray(['to_external_id' => 7]);

    // Promoting an already synced user keeps its directory record.
    $this->artisan('hdid:make-admin', ['email' => 'root@staff.hu', '--password' => 'a-long-password-1'])->assertSuccessful();
    expect($admin->fresh()->externalRecord->external_id)->toBe(7);
});

it('refuses to start under a PHP time limit below 120 seconds', function (): void {
    set_time_limit(60);

    try {
        expect(SyncExternalRecords::insufficientTimeLimit())->toBe(60)
            ->and(fn () => app(SyncExternalRecords::class)->run(readerOf([row('1', 'a@x.hu')])))->toThrow(RuntimeException::class, 'max_execution_time is 60 s');
        expect(SyncRun::query()->count())->toBe(0);

        set_time_limit(120);
        expect(SyncExternalRecords::insufficientTimeLimit())->toBeNull();
    } finally {
        set_time_limit(0);
    }
});

it('turns a run that PHP cut short into a failed run and frees the sync lock', function (): void {
    $sync = app(SyncExternalRecords::class);
    $aborting = new class implements SourceReader
    {
        public function source(): SyncSource
        {
            return SyncSource::Csv;
        }

        public function label(): ?string
        {
            return 'cut-short.csv';
        }

        public function read(): iterable
        {
            yield row('1', 'a@x.hu');
            // PHP dies here (time limit): only the shutdown handler runs.
            SyncExternalRecords::simulateAbort();
            $probe = Cache::lock('hdid:sync', 3600);
            expect($probe->get())->toBeTrue('the guard released the sync lock');
            $probe->release();
            expect(SyncRun::query()->sole()->status)->toBe(SyncRunStatus::Failed);
            throw new RuntimeException('simulated fatal');
        }

        public function details(): array
        {
            return [];
        }
    };

    expect(fn () => $sync->run($aborting))->toThrow(RuntimeException::class, 'simulated fatal');

    $run = SyncRun::query()->sole();
    expect($run->status)->toBe(SyncRunStatus::Failed)
        ->and($run->error)->toContain('simulated fatal')
        ->and(AuditLog::query()->where('event', 'sync.failed')->where('context->aborted', true)->exists())->toBeTrue()
        ->and(Client::query()->where('email', 'a@x.hu')->exists())->toBeTrue('the rows written before the cut stay');

    // A completed run disarms the guard: nothing happens at shutdown.
    $sync->run(readerOf([row('1', 'a@x.hu')]));
    SyncExternalRecords::simulateAbort();
    expect(SyncRun::query()->latest('started_at')->first()->status)->toBe(SyncRunStatus::Completed);
});

it('forecasts the accounts the next sync closes: run stats, EMD list, client and staff tabs, health tile', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament\Facades\Filament::setCurrentPanel('admin');
    $sync = app(SyncExternalRecords::class);
    $all = fn (): array => [row('1', 'a@x.hu'), row('2', 'b@x.hu'), row('3', 'staff@staff.hu', 'Acme'), row('4', 'c@x.hu')];
    $sync->run(readerOf($all()));
    $staff = User::factory()->create(['email' => 'staff@staff.hu']);
    $sync->run(readerOf($all()));
    expect($staff->fresh()->external_record_id)->not->toBeNull();

    // b and the staff member are absent once: one more absent run closes them (threshold 2).
    $run = $sync->run(readerOf([row('1', 'a@x.hu'), row('4', 'c@x.hu')]));
    $b = Client::query()->where('email', 'b@x.hu')->first();

    expect($run->stats)->toMatchArray(['missing' => 0, 'closed' => 0, 'closing_next' => 2])
        ->and($run->stats['samples']['closing_next'])->toBe(['Name 2 <b@x.hu>', 'Name 3 <staff@staff.hu>'])
        ->and($b->isClosed())->toBeFalse()
        ->and($b->externalRecord->closesAtNextRun(2))->toBeTrue()
        ->and(ExternalRecord::query()->where('email', 'a@x.hu')->first()->closesAtNextRun(2))->toBeFalse();

    Livewire\Livewire::test(ManageExternalRecords::class)
        ->assertSee(__('closes at the next sync'))
        ->set('activeTab', 'closing')
        ->assertCanSeeTableRecords([$b->externalRecord, $staff->fresh()->externalRecord])
        ->assertCountTableRecords(2);
    Livewire\Livewire::test(ListClients::class)
        ->set('activeTab', 'closing')
        ->assertCanSeeTableRecords([$b])
        ->assertCountTableRecords(1);
    Livewire\Livewire::test(ListUsers::class)
        ->set('activeTab', 'closing')
        ->assertCanSeeTableRecords([$staff])
        ->assertCountTableRecords(1);

    $health = app(HealthChecks::class)->syncApi();
    expect($health->status)->toBe(CheckStatus::Warn)->and($health->detail)->toContain('2 account(s) close at the next sync');

    // The records return: the forecast clears without any closure.
    $sync->run(readerOf($all()));
    expect(ExternalRecordResource::closingAtNextRunCount())->toBe(0)
        ->and($b->fresh()->isClosed())->toBeFalse()
        ->and(app(HealthChecks::class)->syncApi()->status)->toBe(CheckStatus::Ok);

    // A trial run forecasts too, without changing the counters.
    $dry = $sync->run(readerOf([row('1', 'a@x.hu'), row('4', 'c@x.hu')]), dryRun: true);
    expect($dry->stats['closing_next'])->toBe(2)
        ->and(ExternalRecord::query()->where('email', 'b@x.hu')->first()->missed_runs)->toBe(0);
});

function rowWithPackages(string $id, string $email, ?string $implicit, ?string $explicit, ?string $name = null): ExternalRecordDto
{
    return new ExternalRecordDto((int) $id, $name ?? 'Name '.$id, $email, 'Acme', [], [], $implicit, $explicit);
}

it('ends the links of a sponsor whose implicit package stops being premium and keeps the dependents open', function (): void {
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([rowWithPackages('1', 'fo@x.hu', 'Premium', null), rowWithPackages('2', 'kap@x.hu', 'Basic', 'Premium')]));
    $sponsor = Client::query()->where('email', 'fo@x.hu')->first();
    $dependent = Client::query()->where('email', 'kap@x.hu')->first();
    $link = app(ClientLinks::class)->link($sponsor, $dependent);

    // The directory moves the sponsor to a standard package; the dependent stays in the directory.
    $sync->run(readerOf([rowWithPackages('1', 'fo@x.hu', 'Basic', null), rowWithPackages('2', 'kap@x.hu', 'Basic', 'Premium')]));

    expect($link->fresh()->isActive())->toBeFalse()
        ->and($link->fresh()->ended_reason)->toBe(ClientLinks::END_REASON_SPONSOR_INELIGIBLE)
        ->and($dependent->fresh()->isClosed())->toBeFalse('a dependent keeps whatever its own packages entitle it to')
        ->and($dependent->fresh()->sponsor())->toBeNull()
        ->and(app(ClientLinks::class)->isMissingSponsor($dependent->fresh()))->toBeTrue('the warning applies again')
        ->and(AuditLog::query()->where('event', 'client.unlinked')->where('subject_id', $dependent->id)->exists())->toBeTrue();
});

it('ends the links when a sponsor returns without implicit premium and reopens the dependents it had closed', function (): void {
    app(Settings::class)->set(SettingKey::SyncMissedRunsBeforeClose, 1);
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([rowWithPackages('1', 'fo@x.hu', 'Premium', null), rowWithPackages('2', 'kap@x.hu', 'Basic', 'Premium'), rowWithPackages('3', 'masik@x.hu', 'Basic', 'Premium')]));
    $sponsor = Client::query()->where('email', 'fo@x.hu')->first();
    $present = Client::query()->where('email', 'kap@x.hu')->first();
    $absent = Client::query()->where('email', 'masik@x.hu')->first();
    app(ClientLinks::class)->link($sponsor, $present);
    app(ClientLinks::class)->link($sponsor, $absent);

    // The sponsor leaves the directory: it closes and takes its dependents with it.
    $sync->run(readerOf([rowWithPackages('2', 'kap@x.hu', 'Basic', 'Premium'), rowWithPackages('3', 'masik@x.hu', 'Basic', 'Premium')]));
    expect($sponsor->fresh()->closed_reason)->toBe('sync.missing')
        ->and($present->fresh()->closed_reason)->toBe(ClientLinks::CLOSE_REASON_SPONSOR);

    // It returns with a standard package, while the third client has left the directory meanwhile.
    $sync->run(readerOf([rowWithPackages('1', 'fo@x.hu', 'Basic', null), rowWithPackages('2', 'kap@x.hu', 'Basic', 'Premium')]));

    expect($sponsor->fresh()->isClosed())->toBeFalse()
        ->and($sponsor->fresh()->activeLinks()->count())->toBe(0)
        ->and($present->fresh()->isClosed())->toBeFalse('present in the directory: entitled on its own')
        ->and($absent->fresh()->isClosed())->toBeTrue()
        ->and($absent->fresh()->closed_reason)->toBe('sync.missing')
        ->and(AuditLog::query()->where('event', 'account.reopened')->where('subject_id', $present->id)->exists())->toBeTrue();
});

it('follows a staff e-mail change from the directory so the login rule still matches', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->withRole(Role::Agent)->create(['email' => 'regi@staff.hu']);
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('7', 'regi@staff.hu')]));
    expect($user->fresh()->external_record_id)->not->toBeNull();

    $sync->run(readerOf([row('7', 'uj@staff.hu')]));

    expect($user->fresh()->email)->toBe('uj@staff.hu')
        ->and(app(AccountLogin::class)->resolveByEmail(PrincipalType::User, 'uj@staff.hu', 'password')->is($user))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'account.email_changed')->where('subject_id', $user->id)->exists())->toBeTrue();
});

it('keeps a staff e-mail and reports a conflict when another user already holds the new address', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->withRole(Role::Agent)->create(['email' => 'regi@staff.hu']);
    User::factory()->withRole(Role::Agent)->create(['email' => 'foglalt@staff.hu']);
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('7', 'regi@staff.hu')]));
    Log::spy();

    $run = $sync->run(readerOf([row('7', 'foglalt@staff.hu')]));

    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and($run->stats['email_conflicts'])->toBe(1)
        ->and($run->stats['email_conflict_samples'][0])->toMatchArray(['external_id' => 7, 'kind' => 'user', 'email' => 'foglalt@staff.hu', 'kept' => 'regi@staff.hu'])
        ->and($user->fresh()->email)->toBe('regi@staff.hu')
        ->and(AuditLog::query()->where('event', 'sync.email_conflict')->where('subject_id', $user->id)->exists())->toBeTrue();
    Log::shouldHaveReceived('warning')->once();
});

it('keeps a client e-mail and reports the conflict when the new address belongs to another client, without closing anyone', function (): void {
    app(Settings::class)->set(SettingKey::SyncMissedRunsBeforeClose, 1);
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('1', 'a@x.hu'), row('2', 'b@x.hu')]));
    $a = Client::query()->where('email', 'a@x.hu')->first();
    Log::spy();

    // The directory now gives b@x.hu to record 1 while client 2 still holds it.
    $run = $sync->run(readerOf([row('1', 'b@x.hu')]));

    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and($run->stats['email_conflicts'])->toBe(1)
        ->and($a->fresh()->email)->toBe('a@x.hu')
        ->and($a->fresh()->isClosed())->toBeFalse('the record was seen; a conflict never closes the account')
        ->and($a->fresh()->externalRecord->email)->toBe('b@x.hu')
        ->and(app(HealthChecks::class)->syncApi()->status)->toBe(CheckStatus::Warn);
    Log::shouldHaveReceived('warning')->once();

    // The login rule refuses the mismatch until the duplicate is sorted out.
    expect(fn () => app(AccountLogin::class)->resolveByEmail(PrincipalType::Client, 'a@x.hu', 'magic_link'))->toThrow(LoginRejectedException::class);
});

it('changes a record classification only after the grace runs, closing the old account and provisioning the new kind', function (): void {
    app(Settings::class)->set(SettingKey::SyncMissedRunsBeforeClose, 2);
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('1', 'a@x.hu')]));
    $client = Client::query()->where('email', 'a@x.hu')->first();

    // The person moved to the staff domain: the first run only counts.
    $run = $sync->run(readerOf([row('1', 'a@staff.hu')]));
    $record = ExternalRecord::query()->where('external_id', 1)->first();
    expect($run->stats['kind_pending'])->toBe(1)
        ->and($run->stats['samples']['kind_pending'][0])->toContain('(1/2)')
        ->and($record->kind)->toBe(PrincipalType::Client)
        ->and($record->pending_kind)->toBe(PrincipalType::User)
        ->and($client->fresh()->isClosed())->toBeFalse()
        ->and($client->fresh()->email)->toBe('a@staff.hu', 'the client follows the e-mail while the classification is pending');

    // The second consecutive run makes the switch final.
    $run = $sync->run(readerOf([row('1', 'a@staff.hu')]));
    expect($run->stats['kind_changed'])->toBe(1)
        ->and($record->fresh()->kind)->toBe(PrincipalType::User)
        ->and($record->fresh()->pending_kind)->toBeNull()
        ->and($client->fresh()->isClosed())->toBeTrue()
        ->and($client->fresh()->closed_reason)->toBe(AccountProvisioner::CLOSE_REASON_KIND_CHANGED)
        ->and(User::query()->where('email', 'a@staff.hu')->exists())->toBeFalse('staff accounts are never created by the sync');

    // Flipping back takes the same grace, then the client account reopens.
    $sync->run(readerOf([row('1', 'a@x.hu')]));
    expect($client->fresh()->isClosed())->toBeTrue('one run back is only a pending change');
    $sync->run(readerOf([row('1', 'a@x.hu')]));
    expect($record->fresh()->kind)->toBe(PrincipalType::Client)
        ->and($client->fresh()->isClosed())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'account.reopened')->where('subject_id', $client->id)->exists())->toBeTrue();
});

it('does not persist a pending classification change in a trial run', function (): void {
    app(Settings::class)->set(SettingKey::SyncMissedRunsBeforeClose, 2);
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('1', 'a@x.hu')]));

    $run = $sync->run(readerOf([row('1', 'a@staff.hu')]), dryRun: true);

    expect($run->stats['kind_pending'])->toBe(1)
        ->and(ExternalRecord::query()->where('external_id', 1)->first()->pending_kind)->toBeNull();
});

it('signs an account out everywhere when the directory changes who it is', function (string $change): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('1', 'a@x.hu'), row('2', 'b@x.hu')]));
    $client = Client::query()->where('email', 'a@x.hu')->firstOrFail();
    $client->createToken('phone');
    $client->forceFill(['remember_token' => 'cookie-token'])->saveQuietly();
    $epoch = (int) $client->fresh()->session_epoch;
    ['token' => $link] = app(OneTimeCodes::class)->issueToken($client, OneTimeCodePurpose::MagicLink, 60, 'a@x.hu');

    $sync->run(readerOf(match ($change) {
        'renamed e-mail' => [row('1', 'uj@x.hu'), row('2', 'b@x.hu')],
        'reissued record' => [row('9', 'a@x.hu'), row('2', 'b@x.hu')],
        'conflicting e-mail' => [row('1', 'b@x.hu')],
    }));

    $client->refresh();
    expect($client->tokens()->count())->toBe(0)
        ->and($client->remember_token)->not->toBe('cookie-token')
        ->and((int) $client->session_epoch)->toBeGreaterThan($epoch)
        ->and(app(OneTimeCodes::class)->peekToken(OneTimeCodePurpose::MagicLink, $link))->toBeNull('a link sent to the old identity no longer works')
        ->and(AuditLog::query()->where('event', 'account.access_revoked')->where('subject_id', $client->id)->value('context'))->toHaveKey('reason');
})->with([
    'renamed e-mail' => ['renamed e-mail'],
    'reissued record' => ['reissued record'],
    'conflicting e-mail' => ['conflicting e-mail'],
]);

it('signs a staff member out everywhere when the directory renames their e-mail, but not when it first adopts a local admin', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->withRole(Role::Agent)->create(['email' => 'regi@staff.hu']);
    $user->createToken('cli');
    $sync = app(SyncExternalRecords::class);

    $sync->run(readerOf([row('7', 'regi@staff.hu')]));
    expect($user->tokens()->count())->toBe(1, 'binding the existing account to its directory row changes nobody');

    $sync->run(readerOf([row('7', 'uj@staff.hu')]));
    expect($user->tokens()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'account.access_revoked')->where('subject_id', $user->id)->value('context')['reason'])->toBe('email_changed');
});

it('does not sign an unchanged account out on a repeated run', function (): void {
    $sync = app(SyncExternalRecords::class);
    $sync->run(readerOf([row('1', 'a@x.hu')]));
    $client = Client::query()->where('email', 'a@x.hu')->firstOrFail();
    $client->createToken('phone');

    $sync->run(readerOf([row('1', 'a@x.hu')]));

    expect($client->tokens()->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'account.access_revoked')->exists())->toBeFalse();
});
