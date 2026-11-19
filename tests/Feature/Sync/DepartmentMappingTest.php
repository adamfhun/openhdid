<?php

use App\Auth\Role;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\ExternalRecords\ExternalRecordResource;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\ExternalRecordDto;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The organisational unit (and the job title) come from the column the
 * column mapping names (owner's decision of 2026-11-18). The heading is
 * matched without regard to case and outer spaces, and a record taken over
 * before the mapping was set shows the value from its further columns.
 */
it('fills the organisational unit from the mapped column, whatever the case of the heading', function (array $row, array $mapping): void {
    $dto = ExternalRecordDto::fromRow(['external_user_id' => '7', 'email' => 'a@client.hu', 'name' => 'A'] + $row, ['external_id' => 'external_user_id'] + $mapping);

    expect($dto?->department)->toBe('Értékesítési osztály');
})->with([
    'the field name itself' => [['department' => 'Értékesítési osztály'], []],
    'a mapped heading' => [['Szervezeti egység' => 'Értékesítési osztály'], ['department' => 'Szervezeti egység']],
    'another letter case and a space' => [['SZERVEZETI EGYSÉG ' => 'Értékesítési osztály'], ['department' => 'szervezeti egység']],
    'an empty mapping falls back to the field name' => [['Department' => 'Értékesítési osztály'], ['department' => '']],
]);

it('shows the organisational unit of a record taken over before the mapping named its column, on the client page and on the EMD record', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $record = ExternalRecord::factory()->client()->create(['department' => null, 'title' => null, 'attributes' => ['Szervezeti egység' => 'Értékesítési osztály', 'Beosztás' => 'vezető']]);

    expect($record->departmentName())->toBeNull();

    app(Settings::class)->set(SettingKey::SyncColumnMapping, ['department' => 'szervezeti egység', 'title' => 'Beosztás'] + SettingKey::SyncColumnMapping->default());

    expect($record->fresh()->departmentName())->toBe('Értékesítési osztály')
        ->and($record->fresh()->jobTitle())->toBe('vezető');

    $client = Client::factory()->create(['external_record_id' => $record->id, 'email' => $record->email]);
    $this->get(ClientResource::getUrl('view', ['record' => $client]))->assertOk()->assertSee('Értékesítési osztály');
    $this->get(ExternalRecordResource::getUrl().'?tableSearch='.$record->external_id)->assertOk()->assertSee('Értékesítési osztály');
});
