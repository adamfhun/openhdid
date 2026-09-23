<?php

use App\Enums\PhoneNumberSource;
use App\Enums\PrincipalType;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSource;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientPhoneNumber;
use App\Models\ExternalRecord;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\Contracts\SourceReader;
use App\Sync\EmptySourceException;
use App\Sync\ExternalRecordDto;
use App\Sync\ReaderFactory;
use App\Sync\Readers\JsonApiReader;
use App\Sync\SkippedRow;
use App\Sync\SourceFormatException;
use App\Sync\SuspiciousSourceException;
use App\Sync\SyncExternalRecords;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

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
    };
}

function row(string $id, string $email, string $company = 'Acme', array $phones = []): ExternalRecordDto
{
    return new ExternalRecordDto((int) $id, 'Name '.$id, $email, $company, $phones, ['dept' => 'x'], 'Basic', null);
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

it('treats a missing data path in the api response as an error and stops on a repeated page', function (): void {
    $mapping = ['external_id' => 'external_id', 'name' => 'name', 'email' => 'email', 'company' => 'company', 'phones' => 'phone'];

    Http::fake(['https://dir.test/broken*' => Http::response(['unexpected' => []])]);
    $broken = new JsonApiReader(app(Factory::class), 'https://dir.test/broken', [], $mapping, 'data', 'page');
    expect(fn () => iterator_to_array($broken->read()))->toThrow(SourceFormatException::class);

    Http::fake(['https://dir.test/loop*' => Http::response(['data' => [['external_id' => 1, 'name' => 'A', 'email' => 'a@x.hu']]])]);
    $looping = new JsonApiReader(app(Factory::class), 'https://dir.test/loop', [], $mapping, 'data', 'page');
    expect(iterator_to_array($looping->read(), false))->toHaveCount(1);
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
