<?php

use App\Auth\Role;
use App\Filament\Admin\Pages\ManageSettings;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');
});

it('saves separate pin limits and ivr and dictated code lengths', function (): void {
    Livewire::test(ManageSettings::class)
        ->fillForm([
            ManageSettings::fieldName(SettingKey::PinMinLength) => 9,
            ManageSettings::fieldName(SettingKey::PinMaxLength) => 12,
            ManageSettings::fieldName(SettingKey::IvrCodeLength) => 10,
            ManageSettings::fieldName(SettingKey::PinClientChangesEnabled) => true,
            ManageSettings::fieldName(SettingKey::PinUniqueRequired) => false,
            ManageSettings::fieldName(SettingKey::MobileOtpLength) => 8,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(Settings::class);
    expect($settings->int(SettingKey::PinMinLength))->toBe(9)
        ->and($settings->int(SettingKey::PinMaxLength))->toBe(12)
        ->and($settings->int(SettingKey::IvrCodeLength))->toBe(10)
        ->and($settings->bool(SettingKey::PinClientChangesEnabled))->toBeTrue()
        ->and($settings->bool(SettingKey::PinUniqueRequired))->toBeFalse()
        ->and($settings->int(SettingKey::MobileOtpLength))->toBe(8);
    $this->assertDatabaseHas('audit_log', ['event' => 'setting.changed', 'context->key' => SettingKey::IvrCodeLength->value]);
});

it('rejects invalid pin limits and ivr lengths without saving other settings', function (SettingKey $key, int $value): void {
    Livewire::test(ManageSettings::class)
        ->fillForm([
            ManageSettings::fieldName($key) => $value,
            ManageSettings::fieldName(SettingKey::MobileOtpLength) => 10,
        ])
        ->call('save')
        ->assertHasFormErrors([ManageSettings::fieldName($key)]);

    expect(app(Settings::class)->int($key))->toBe($key->default())
        ->and(app(Settings::class)->int(SettingKey::MobileOtpLength))->toBe(8);
})->with([
    'pin minimum below floor' => [SettingKey::PinMinLength, 5],
    'pin minimum above ceiling' => [SettingKey::PinMinLength, 13],
    'pin maximum below minimum' => [SettingKey::PinMaxLength, 5],
    'pin maximum above ceiling' => [SettingKey::PinMaxLength, 13],
    'ivr too short' => [SettingKey::IvrCodeLength, 7],
    'ivr too long' => [SettingKey::IvrCodeLength, 13],
]);

it('rejects a pin minimum above its maximum and accepts equal limits', function (): void {
    $page = Livewire::test(ManageSettings::class)
        ->fillForm([
            ManageSettings::fieldName(SettingKey::PinMinLength) => 10,
            ManageSettings::fieldName(SettingKey::PinMaxLength) => 8,
        ])->call('save')->assertHasFormErrors([ManageSettings::fieldName(SettingKey::PinMaxLength)]);

    expect(app(Settings::class)->int(SettingKey::PinMinLength))->toBe(6);

    $page->fillForm([ManageSettings::fieldName(SettingKey::PinMaxLength) => 10])
        ->call('save')->assertHasNoFormErrors();

    expect(app(Settings::class)->int(SettingKey::PinMinLength))->toBe(10)
        ->and(app(Settings::class)->int(SettingKey::PinMaxLength))->toBe(10);
});

it('refuses to switch on an SSO provider that is not configured on the server', function (): void {
    config()->set('hdid.oidc.user.entra', ['tenant' => null, 'client_id' => null, 'client_secret' => null]);

    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::UserSsoEntraEnabled) => true])
        ->call('save')
        ->assertHasFormErrors([ManageSettings::fieldName(SettingKey::UserSsoEntraEnabled)]);

    expect(app(Settings::class)->bool(SettingKey::UserSsoEntraEnabled))->toBeFalse();

    config()->set('hdid.oidc.user.entra', ['tenant' => '11111111-2222-3333-4444-555555555555', 'client_id' => 'app-id', 'client_secret' => 'secret']);

    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::UserSsoEntraEnabled) => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(Settings::class)->bool(SettingKey::UserSsoEntraEnabled))->toBeTrue();
});

it('keeps admin password login on while no admin SSO provider is configured', function (): void {
    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::UserLoginPasswordEnabled) => false])
        ->call('save')
        ->assertHasFormErrors([ManageSettings::fieldName(SettingKey::UserLoginPasswordEnabled)]);

    expect(app(Settings::class)->bool(SettingKey::UserLoginPasswordEnabled))->toBeTrue();

    config()->set('hdid.oidc.user.adfs', ['issuer' => 'https://adfs.example.test/adfs', 'client_id' => 'app-id', 'client_secret' => 'secret']);

    Livewire::test(ManageSettings::class)
        ->fillForm([
            ManageSettings::fieldName(SettingKey::UserSsoAdfsEnabled) => true,
            ManageSettings::fieldName(SettingKey::UserLoginPasswordEnabled) => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(Settings::class)->bool(SettingKey::UserLoginPasswordEnabled))->toBeFalse();
});

it('keeps the sync column mapping to the importer fields only', function (): void {
    app(Settings::class)->set(SettingKey::SyncColumnMapping, ['email' => 'E-mail', 'stray' => 'whatever', 'phones' => ['tel', 'mobil']]);

    $page = Livewire::test(ManageSettings::class);
    $form = $page->instance()->form->getState()[ManageSettings::fieldName(SettingKey::SyncColumnMapping)];

    expect(array_keys($form))->toBe(array_keys(SettingKey::SyncColumnMapping->default()))
        ->and($form['email'])->toBe('E-mail')
        ->and($form['phones'])->toBe('tel,mobil')
        ->and($form['name'])->toBe('');

    $page->fillForm([ManageSettings::fieldName(SettingKey::SyncColumnMapping) => [
        'external_id' => ' Azonosító ',
        'name' => 'Név',
        'email' => '',
        'company' => 'Cég',
        'phones' => 'tel, mobil',
        'implicit_package' => 'csomag',
        'explicit_package' => '',
        'stray' => 'ignored',
    ]])->call('save')->assertHasNoFormErrors();

    expect(app(Settings::class)->array(SettingKey::SyncColumnMapping))->toBe([
        'external_id' => 'Azonosító',
        'name' => 'Név',
        'company' => 'Cég',
        'phones' => ['tel', 'mobil'],
        'implicit_package' => 'csomag',
    ]);
});

it('stores the directory fields as column => label pairs from the settings page', function (): void {
    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::ClientsDirectoryFields) => ['department' => 'Osztály', 'title' => 'Beosztás']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(Settings::class)->array(SettingKey::ClientsDirectoryFields))->toBe(['department' => 'Osztály', 'title' => 'Beosztás']);
});

it('lets only a SuperAdmin edit the complete export payload and validates its JSON object', function (): void {
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create());
    $field = ManageSettings::fieldName(SettingKey::SyncExportPayload);
    $payload = '{"filter":{"ids":"12,34","empty":{}},"label":"Árvíztűrő"}';

    Livewire::test(ManageSettings::class)->fillForm([$field => $payload])->call('save')->assertHasNoFormErrors();
    expect(app(Settings::class)->string(SettingKey::SyncExportPayload))->toBe($payload);

    foreach (['not-json', '[]', 'null'] as $invalid) {
        Livewire::test(ManageSettings::class)->fillForm([$field => $invalid])->call('save')->assertHasFormErrors([$field]);
        expect(app(Settings::class)->string(SettingKey::SyncExportPayload))->toBe($payload);
    }
});

it('preserves the export payload when an Admin saves other settings or tampers with its state', function (): void {
    $admin = auth()->user();
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create());
    app(Settings::class)->set(SettingKey::SyncExportPayload, '{"ids":"original"}');
    $this->actingAs($admin);
    $field = ManageSettings::fieldName(SettingKey::SyncExportPayload);

    Livewire::test(ManageSettings::class)
        ->set('data.'.$field, '{"ids":"tampered"}')
        ->fillForm([ManageSettings::fieldName(SettingKey::SyncExpectedIntervalHours) => 3])
        ->call('save')->assertHasNoFormErrors();

    expect(app(Settings::class)->string(SettingKey::SyncExportPayload))->toBe('{"ids":"original"}')
        ->and(app(Settings::class)->int(SettingKey::SyncExpectedIntervalHours))->toBe(3);
    expect(fn () => app(Settings::class)->set(SettingKey::SyncExportPayload, '{"ids":"bypass"}'))
        ->toThrow(HttpException::class);
});

it('lowercases entered domains and rejects overlap without saving either list', function (): void {
    $users = ManageSettings::fieldName(SettingKey::SyncUserDomains);
    $clients = ManageSettings::fieldName(SettingKey::SyncClientDomains);
    $page = Livewire::test(ManageSettings::class)
        ->set('data.'.$users, [' @STAFF.HU ', 'staff.hu'])
        ->assertSet('data.'.$users, ['staff.hu'])
        ->fillForm([$clients => ['STAFF.hu']])
        ->call('save')->assertHasFormErrors([$users, $clients]);
    expect(app(Settings::class)->array(SettingKey::SyncUserDomains))->toBe([])
        ->and(app(Settings::class)->array(SettingKey::SyncClientDomains))->toBe([]);

    $page->fillForm([$clients => [' CLIENT.HU ']])->call('save')->assertHasNoFormErrors();
    expect(app(Settings::class)->array(SettingKey::SyncUserDomains))->toBe(['staff.hu'])
        ->and(app(Settings::class)->array(SettingKey::SyncClientDomains))->toBe(['client.hu']);
});

it('allows shared domains only with the switch off and allows moving a domain between lists', function (): void {
    $users = ManageSettings::fieldName(SettingKey::SyncUserDomains);
    $clients = ManageSettings::fieldName(SettingKey::SyncClientDomains);
    $unique = ManageSettings::fieldName(SettingKey::SyncUniqueDomains);
    Livewire::test(ManageSettings::class)->fillForm([
        $users => ['Shared.HU'], $clients => ['SHARED.hu'], $unique => false,
    ])->call('save')->assertHasNoFormErrors();
    expect(app(Settings::class)->array(SettingKey::SyncClientDomains))->toBe(['shared.hu']);
    Livewire::test(ManageSettings::class)->fillForm([$unique => true])
        ->call('save')->assertHasFormErrors([$users, $clients]);
    expect(app(Settings::class)->bool(SettingKey::SyncUniqueDomains))->toBeFalse();
    Livewire::test(ManageSettings::class)->fillForm([$clients => [], $unique => true])
        ->call('save')->assertHasNoFormErrors();
    Livewire::test(ManageSettings::class)->fillForm([$users => [], $clients => ['SHARED.HU']])
        ->call('save')->assertHasNoFormErrors();
    expect(app(Settings::class)->array(SettingKey::SyncUserDomains))->toBe([])
        ->and(app(Settings::class)->array(SettingKey::SyncClientDomains))->toBe(['shared.hu']);
});

it('enforces normalized domain uniqueness through the settings service too', function (): void {
    $settings = app(Settings::class);
    $settings->set(SettingKey::SyncUserDomains, ['@STAFF.HU', 'staff.hu']);
    expect($settings->array(SettingKey::SyncUserDomains))->toBe(['staff.hu']);
    expect(fn () => $settings->set(SettingKey::SyncClientDomains, ['STAFF.hu']))
        ->toThrow(ValidationException::class);
    expect($settings->array(SettingKey::SyncClientDomains))->toBe([]);
});

it('removes the replaced branding image from the public disk', function (): void {
    Storage::fake('public');
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Storage::disk('public')->put('branding/old-logo.png', 'x');
    app(Settings::class)->set(SettingKey::BrandingLogo, 'branding/old-logo.png');

    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::BrandingLogo) => ['branding/new-logo.png']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(Settings::class)->string(SettingKey::BrandingLogo))->toBe('branding/new-logo.png')
        ->and(Storage::disk('public')->exists('branding/old-logo.png'))->toBeFalse();
});

it('never seeds the shared settings cache from an uncommitted transaction', function (): void {
    $settings = app(Settings::class);
    $settings->set(SettingKey::CallsDashboardPollSeconds, 5);
    $settings->forget();

    try {
        DB::transaction(function () use ($settings): void {
            $settings->setMany([SettingKey::CallsDashboardPollSeconds->value => 9]);

            // The pending value must not reach the cache every other process
            // reads; only the committed state may.
            expect(Cache::get('hdid.settings'))->toBeNull();

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    app(Settings::class)->forget();
    expect(app(Settings::class)->int(SettingKey::CallsDashboardPollSeconds))->toBe(5, 'the rolled back value is gone');
});
