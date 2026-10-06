<?php

use App\Auth\Role;
use App\Filament\Admin\Pages\ManageSettings;
use App\Models\AuditLog;
use App\Models\SyncIdListItem;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
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
    return TestAction::make('fetchIdList')->schemaComponent('idList', 'form');
}

it('shows the stored IDs alphabetically and saves the selection with an audit trail', function (): void {
    $zebra = SyncIdListItem::factory()->create(['external_id' => '12', 'name' => 'Zebra Kft.']);
    $ag = SyncIdListItem::factory()->create(['external_id' => '3', 'name' => 'Ág Bt.']);
    SyncIdListItem::factory()->removed()->create(['external_id' => '9', 'name' => 'Megszűnt Kft.']);

    Livewire::test(ManageSettings::class)
        ->assertSeeInOrder(['Ág Bt. (3)', 'Zebra Kft. (12)'])
        ->assertDontSee('Megszűnt Kft.')
        ->fillForm([ManageSettings::ID_LIST_FIELD => ['3']])
        ->call('save')->assertHasNoFormErrors();

    expect($ag->fresh()->selected)->toBeTrue()->and($zebra->fresh()->selected)->toBeFalse();
    $audit = AuditLog::query()->where('event', 'sync_id_list_item.updated')->sole();
    expect($audit->subject_id)->toBe($ag->id)
        ->and($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->context)->toBe(['from' => ['selected' => false], 'to' => ['selected' => true]]);
});

it('asks for confirmation before saving a selection that drops IDs', function (): void {
    SyncIdListItem::factory()->selected()->create(['external_id' => '40', 'name' => 'Alma Zrt.']);
    SyncIdListItem::factory()->selected()->create(['external_id' => '12', 'name' => 'Zebra Kft.']);

    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::ID_LIST_FIELD => ['12']])
        ->mountAction('save')
        ->assertActionMounted('save')
        ->assertMountedActionModalSee('Alma Zrt. (40)')
        ->assertMountedActionModalDontSee('Zebra Kft.')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(SyncIdListItem::query()->where('selected', true)->pluck('external_id')->all())->toBe(['12']);
});

it('saves without confirmation when no selected ID is dropped', function (): void {
    SyncIdListItem::factory()->create(['external_id' => '40', 'name' => 'Alma Zrt.']);

    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::ID_LIST_FIELD => ['40']])
        ->callAction('save')
        ->assertHasNoFormErrors();

    expect(SyncIdListItem::query()->sole()->selected)->toBeTrue();
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

it('refuses an export payload using ids until the ID list is usable', function (?string $url, ?string $idListPayload, bool $withSelection, string $field): void {
    config()->set('hdid.sync.id_list_url', $url);
    app(Settings::class)->set(SettingKey::SyncIdListPayload, $idListPayload);
    SyncIdListItem::factory()->create(['external_id' => '12']);
    $exportField = ManageSettings::fieldName(SettingKey::SyncExportPayload);

    Livewire::test(ManageSettings::class)
        ->fillForm([$exportField => '{"ids":"{{ ids }}"}', ManageSettings::ID_LIST_FIELD => $withSelection ? ['12'] : []])
        ->call('save')
        ->assertHasFormErrors([$field === 'export' ? $exportField : ManageSettings::ID_LIST_FIELD]);

    expect(app(Settings::class)->string(SettingKey::SyncExportPayload))->toBeNull();
})->with([
    'no ID list URL' => [null, '{"a":1}', true, 'export'],
    'no ID list payload' => ['https://directory.test/organizations', null, true, 'export'],
    'nothing selected' => ['https://directory.test/organizations', '{"a":1}', false, 'selection'],
]);

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
