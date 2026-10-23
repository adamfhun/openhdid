<?php

use App\Auth\Role;
use App\Filament\Admin\Pages\ManageSettings;
use App\Models\User;
use App\Settings\JsonOverview;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Settings\SettingSection;
use App\Settings\SettingTab;
use App\Settings\SettingType;
use App\Sync\RequestPayload;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The settings page regrouped into seven tabs of blocks (2026-10-23): no
 * setting may get lost on the way, a setting under a switch keeps its stored
 * value while hidden, the unsaved-changes guard still marks the tab, and the
 * readable view of a JSON body only shows.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create());
    Filament::setCurrentPanel('admin');
});

it('puts every setting in exactly one block, and every dependent one in the block of its switch', function (): void {
    $placed = collect(SettingSection::cases())->flatMap(fn (SettingSection $section): array => $section->keys());

    expect($placed->map(fn (SettingKey $key): string => $key->value)->duplicates()->all())->toBe([])
        ->and($placed->count())->toBe(count(SettingKey::cases()))
        ->and($placed->map(fn (SettingKey $key): string => $key->value)->sort()->values()->all())
        ->toBe(collect(SettingKey::cases())->map(fn (SettingKey $key): string => $key->value)->sort()->values()->all());

    foreach (SettingKey::cases() as $key) {
        if (($switch = $key->dependsOn()) !== null) {
            expect($switch->type())->toBe(SettingType::Boolean, $key->value.' depends on a switch')
                ->and($switch->section())->toBe($key->section(), $key->value.' sits in the block of its switch')
                ->and($switch->dependsOn())->toBeNull($key->value.' depends on one level only');
        }
    }

    expect(collect(SettingTab::cases())->every(fn (SettingTab $tab): bool => $tab->sections() !== []))->toBeTrue();
});

it('has every setting on the form exactly once, hidden ones included', function (): void {
    $page = Livewire::test(ManageSettings::class)->instance();
    $fields = collect($page->form->getFlatFields(withHidden: true))
        ->map(fn (Field $field): string => Str::after($field->getStatePath(), 'data.'))
        ->filter(fn (string $path): bool => ! str_contains($path, '.'))
        ->values();

    expect($fields->duplicates()->all())->toBe([])
        ->and($fields->sort()->values()->all())->toBe(collect(SettingKey::cases())->map(fn (SettingKey $key): string => ManageSettings::fieldName($key))->sort()->values()->all());
});

it('renders every setting once when every switch is on, and the settings under a switch only while it is on', function (): void {
    $settings = app(Settings::class);
    $switches = collect(SettingKey::cases())->filter(fn (SettingKey $key): bool => $key->dependents() !== []);

    $html = Livewire::test(ManageSettings::class)->html();
    foreach ($switches as $switch) {
        foreach ($switch->dependents() as $dependent) {
            expect(substr_count($html, 'data-setting="'.$dependent->value.'"'))->toBe((bool) $switch->default() ? 1 : 0, $dependent->value);
        }
    }

    $settings->setMany($switches->mapWithKeys(fn (SettingKey $key): array => [$key->value => true])->all());

    $html = Livewire::test(ManageSettings::class)->html();
    foreach (SettingKey::cases() as $key) {
        expect(substr_count($html, 'data-setting="'.$key->value.'"'))->toBe(1, $key->value);
    }
});

it('keeps the stored value of a setting hidden under a switch that is off, and saves it once the switch is on', function (): void {
    $settings = app(Settings::class);
    $settings->set(SettingKey::ClientLoginOtpSmsTtlMinutes, 7);
    $ttl = ManageSettings::fieldName(SettingKey::ClientLoginOtpSmsTtlMinutes);

    Livewire::test(ManageSettings::class)
        ->set('data.'.$ttl, 9)
        ->fillForm([ManageSettings::fieldName(SettingKey::PortalLegalName) => 'Példa Kft.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($settings->int(SettingKey::ClientLoginOtpSmsTtlMinutes))->toBe(7)
        ->and($settings->string(SettingKey::PortalLegalName))->toBe('Példa Kft.');

    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::ClientLoginOtpSmsEnabled) => true, $ttl => 9])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($settings->int(SettingKey::ClientLoginOtpSmsTtlMinutes))->toBe(9)
        ->and($settings->bool(SettingKey::ClientLoginOtpSmsEnabled))->toBeTrue();
});

it('names the tab and the block of a changed setting for the unsaved-changes guard', function (): void {
    $labels = Livewire::test(ManageSettings::class)->get('unsavedLabels');

    expect($labels[ManageSettings::fieldName(SettingKey::ClientLoginOtpSmsTtlMinutes)])->toBe([
        'label' => SettingKey::ClientLoginOtpSmsTtlMinutes->label(),
        'group' => SettingTab::Portal->label(),
        'section' => SettingSection::PortalSignIn->label(),
    ])->and($labels[ManageSettings::fieldName(SettingKey::RetentionGeneralYears)])->toMatchArray([
        'group' => SettingTab::Operations->label(),
        'section' => SettingSection::Retention->label(),
    ]);
});

it('marks a value that differs from the default and says what the installation lacks', function (): void {
    app(Settings::class)->set(SettingKey::UserLoginSessionMaxHours, 10);

    Livewire::test(ManageSettings::class)
        ->assertSee(__('Default: :value', ['value' => '12 '.__('setting-unit.hours')]))
        ->assertDontSee(__('Default: :value', ['value' => '24 '.__('setting-unit.hours')]))
        ->assertFormFieldIsDisabled(ManageSettings::fieldName(SettingKey::ClientSsoAdfsEnabled))
        ->assertSee('Missing in the installation')
        ->assertSee(__('Not used: this installation reads EMD through another source (EMD_SYNC_DRIVER=:driver).', ['driver' => 'spreadsheet']));
});

it('indexes every setting for the search by its label, block, tab, key and earlier label, not by its value', function (): void {
    app(Settings::class)->set(SettingKey::PortalLegalName, 'Egyedi Jogi Név Kft.');
    $index = collect(Livewire::test(ManageSettings::class)->instance()->searchIndex())->keyBy('key');

    expect($index)->toHaveCount(count(SettingKey::cases()));

    $lockout = $index[SettingKey::ClientLoginLockoutMaxAttempts->value];
    expect($lockout['tab'])->toBe(SettingTab::Portal->label())
        ->and($lockout['section'])->toBe(SettingSection::PortalSignIn->label())
        ->and($lockout['master'])->toBe(SettingKey::ClientLoginOtpSmsEnabled->value)
        ->and($lockout['text'])->toContain(SettingKey::ClientLoginLockoutMaxAttempts->value)
        ->toContain(SettingKey::ClientLoginLockoutMaxAttempts->generatedLabel())
        ->toContain(SettingKey::ClientLoginLockoutMaxAttempts->groupLabel())
        // The staff lockout counts second-factor codes too: it is not hidden under the password switch.
        ->and($index[SettingKey::UserLoginLockoutMaxAttempts->value]['master'])->toBeNull()
        ->and($index[SettingKey::ClientSsoEntraEnabled->value]['text'])->toContain('CLIENT_ENTRA_')
        ->and($index->pluck('text')->implode(' '))->not->toContain('Egyedi Jogi Név Kft.');
});

it('formats a request body for reading, marks its placeholders and escapes everything', function (): void {
    $overview = JsonOverview::of('{"from":"{{ now }}","ids":[{{ ids }}],"note":"<script>alert(1)</script>","n":3,"on":true}', RequestPayload::EXPORT_PLACEHOLDERS);
    $html = implode("\n", array_map(fn ($line): string => $line->toHtml(), $overview->lines));

    expect($overview->valid)->toBeTrue()
        ->and($overview->used)->toBe(['now', 'ids'])
        ->and($overview->formatted)->toContain('{{ ids }}')->toContain('"from": "{{ now }}"')
        ->and($html)->toContain('<span class="hdid-json-ph">{{ now }}</span>')
        ->toContain('<span class="hdid-json-ph">{{ ids }}</span>')
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>');

    $unknown = JsonOverview::of('{"x":"{{ later }}"}', RequestPayload::EXPORT_PLACEHOLDERS, 'Unknown placeholder: later.');
    expect($unknown->valid)->toBeFalse()
        ->and($unknown->unknown)->toBe(['later'])
        ->and($unknown->lines[0]->toHtml().$unknown->lines[1]->toHtml())->toContain('hdid-json-ph-unknown');

    $broken = JsonOverview::of("{\n  \"a\": 1,\n}", RequestPayload::EXPORT_PLACEHOLDERS);
    expect($broken->valid)->toBeFalse()
        ->and($broken->error)->toStartWith(__('Invalid JSON. :fault', ['fault' => '']))
        ->and($broken->lines)->toHaveCount(3);
});

it('opens the readable view of the export payload from the field without changing it', function (): void {
    $settings = app(Settings::class);
    $settings->set(SettingKey::SyncExportPayload, '{"filter":{"from":"{{ now }}"}}');
    $field = ManageSettings::fieldName(SettingKey::SyncExportPayload);

    // The modal is not part of the rendered page in a Livewire test: its content is rendered from the mounted action.
    $page = Livewire::test(ManageSettings::class)
        ->set('data.'.$field, '{"filter":{"from":"{{ now }}"},"ids":[{{ ids }}]}')
        ->mountAction(TestAction::make('syncExportPayloadOverview')->schemaComponent($field))
        ->assertActionMounted(TestAction::make('syncExportPayloadOverview')->schemaComponent($field));
    $modal = $page->instance()->getMountedAction()->getModalContent()->render();

    expect($modal)->toContain(__('Valid JSON; Save accepts it as it stands.'))
        ->toContain('<span class="hdid-json-key">&quot;filter&quot;</span>')
        ->toContain('<span class="hdid-json-ph">{{ ids }}</span>')
        ->not->toContain(__('Unknown placeholder: Save refuses it.'));

    $page->set('data.'.$field, '{"filter":"{{ later }}"}')
        ->mountAction(TestAction::make('syncExportPayloadOverview')->schemaComponent($field));
    expect($page->instance()->getMountedAction()->getModalContent()->render())
        ->toContain(__('Unknown placeholder: Save refuses it.'))
        ->toContain('hdid-json-ph-unknown');

    expect($settings->string(SettingKey::SyncExportPayload))->toBe('{"filter":{"from":"{{ now }}"}}');
});
