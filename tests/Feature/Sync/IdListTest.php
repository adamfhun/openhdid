<?php

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\SyncIdListItem;
use App\Models\SyncRun;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\IdList;
use App\Sync\IdListException;
use App\Sync\ReaderFactory;
use App\Sync\SourceFormatException;
use App\Sync\SyncExternalRecords;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\SimpleExcel\SimpleExcelWriter;

beforeEach(function (): void {
    Storage::fake('local');
    Http::preventStrayRequests();
    config()->set([
        'hdid.sync.driver' => 'spreadsheet', 'hdid.sync.export_format' => 'xlsx',
        'hdid.sync.api_url' => 'https://directory.test/export',
        'hdid.sync.id_list_url' => 'https://directory.test/organizations',
        'hdid.sync.login_url' => 'https://directory.test/login',
        'hdid.sync.refresh_url' => 'https://directory.test/refresh',
        'hdid.sync.username' => 'u', 'hdid.sync.password' => 'p',
    ]);
    $settings = app(Settings::class);
    $settings->set(SettingKey::SyncClientDomains, ['client.hu']);
    $settings->set(SettingKey::SyncExportPayload, '{"query":{"ids":"{{ ids }}"}}');
    $settings->set(SettingKey::SyncIdListPayload, '{"from":"{{ now }}"}');
    $settings->set(SettingKey::SyncColumnMapping, ['external_id' => 'ID', 'name' => 'Name', 'email' => 'Email']);
});

/**
 * @param  list<array{0: int|string, 1: string}>  $items  ID and name
 */
function organizations(array $items): array
{
    return ['statusCode' => 200, 'result' => array_map(
        fn (array $item): array => ['id' => $item[0], 'data' => ['id' => $item[0], 'name' => $item[1], 'type' => 'org']],
        $items,
    )];
}

function exportWorkbook(): string
{
    $path = Storage::disk('local')->path('ids-fixture.xlsx');
    $writer = SimpleExcelWriter::create($path);
    $writer->addRows([['ID' => 1, 'Name' => 'Ügyfél', 'Email' => 'client@client.hu']]);
    $writer->close();
    $body = file_get_contents($path);
    unlink($path);

    return $body;
}

function fakeEmd(mixed $idList, int $idListStatus = 200): void
{
    Http::fake([
        'https://directory.test/login' => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh']),
        'https://directory.test/refresh' => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh']),
        'https://directory.test/organizations' => $idList instanceof ResponseSequence ? $idList : Http::response($idList, $idListStatus),
        'https://directory.test/export' => Http::response(exportWorkbook(), 200, ['Content-Type' => 'application/octet-stream']),
    ]);
}

it('stores the ID list unique by ID and offers it in Hungarian alphabetical order, nothing selected', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Europe/Budapest'));
    fakeEmd(organizations([[12, 'Zebra Kft.'], [3, 'Ág Bt.'], [40, 'Alma Zrt.'], [12, 'Zebra másodszor']]));

    $result = app(IdList::class)->refresh();

    expect($result)->toMatchArray(['status' => 200, 'http_status' => 200, 'received' => 3, 'added' => 3])
        ->and(array_keys(app(IdList::class)->options()))->toBe([3, 40, 12])
        ->and(app(IdList::class)->options()[12])->toBe('Zebra Kft. (12)')
        ->and(SyncIdListItem::query()->where('selected', true)->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'sync_id_list_item.created')->count())->toBe(3);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://directory.test/organizations'
        && $request->hasHeader('Authorization', 'Bearer access')
        && $request->body() === '{"from":"2026-10-02T10:00:00.000Z"}');
});

it('reads the documented EMD response with the default settings, the HTTP status counting', function (): void {
    fakeEmd(['result' => [
        ['id' => 31, 'data' => ['id' => 31, 'name' => 'Harmincegy Kft.']],
        ['id' => 4, 'data' => ['id' => 4, 'name' => 'Négy Bt.']],
    ]]);

    $result = app(IdList::class)->refresh();

    expect($result)->toMatchArray(['status' => 200, 'http_status' => 200, 'received' => 2])
        ->and(app(IdList::class)->options())->toBe([31 => 'Harmincegy Kft. (31)', 4 => 'Négy Bt. (4)']);
});

it('reads the IDs, names and status code from the configured paths', function (): void {
    $settings = app(Settings::class);
    $settings->set(SettingKey::SyncIdListIdPath, 'payload.items.*.org.code');
    $settings->set(SettingKey::SyncIdListNamePath, 'payload.items.*.org.title');
    $settings->set(SettingKey::SyncIdListStatusPath, 'meta.code');
    fakeEmd(['meta' => ['code' => '201'], 'payload' => ['items' => [
        ['org' => ['code' => 'A-7', 'title' => 'Hét']],
        ['org' => ['code' => 'B-2']],
    ]]]);

    $result = app(IdList::class)->refresh();

    expect($result['status'])->toBe(201)
        ->and(SyncIdListItem::query()->orderBy('external_id')->pluck('name', 'external_id')->all())->toBe(['A-7' => 'Hét', 'B-2' => 'B-2']);
});

it('refuses a failed or malformed ID list without touching the stored list', function (mixed $body, int $http, ?string $statusPath, ?int $status): void {
    $kept = SyncIdListItem::factory()->selected()->create(['external_id' => '5']);
    app(Settings::class)->set(SettingKey::SyncIdListStatusPath, $statusPath);
    fakeEmd($body, $http);

    try {
        app(IdList::class)->refresh();
        $this->fail('The ID list was accepted.');
    } catch (IdListException $exception) {
        expect($exception->status)->toBe($status)->and($exception->httpStatus)->toBe($http);
    }

    expect($kept->fresh()->removed_at)->toBeNull()->and(SyncIdListItem::query()->count())->toBe(1)
        ->and(app(IdList::class)->status()['status'])->toBe($status);
})->with([
    'HTTP error, no status path' => [organizations([[1, 'Egy']]), 503, null, 503],
    'body status error' => [['statusCode' => 500, 'result' => []], 200, 'statusCode', 500],
    'status missing at the path' => [organizations([[1, 'Egy']]), 200, 'meta.code', null],
    'not JSON' => ['<html>login</html>', 200, null, 200],
    'empty list' => [['statusCode' => 200, 'result' => []], 200, null, 200],
    'ID with a comma' => [organizations([['1,2', 'Kettő']]), 200, null, 200],
    'ID that is an object' => [['result' => [['data' => ['id' => ['x' => 1], 'name' => 'Rossz']]]], 200, null, 200],
]);

it('sends the selected listed IDs in the export and reports both status codes on the run', function (): void {
    SyncIdListItem::factory()->selected()->create(['external_id' => '12', 'name' => 'Zebra Kft.']);
    SyncIdListItem::factory()->selected()->create(['external_id' => '3', 'name' => 'Ág Bt.']);
    SyncIdListItem::factory()->create(['external_id' => '40', 'name' => 'Alma Zrt.']);
    $gone = SyncIdListItem::factory()->selected()->create(['external_id' => '7', 'name' => 'Megszűnt Kft.']);
    fakeEmd(organizations([[12, 'Zebra Kft.'], [3, 'Ág Bt.'], [40, 'Alma Zrt.'], [99, 'Új Kft.']]));

    $this->artisan('hdid:emd-sync')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://directory.test/export'
        && $request->body() === '{"query":{"ids":"3,12"}}');
    expect(SyncRun::query()->sole()->stats['requests'])->toMatchArray([
        'id_list' => ['status' => 200, 'http_status' => 200, 'received' => 4, 'added' => 1, 'renamed' => 0, 'removed' => 1, 'returned' => 0, 'sent' => 2, 'ids' => '3,12'],
        'export' => ['status' => 200],
    ])
        ->and($gone->fresh()->removed_at)->not->toBeNull()
        ->and($gone->fresh()->selected)->toBeTrue()
        ->and(SyncIdListItem::query()->where('external_id', '99')->sole()->selected)->toBeFalse();
});

it('stops before the export when no selected ID is on the list, without closing accounts', function (): void {
    $client = Client::factory()->synced()->create();
    SyncIdListItem::factory()->selected()->create(['external_id' => '7']);
    fakeEmd(organizations([[12, 'Zebra Kft.']]));

    expect(fn () => app(SyncExternalRecords::class)->run(app(ReaderFactory::class)->forApi()))->toThrow(SourceFormatException::class);

    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://directory.test/export');
    $run = SyncRun::query()->sole();
    expect($run->status->value)->toBe('failed')
        ->and($run->stats['requests']['id_list'])->toMatchArray(['status' => 200, 'sent' => 0, 'ids' => ''])
        ->and($client->fresh()->isClosed())->toBeFalse()
        ->and($client->externalRecord->fresh()->missed_runs)->toBe(0);
});

it('keeps the ID list status code on a failed run', function (): void {
    SyncIdListItem::factory()->selected()->create(['external_id' => '12']);
    app(Settings::class)->set(SettingKey::SyncIdListStatusPath, 'statusCode');
    fakeEmd(['statusCode' => 401, 'result' => []]);

    expect(fn () => app(SyncExternalRecords::class)->run(app(ReaderFactory::class)->forApi()))->toThrow(SourceFormatException::class);

    $run = SyncRun::query()->sole();
    expect($run->stats['requests']['id_list'])->toBe(['status' => 401, 'http_status' => 200])
        ->and($run->error)->toContain('401');
    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://directory.test/export');
});

it('does not query the ID list while the export payload has no ids placeholder', function (): void {
    app(Settings::class)->set(SettingKey::SyncExportPayload, '{"query":{"ids":"1,2"}}');
    fakeEmd(organizations([[1, 'Egy']]));

    $this->artisan('hdid:emd-sync')->assertSuccessful();

    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://directory.test/organizations');
    expect(SyncRun::query()->sole()->stats['requests'])->toBe(['export' => ['status' => 200]]);
});

it('marks IDs leaving the list and restores them with their selection, audited', function (): void {
    fakeEmd(Http::sequence()
        ->push(organizations([[1, 'Egy'], [2, 'Kettő']]))
        ->push(organizations([[2, 'Kettő']]))
        ->push(organizations([[1, 'Egy új néven'], [2, 'Kettő']])));
    app(IdList::class)->refresh();
    app(IdList::class)->setSelected(['1'], true);
    $item = SyncIdListItem::query()->where('external_id', '1')->sole();

    expect(app(IdList::class)->refresh())->toMatchArray(['removed' => 1])
        ->and(app(IdList::class)->selectedIds())->toBe([])
        ->and(app(IdList::class)->selectedButRemoved())->toBe(['Egy (1)']);

    expect(app(IdList::class)->refresh())->toMatchArray(['returned' => 1, 'renamed' => 1])
        ->and($item->fresh()->removed_at)->toBeNull()
        ->and($item->fresh()->name)->toBe('Egy új néven')
        ->and(app(IdList::class)->selectedIds())->toBe(['1']);

    $changes = AuditLog::query()->where('event', 'sync_id_list_item.updated')->where('subject_id', $item->id)->orderBy('id')->get();
    expect($changes->map(fn (AuditLog $log): array => array_keys($log->context['to']))->all())
        ->toBe([['removed_at'], ['name', 'removed_at']])
        ->and(AuditLog::query()->where('event', 'sync.id_list.selection_changed')->sole()->context)
        ->toBe(['action' => 'select', 'count' => 1, 'ids' => ['1'], 'truncated' => false]);
});

it('keeps the reason of a connection failure in the ID list status and the audit log', function (): void {
    Http::fake([
        'https://directory.test/login' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host: directory.test'),
    ]);

    expect(fn () => app(IdList::class)->fetchNow())->toThrow(IdListException::class, 'directory.test:443');
    expect(app(IdList::class)->status()['error'])->toContain('DNS')
        ->and(AuditLog::query()->where('event', 'sync.id_list.fetch_failed')->sole()->context['error'])->toContain('directory.test:443');
});

it('names an unverifiable certificate of the ID list endpoint with the ca folder hint', function (): void {
    Http::fake([
        'https://directory.test/login' => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh']),
        'https://directory.test/organizations' => fn () => throw new ConnectionException('cURL error 60: SSL certificate problem: unable to get local issuer certificate'),
    ]);

    expect(fn () => app(IdList::class)->refresh())->toThrow(IdListException::class, 'ca folder');
});
