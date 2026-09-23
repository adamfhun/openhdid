<?php

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\ApiTokens;
use App\Sync\EmptySourceException;
use App\Sync\ReaderFactory;
use App\Sync\SourceFormatException;
use App\Sync\SyncExternalRecords;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\SimpleExcel\SimpleExcelWriter;

beforeEach(function (): void {
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['ugyfel.hu', 'client.hu']);
    Storage::fake('local');
    Http::preventStrayRequests();
    config()->set([
        'hdid.sync.driver' => 'spreadsheet', 'hdid.sync.export_format' => 'xlsx',
        'hdid.sync.api_url' => 'https://directory.test/export',
        'hdid.sync.login_url' => 'https://directory.test/login',
        'hdid.sync.refresh_url' => 'https://directory.test/refresh',
        'hdid.sync.username' => 'u', 'hdid.sync.password' => 'p',
    ]);
    app(Settings::class)->set(SettingKey::SyncExportPayload, '{"query":{"ids":"12,34","empty":{},"list":[]},"other":"Árvíztűrő"}');
    app(Settings::class)->set(SettingKey::SyncColumnMapping, [
        'external_id' => 'Külső azonosító (ID)', 'name' => 'Teljes név',
        'email' => 'E-mail cím', 'department' => 'Szervezeti egység / osztály',
        'login_name' => 'Belépési név', 'room' => 'Szoba', 'employment_status' => 'Állapot, jogviszony',
    ]);
});

/** @param list<array<string, mixed>> $rows */
function directoryWorkbook(array $rows): string
{
    $path = Storage::disk('local')->path('fixture.xlsx');
    $writer = SimpleExcelWriter::create($path);
    $writer->addRows($rows);
    $writer->close();
    $body = file_get_contents($path);
    unlink($path);

    return $body;
}

function fakeDirectoryExport(string $body, string $contentType = 'application/octet-stream', int $status = 200): void
{
    Http::fake([
        'https://directory.test/login' => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh']),
        'https://directory.test/refresh' => Http::response(['access_token' => 'access-new', 'refresh_token' => 'refresh-new']),
        'https://directory.test/export' => Http::response($body, $status, ['Content-Type' => $contentType]),
    ]);
}

it('posts the unchanged nested payload and maps reordered XLSX columns by their Hungarian headings', function (): void {
    fakeDirectoryExport(directoryWorkbook([[
        'Szervezeti egység / osztály' => 'Pénzügy', 'E-mail cím' => 'agnes@ugyfel.hu',
        'Teljes név' => 'Őri Ágnes', 'Külső azonosító (ID)' => 123,
        'Állapot, jogviszony' => 'Aktív', 'Belépési név' => 'agnes.login', 'Szoba' => 'A-12',
    ]]));
    $this->artisan('hdid:emd-sync')->assertSuccessful();

    expect(Client::query()->sole()->name)->toBe('Őri Ágnes')
        ->and(ExternalRecord::query()->sole()->department)->toBe('Pénzügy')
        ->and(ExternalRecord::query()->sole()->login_name)->toBe('agnes.login')
        ->and(ExternalRecord::query()->sole()->room)->toBe('A-12')
        ->and(ExternalRecord::query()->sole()->employment_status)->toBe('Aktív');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://directory.test/export'
        && $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer access')
        && $request->body() === '{"query":{"ids":"12,34","empty":{},"list":[]},"other":"Árvíztűrő"}');
    expect(Storage::disk('local')->allFiles('sync-uploads'))->toBeEmpty();
});

it('imports CSV using its configured separator and character encoding', function (): void {
    config()->set(['hdid.sync.export_format' => 'csv', 'hdid.sync.csv_delimiter' => ';']);
    app(Settings::class)->set(SettingKey::SyncCsvEncoding, 'Windows-1250');
    fakeDirectoryExport(iconv('UTF-8', 'CP1250', "E-mail cím;Külső azonosító (ID);Teljes név\nagnes@ugyfel.hu;42;Őri Ágnes\n"), 'text/csv');

    $this->artisan('hdid:emd-sync')->assertSuccessful();
    expect(Client::query()->sole()->name)->toBe('Őri Ágnes');
    expect(Storage::disk('local')->allFiles('sync-uploads'))->toBeEmpty();
    Http::assertSentCount(2);
});

it('reports current accounts and domain-filtered incoming counts without changing accounts or losing tokens', function (): void {
    Cache::setDefaultDriver('database');
    $settings = app(Settings::class);
    $settings->set(SettingKey::SyncUserDomains, ['@staff.hu']);
    $settings->set(SettingKey::SyncClientDomains, ['client.hu']);
    User::factory()->create(['email' => 'existing@staff.hu']);
    User::factory()->closed()->create();
    User::factory()->create()->delete();
    Client::factory()->count(2)->create();
    Client::factory()->closed()->create();
    Client::factory()->create()->delete();
    $beforeClients = Client::withTrashed()->get()->toJson();
    $beforeUsers = User::withTrashed()->get()->toJson();
    $beforeAudit = AuditLog::query()->count();

    fakeDirectoryExport(directoryWorkbook([
        ['Külső azonosító (ID)' => 1, 'Teljes név' => 'Munkatárs', 'E-mail cím' => 'EXISTING@STAFF.HU'],
        ['Külső azonosító (ID)' => 2, 'Teljes név' => 'Új ügyfél', 'E-mail cím' => 'new@client.hu'],
        ['Külső azonosító (ID)' => 3, 'Teljes név' => 'Tiltott domain', 'E-mail cím' => 'outside@else.hu'],
        ['Külső azonosító (ID)' => 4, 'Teljes név' => 'Besorolatlan', 'E-mail cím' => 'unknown@unclassified.hu'],
        ['Külső azonosító (ID)' => 'hibás', 'Teljes név' => 'Hibás sor', 'E-mail cím' => 'invalid@client.hu'],
    ]));
    $run = app(SyncExternalRecords::class)->run(app(ReaderFactory::class)->forApi(), dryRun: true);

    expect($run->stats['current'])->toBe(['users' => 1, 'clients' => 2])
        ->and($run->stats['read'])->toBe(5)
        ->and($run->stats['incoming'])->toBe(['users' => 1, 'clients' => 1, 'unclassified' => 2, 'invalid_row' => 1])
        ->and($run->stats['preview'])->toHaveCount(2)
        ->and($run->stats['preview'][0]['email'])->toBe('existing@staff.hu')
        ->and(Client::withTrashed()->get()->toJson())->toBe($beforeClients)
        ->and(User::withTrashed()->get()->toJson())->toBe($beforeUsers)
        ->and(ExternalRecord::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($beforeAudit + 1)
        ->and(AuditLog::query()->latest('id')->first()->event)->toBe('sync.dry_run')
        ->and(app(ApiTokens::class)->accessToken())->toBe('access');
    Http::assertSentCount(2);
    expect(Storage::disk('local')->allFiles('sync-uploads'))->toBeEmpty();
});

it('rejects non-file or incomplete export responses without marking existing accounts missing', function (string $body, string $contentType, int $status): void {
    $client = Client::factory()->synced()->create();
    fakeDirectoryExport($body, $contentType, $status);
    expect(fn () => app(SyncExternalRecords::class)->run(app(ReaderFactory::class)->forApi()))
        ->toThrow(SourceFormatException::class);
    expect($client->fresh()->isClosed())->toBeFalse()
        ->and($client->externalRecord->fresh()->missed_runs)->toBe(0)
        ->and(SyncRun::query()->sole()->status->value)->toBe('failed');
    expect(Storage::disk('local')->allFiles('sync-uploads'))->toBeEmpty();
    Http::assertSentCount(2);
})->with([
    ['{"error":"expired"}', 'application/json', 200],
    ['<html>Login</html>', 'text/html', 200],
    ['not an excel file', 'application/octet-stream', 200],
    ['', 'application/octet-stream', 200],
    ['partial', 'application/octet-stream', 206],
    ['denied', 'text/plain', 401],
]);

it('refuses invalid payloads before contacting the external system', function (string $payload): void {
    app(Settings::class)->set(SettingKey::SyncExportPayload, $payload);
    expect(fn () => app(SyncExternalRecords::class)->run(app(ReaderFactory::class)->forApi()))
        ->toThrow(SourceFormatException::class);
    Http::assertNothingSent();
})->with(['broken', '[]', 'null']);

it('does not close accounts when every row is rejected by domain filters', function (): void {
    $client = Client::factory()->synced()->create();
    fakeDirectoryExport(directoryWorkbook([[
        'Külső azonosító (ID)' => 9, 'Teljes név' => 'Kimaradó', 'E-mail cím' => 'outside@else.hu',
    ]]));
    expect(fn () => app(SyncExternalRecords::class)->run(app(ReaderFactory::class)->forApi()))
        ->toThrow(EmptySourceException::class);
    expect($client->externalRecord->fresh()->missed_runs)->toBe(0);
    Http::assertSentCount(2);
});

it('keeps the preview short while counting every accepted record', function (): void {
    $rows = [];
    for ($id = 1; $id <= 12; $id++) {
        $rows[] = ['Külső azonosító (ID)' => $id, 'Teljes név' => 'Ügyfél '.$id, 'E-mail cím' => 'client'.$id.'@client.hu'];
    }
    fakeDirectoryExport(directoryWorkbook($rows));

    $run = app(SyncExternalRecords::class)->run(app(ReaderFactory::class)->forApi(), dryRun: true);
    expect($run->stats['preview'])->toHaveCount(10)
        ->and($run->stats['incoming']['clients'])->toBe(12)
        ->and($run->stats['read'])->toBe(12)
        ->and(Client::query()->count())->toBe(0);
    Http::assertSentCount(2);
});

it('rejects a disguised JSON response in CSV mode and cleans up after a timeout', function (bool $timeout): void {
    config()->set('hdid.sync.export_format', 'csv');
    Http::fake([
        'https://directory.test/login' => Http::response(['access_token' => 'a', 'refresh_token' => 'r']),
        'https://directory.test/export' => $timeout ? Http::failedConnection() : Http::response('{"error":"not a file"}', 200, ['Content-Type' => 'application/octet-stream']),
    ]);

    expect(fn () => app(SyncExternalRecords::class)->run(app(ReaderFactory::class)->forApi()))
        ->toThrow(SourceFormatException::class);
    expect(Storage::disk('local')->allFiles('sync-uploads'))->toBeEmpty()
        ->and(Client::query()->count())->toBe(0)
        ->and(SyncRun::query()->sole()->status->value)->toBe('failed');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://directory.test/login');
})->with([true, false]);
