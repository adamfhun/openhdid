<?php

use App\Auth\Role;
use App\Filament\Admin\Pages\ManageSettings;
use App\Models\AuditLog;
use App\Models\SyncIdListItem;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\IdList;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
    config()->set([
        'hdid.sync.id_list_url' => 'https://directory.test/organizations',
        'hdid.sync.login_url' => 'https://directory.test/login',
        'hdid.sync.refresh_url' => 'https://directory.test/refresh',
        'hdid.sync.username' => 'u', 'hdid.sync.password' => 'p',
    ]);
    app(Settings::class)->set(SettingKey::SyncIdListPayload, '{"from":"{{ now }}"}');
});

function fetchIdListAction(): TestAction
{
    return TestAction::make('fetchIdList')->table();
}

function selectionAudits(): array
{
    return AuditLog::query()->where('event', 'sync.id_list.selection_changed')->orderBy('created_at')->get()
        ->map(fn (AuditLog $log): array => $log->context)->all();
}

it('lists the stored IDs alphabetically in a paged table and searches by name or ID', function (): void {
    // Sorting is the database's: MariaDB orders accented letters with their base letter;
    // the test database (SQLite) compares bytes, so the order check uses plain names.
    $zebra = SyncIdListItem::factory()->create(['external_id' => '12', 'name' => 'Zebra Kft.']);
    $ag = SyncIdListItem::factory()->create(['external_id' => '3', 'name' => 'Ág Bt.']);
    $alma = SyncIdListItem::factory()->create(['external_id' => '40', 'name' => 'Alma Zrt.']);
    $gone = SyncIdListItem::factory()->removed()->create(['external_id' => '9', 'name' => 'Megszűnt Kft.']);

    Livewire::test(ManageSettings::class)
        ->assertCanSeeTableRecords([$alma, $zebra], inOrder: true)
        ->assertCanSeeTableRecords([$ag])
        ->assertCanNotSeeTableRecords([$gone])
        ->searchTable('Zebra')->assertCanSeeTableRecords([$zebra])->assertCanNotSeeTableRecords([$ag])
        ->searchTable('40')->assertCanSeeTableRecords([$alma])->assertCanNotSeeTableRecords([$zebra, $ag])
        ->searchTable(null)->filterTable('listed', false)->assertCanSeeTableRecords([$gone])->assertCanNotSeeTableRecords([$ag, $zebra, $alma]);
});

it('selects a row at once, without a dialog, as one audited change', function (): void {
    $ag = SyncIdListItem::factory()->create(['external_id' => '3', 'name' => 'Ág Bt.']);

    Livewire::test(ManageSettings::class)
        ->callAction(TestAction::make('toggleSelection')->table($ag))
        ->assertHasNoActionErrors();

    expect($ag->fresh()->selected)->toBeTrue()
        ->and(selectionAudits())->toBe([['action' => 'select', 'count' => 1, 'ids' => ['3'], 'truncated' => false]])
        ->and(AuditLog::query()->where('event', 'sync.id_list.selection_changed')->sole()->actor_id)->toBe($this->admin->id)
        ->and(AuditLog::query()->where('event', 'sync_id_list_item.updated')->exists())->toBeFalse('one summary row, not one per item');
});

it('asks before deselecting a row, naming the ID and the consequence', function (): void {
    $alma = SyncIdListItem::factory()->selected()->create(['external_id' => '40', 'name' => 'Alma Zrt.']);
    SyncIdListItem::factory()->selected()->create(['external_id' => '12', 'name' => 'Zebra Kft.']);

    Livewire::test(ManageSettings::class)
        ->mountAction(TestAction::make('toggleSelection')->table($alma))
        ->assertActionMounted(TestAction::make('toggleSelection')->table($alma))
        ->assertMountedActionModalSee('Alma Zrt. (40)')
        ->assertMountedActionModalDontSee('Zebra Kft.')
        ->callMountedAction();

    expect(SyncIdListItem::query()->where('selected', true)->pluck('external_id')->all())->toBe(['12'])
        ->and(selectionAudits())->toBe([['action' => 'deselect', 'count' => 1, 'ids' => ['40'], 'truncated' => false]]);
});

it('selects and deselects the checked rows of the page in bulk, the deselection with a warning', function (): void {
    app(Settings::class)->set(SettingKey::SyncExportPayload, '{"ids":"{{ ids }}"}');
    $items = collect(['1' => 'Egy Kft.', '2' => 'Kettő Kft.', '3' => 'Három Kft.'])
        ->map(fn (string $name, string $id) => SyncIdListItem::factory()->create(['external_id' => $id, 'name' => $name]));

    Livewire::test(ManageSettings::class)
        ->selectTableRecords($items->only(['1', '2'])->values()->all())
        ->callAction(TestAction::make('selectIds')->table()->bulk())
        ->assertNotified(__(':n ID(s) selected', ['n' => 2]));
    expect(app(IdList::class)->selectedIds())->toBe(['1', '2']);

    Livewire::test(ManageSettings::class)
        ->selectTableRecords($items->only(['1', '2'])->values()->all())
        ->mountAction(TestAction::make('deselectIds')->table()->bulk())
        ->assertMountedActionModalSee(['Egy Kft. (1)', 'Kettő Kft. (2)', __('No ID stays selected: the sync does not run until one is selected again.')])
        ->callMountedAction();
    expect(app(IdList::class)->selectedIds())->toBe([])
        ->and(collect(selectionAudits())->pluck('action')->all())->toBe(['select', 'deselect']);
});

it('selects by pasted IDs, adding or replacing, and reports the IDs not on the list', function (): void {
    foreach (['3' => 'Ág Bt.', '12' => 'Zebra Kft.', '40' => 'Alma Zrt.'] as $id => $name) {
        SyncIdListItem::factory()->create(['external_id' => (string) $id, 'name' => $name, 'selected' => $id === 40]);
    }

    Livewire::test(ManageSettings::class)
        ->callAction(TestAction::make('selectByIds')->table(), ['ids' => "3, 12\n999", 'mode' => 'add'])
        ->assertNotified(__('Selection updated: :added selected, :removed deselected.', ['added' => 2, 'removed' => 0]));
    expect(app(IdList::class)->selectedIds())->toBe(['3', '12', '40']);

    Livewire::test(ManageSettings::class)
        ->callAction(TestAction::make('selectByIds')->table(), ['ids' => '12;40', 'mode' => 'only'])
        ->assertNotified(__('Selection updated: :added selected, :removed deselected.', ['added' => 0, 'removed' => 1]));
    expect(app(IdList::class)->selectedIds())->toBe(['12', '40']);

    // A typo must not empty the selection.
    Livewire::test(ManageSettings::class)
        ->callAction(TestAction::make('selectByIds')->table(), ['ids' => '777', 'mode' => 'only'])
        ->assertNotified(__('None of the IDs is on the list; nothing changed.'));
    expect(app(IdList::class)->selectedIds())->toBe(['12', '40']);
});

it('exports the filtered ID list as CSV', function (): void {
    SyncIdListItem::factory()->selected()->create(['external_id' => '3', 'name' => 'Ág Bt.']);
    SyncIdListItem::factory()->create(['external_id' => '12', 'name' => 'Zebra Kft.']);

    $response = Livewire::test(ManageSettings::class)
        ->filterTable('selected', true)
        ->callAction(TestAction::make('exportIdList')->table())
        ->assertFileDownloaded();
    $body = base64_decode((string) data_get($response->effects, 'download.content'));

    expect($body)->toContain('Ág Bt.')->not->toContain('Zebra Kft.')
        ->and(explode("\n", trim($body))[0])->toContain(__('ID'), __('Name'), __('Selected'));
});

it('fetches the ID list from the panel and audits the status code', function (): void {
    Http::fake([
        'https://directory.test/login' => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh']),
        'https://directory.test/organizations' => Http::sequence()
            ->push(['statusCode' => 200, 'result' => [['id' => 5, 'data' => ['id' => 5, 'name' => 'Öt Kft.']]]])
            ->push('Service unavailable', 503),
    ]);

    Livewire::test(ManageSettings::class)->callAction(fetchIdListAction())->assertNotified(__('ID list fetched'));
    expect(SyncIdListItem::query()->sole()->external_id)->toBe('5')
        ->and(AuditLog::query()->where('event', 'sync.id_list.fetched')->sole()->context)->toMatchArray(['status' => 200, 'http_status' => 200, 'added' => 1]);

    Livewire::test(ManageSettings::class)->callAction(fetchIdListAction())->assertNotified(__('The ID list could not be fetched'));
    expect(AuditLog::query()->where('event', 'sync.id_list.fetch_failed')->sole()->context)->toMatchArray(['status' => 503, 'http_status' => 503])
        ->and(SyncIdListItem::query()->sole()->removed_at)->toBeNull();
});

it('disables the fetch button with a reason while the ID list URL is missing', function (): void {
    config()->set('hdid.sync.id_list_url', null);

    Livewire::test(ManageSettings::class)
        ->assertActionDisabled(fetchIdListAction())
        ->assertSee('EMD_SYNC_ID_LIST_URL is not configured.');
});

it('saves an export payload using ids in any order and names what the sync still needs', function (?string $url, ?string $idListPayload, bool $listed, bool $selected, string $missing): void {
    config()->set('hdid.sync.id_list_url', $url);
    app(Settings::class)->set(SettingKey::SyncIdListPayload, $idListPayload);
    if ($listed) {
        SyncIdListItem::factory()->create(['external_id' => '12', 'selected' => $selected]);
    }
    $exportField = ManageSettings::fieldName(SettingKey::SyncExportPayload);

    Livewire::test(ManageSettings::class)
        ->fillForm([$exportField => '{"ids":"{{ ids }}"}'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('The export uses {{ ids }}, but the sync does not run yet'))
        ->assertSee(__($missing));

    expect(app(Settings::class)->string(SettingKey::SyncExportPayload))->toBe('{"ids":"{{ ids }}"}');
})->with([
    'no ID list URL' => [null, '{"a":1}', true, true, 'the ID list address (EMD_SYNC_ID_LIST_URL in .env)'],
    'no ID list payload' => ['https://directory.test/organizations', null, true, true, 'the ID list request payload (above)'],
    'not fetched yet' => ['https://directory.test/organizations', '{"a":1}', false, false, 'fetching the ID list (button below)'],
    'nothing selected' => ['https://directory.test/organizations', '{"a":1}', true, false, 'at least one selected ID (table below)'],
]);

it('says nothing about readiness once the ID list is usable', function (): void {
    SyncIdListItem::factory()->selected()->create(['external_id' => '12']);

    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::SyncExportPayload) => '{"ids":"{{ ids }}"}'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotNotified(__('The export uses {{ ids }}, but the sync does not run yet'));
});

it('accepts only now in the ID list payload and well-formed response paths', function (string $field, string $value): void {
    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::from($field)) => $value])
        ->call('save')
        ->assertHasFormErrors([ManageSettings::fieldName(SettingKey::from($field))]);
})->with([
    'ids in the ID list payload' => ['sync.id_list_payload', '{"ids":"{{ ids }}"}'],
    'ID list payload not an object' => ['sync.id_list_payload', '[1,2]'],
    'empty ID path' => ['sync.id_list_id_path', ''],
    'ID path with spaces' => ['sync.id_list_id_path', 'result data id'],
    'status path with a wildcard' => ['sync.id_list_status_path', 'result.*.code'],
]);
