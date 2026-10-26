<?php

use App\Auth\Role;
use App\Filament\Admin\Pages\ManageSettings;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Settings\SettingType;
use App\Support\EmailAddressRule;
use App\Support\InputLimits;
use App\Support\PhoneNumberRule;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

/**
 * The settings page bounds what an administrator types the way every other
 * form does: the field shows the limit, the save enforces the same number,
 * and lists and tables say theirs in the help text.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create());
    Filament::setCurrentPanel('admin');
});

/**
 * Whether the save-time rules of a setting accept the value.
 */
function settingLimitPasses(SettingKey $key, mixed $value): bool
{
    return Validator::make(['value' => $value], ['value' => ManageSettings::limitRules($key)])->passes();
}

it('gives every typed text setting one length bound for the field and the save', function (): void {
    $textKeys = array_filter(SettingKey::cases(), fn (SettingKey $key): bool => in_array($key->type(), [SettingType::Text, SettingType::LongText], true) && $key !== SettingKey::CallsDefaultTier);
    $page = Livewire::test(ManageSettings::class);

    foreach ($textKeys as $key) {
        $limit = ManageSettings::maxLengthOf($key);

        expect($limit)->toBeInt("{$key->value} has a length bound")
            ->and(settingLimitPasses($key, str_repeat('a', $limit + 1)))->toBeFalse("{$key->value} refuses a longer value on save");

        // The browser keeps typing within the bound; the save checks it on a changed value only.
        $page->assertFormFieldExists(ManageSettings::fieldName($key), fn (Field $field): bool => method_exists($field, 'getExtraInputAttributes') && ($field->getExtraInputAttributes()['maxlength'] ?? null) === $limit);
    }
});

it('bounds the short texts of the branding and the portal by their purpose', function (): void {
    expect(ManageSettings::maxLengthOf(SettingKey::BrandingAppName))->toBe(InputLimits::APP_NAME)
        ->and(ManageSettings::maxLengthOf(SettingKey::PremiumBadgeLabel))->toBe(InputLimits::BADGE)
        ->and(ManageSettings::maxLengthOf(SettingKey::PortalFooterText))->toBe(InputLimits::FOOTER)
        ->and(ManageSettings::maxLengthOf(SettingKey::SyncExportPayload))->toBe(InputLimits::JSON_CHARS)
        ->and(ManageSettings::maxLengthOf(SettingKey::SyncCsvEncoding))->toBe(40);

    Livewire::test(ManageSettings::class)
        ->fillForm([
            ManageSettings::fieldName(SettingKey::BrandingAppName) => str_repeat('N', InputLimits::APP_NAME + 1),
            ManageSettings::fieldName(SettingKey::PortalFooterText) => str_repeat('F', InputLimits::FOOTER + 1),
        ])
        ->call('save')
        ->assertHasFormErrors([ManageSettings::fieldName(SettingKey::BrandingAppName), ManageSettings::fieldName(SettingKey::PortalFooterText)]);

    expect(app(Settings::class)->string(SettingKey::BrandingAppName))->toBe(SettingKey::BrandingAppName->default());
});

it('takes support contacts and portal links only in their standard form', function (): void {
    $email = SettingKey::SupportPremiumEmail;
    $phone = SettingKey::SupportPremiumPhone;
    $url = SettingKey::PortalPrivacyUrl;

    expect(settingLimitPasses($email, 'help@corp.hu'))->toBeTrue()
        ->and(settingLimitPasses($email, null))->toBeTrue()
        ->and(settingLimitPasses($email, str_repeat('l', EmailAddressRule::LOCAL_MAX_LENGTH + 1).'@corp.hu'))->toBeFalse()
        ->and(settingLimitPasses($phone, '+36 1 234 5678'))->toBeTrue()
        ->and(settingLimitPasses($phone, 'call us'))->toBeFalse()
        ->and(settingLimitPasses($phone, '+36 1 234 5678'.str_repeat(' ', PhoneNumberRule::RAW_MAX_LENGTH)))->toBeFalse()
        ->and(settingLimitPasses($url, 'https://corp.hu/privacy'))->toBeTrue()
        ->and(settingLimitPasses($url, 'javascript:alert(1)'))->toBeFalse()
        ->and(settingLimitPasses($url, 'https://corp.hu/'.str_repeat('p', InputLimits::URL)))->toBeFalse();

    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName($phone) => 'call us', ManageSettings::fieldName($url) => 'javascript:alert(1)'])
        ->call('save')
        ->assertHasFormErrors([ManageSettings::fieldName($phone), ManageSettings::fieldName($url)]);

    expect(app(Settings::class)->string($url))->toBeNull();
});

it('checks a value only when this page changes it, so an older stored value does not block other saves', function (): void {
    app(Settings::class)->set(SettingKey::SupportStandardPhone, '1818');

    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::PortalLegalName) => 'Helpdesk Kft.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(Settings::class)->string(SettingKey::PortalLegalName))->toBe('Helpdesk Kft.')
        ->and(app(Settings::class)->string(SettingKey::SupportStandardPhone))->toBe('1818');
});

it('bounds the package and domain lists by entries and length, and takes only real domains', function (): void {
    $premium = SettingKey::PackagesPremium;
    $domains = SettingKey::SyncClientDomains;

    expect(settingLimitPasses($premium, ['Premium', 'Gold']))->toBeTrue()
        ->and(settingLimitPasses($premium, [str_repeat('P', InputLimits::NAME + 1)]))->toBeFalse()
        ->and(settingLimitPasses($premium, array_map(fn (int $i): string => 'P'.$i, range(1, ManageSettings::LIST_MAX_ITEMS + 1))))->toBeFalse()
        ->and(settingLimitPasses($domains, ['corp.hu', 'ügyfél.hu']))->toBeTrue()
        ->and(settingLimitPasses($domains, ['not a domain']))->toBeFalse()
        ->and(settingLimitPasses($domains, [str_repeat('d', 64).'.hu']))->toBeFalse()
        ->and(settingLimitPasses($domains, array_map(fn (int $i): string => 'd'.$i.'.hu', range(1, ManageSettings::LIST_MAX_ITEMS + 1))))->toBeFalse();

    Livewire::test(ManageSettings::class)
        ->assertSee(__('At most :items domains, each a domain name of at most :max characters.', ['items' => ManageSettings::LIST_MAX_ITEMS, 'max' => 253]))
        ->fillForm([ManageSettings::fieldName($domains) => ['corp_hu']])
        ->call('save')
        ->assertHasFormErrors([ManageSettings::fieldName($domains)]);

    expect(app(Settings::class)->array($domains))->toBe(SettingKey::SyncClientDomains->default() ?? []);
});

it('bounds the tables of the settings: call queues, EMD fields and the column mapping', function (): void {
    $queues = fn (int $count): array => collect(range(1, $count))->mapWithKeys(fn (int $i): array => ['Queue '.$i => 'premium'])->all();

    expect(settingLimitPasses(SettingKey::CallsQueueTiers, ['Helpdesk' => 'premium']))->toBeTrue()
        ->and(settingLimitPasses(SettingKey::CallsQueueTiers, ['Helpdesk' => 'gold']))->toBeFalse()
        ->and(settingLimitPasses(SettingKey::CallsQueueTiers, [str_repeat('q', ManageSettings::QUEUE_NAME_MAX_LENGTH + 1) => 'premium']))->toBeFalse()
        ->and(settingLimitPasses(SettingKey::CallsQueueTiers, $queues(ManageSettings::QUEUE_TIERS_MAX_ITEMS + 1)))->toBeFalse()
        ->and(settingLimitPasses(SettingKey::ClientsDirectoryFields, ['cost_center' => 'Cost centre']))->toBeTrue()
        ->and(settingLimitPasses(SettingKey::ClientsDirectoryFields, ['cost_center' => str_repeat('L', ManageSettings::DIRECTORY_LABEL_MAX_LENGTH + 1)]))->toBeFalse()
        ->and(settingLimitPasses(SettingKey::ClientsDirectoryFields, collect(range(1, ManageSettings::DIRECTORY_FIELDS_MAX_ITEMS + 1))->mapWithKeys(fn (int $i): array => ['c'.$i => 'C'])->all()))->toBeFalse()
        ->and(settingLimitPasses(SettingKey::SyncColumnMapping, ['email' => 'E-mail', 'phones' => ['Phone', 'Mobile']]))->toBeTrue()
        ->and(settingLimitPasses(SettingKey::SyncColumnMapping, ['email' => str_repeat('c', InputLimits::NAME + 1)]))->toBeFalse()
        ->and(settingLimitPasses(SettingKey::SyncColumnMapping, ['phones' => ['Phone', str_repeat('c', InputLimits::NAME + 1)]]))->toBeFalse();

    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::ClientsDirectoryFields) => ['cost_center' => str_repeat('L', ManageSettings::DIRECTORY_LABEL_MAX_LENGTH + 1)]])
        ->call('save')
        ->assertHasFormErrors([ManageSettings::fieldName(SettingKey::ClientsDirectoryFields)]);
});

it('takes colours only as #RRGGBB, so a typo is refused instead of falling back silently', function (): void {
    expect(settingLimitPasses(SettingKey::BrandingPrimaryColor, '#A1b2C3'))->toBeTrue()
        ->and(settingLimitPasses(SettingKey::BrandingPrimaryColor, '#abc'))->toBeFalse()
        ->and(settingLimitPasses(SettingKey::PremiumAccentColor, 'gold'))->toBeFalse();
});

it('takes the EMD API paths only as dotted names and parameter names', function (): void {
    expect(settingLimitPasses(SettingKey::SyncApiDataPath, 'data.items'))->toBeTrue()
        ->and(settingLimitPasses(SettingKey::SyncApiDataPath, 'data items'))->toBeFalse()
        ->and(settingLimitPasses(SettingKey::SyncApiPageParam, 'page'))->toBeTrue()
        ->and(settingLimitPasses(SettingKey::SyncApiPageParam, 'page[number]'))->toBeTrue()
        ->and(settingLimitPasses(SettingKey::SyncApiPageParam, 'page=1&x'))->toBeFalse();
});

it('saves another setting while an older stored value is longer than its field allows now, and keeps that value', function (): void {
    app(Settings::class)->set(SettingKey::PremiumBadgeLabel, str_repeat('B', 60));
    app(Settings::class)->set(SettingKey::SupportStandardHours, str_repeat('H', 300));

    $page = Livewire::test(ManageSettings::class)
        ->assertSee('The stored value is longer than the');
    $page->fillForm([ManageSettings::fieldName(SettingKey::PortalLegalName) => 'Helpdesk Kft.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(Settings::class)->string(SettingKey::PortalLegalName))->toBe('Helpdesk Kft.')
        ->and(app(Settings::class)->string(SettingKey::PremiumBadgeLabel))->toBe(str_repeat('B', 60))
        ->and(app(Settings::class)->string(SettingKey::SupportStandardHours))->toBe(str_repeat('H', 300));

    // A change of that value has to fit.
    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::PremiumBadgeLabel) => str_repeat('C', 60)])
        ->call('save')
        ->assertHasFormErrors([ManageSettings::fieldName(SettingKey::PremiumBadgeLabel)]);
    expect(app(Settings::class)->string(SettingKey::PremiumBadgeLabel))->toBe(str_repeat('B', 60));
});
