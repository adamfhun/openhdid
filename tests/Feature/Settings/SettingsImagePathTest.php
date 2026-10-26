<?php

use App\Auth\Role;
use App\Filament\Admin\Pages\ManageSettings;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');
    Storage::fake('public');
    Storage::disk('public')->put('branding/old-logo.png', 'x');
    // A file of the public disk that no image setting points to.
    Storage::disk('public')->put('news/canary.png', 'x');
    app(Settings::class)->set(SettingKey::BrandingLogo, 'branding/old-logo.png');
});

it('refuses an image path typed into the settings instead of an upload', function (string $path): void {
    $page = Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::BrandingLogo) => [$path]])
        ->call('save');

    // A stored foreign path would be deleted from the disk by the next image change.
    expect(app(Settings::class)->string(SettingKey::BrandingLogo))->toBe('branding/old-logo.png')
        ->and(Storage::disk('public')->exists('branding/old-logo.png'))->toBeTrue()
        ->and(Storage::disk('public')->exists('news/canary.png'))->toBeTrue();
    $page->assertHasFormErrors([ManageSettings::fieldName(SettingKey::BrandingLogo) => __('The file has to be uploaded here; a file path cannot be given.')]);
})->with(['news/canary.png', 'branding/other-logo.png']);

it('keeps the stored image when another setting is saved', function (): void {
    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::PortalLegalName) => 'Helpdesk Kft.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(Settings::class)->string(SettingKey::PortalLegalName))->toBe('Helpdesk Kft.')
        ->and(app(Settings::class)->string(SettingKey::BrandingLogo))->toBe('branding/old-logo.png')
        ->and(Storage::disk('public')->exists('branding/old-logo.png'))->toBeTrue();
});

it('saves a page opened before another admin changed the image without undoing that change', function (): void {
    $earlier = Livewire::test(ManageSettings::class);
    Storage::disk('public')->put('branding/newer-logo.png', 'x');
    app(Settings::class)->set(SettingKey::BrandingLogo, 'branding/newer-logo.png');

    $earlier->fillForm([ManageSettings::fieldName(SettingKey::PortalLegalName) => 'Helpdesk Kft.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(Settings::class)->string(SettingKey::BrandingLogo))->toBe('branding/newer-logo.png')
        ->and(Storage::disk('public')->exists('branding/newer-logo.png'))->toBeTrue();
});

it('stores a newly uploaded image and removes the replaced one', function (): void {
    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::BrandingLogo) => [UploadedFile::fake()->image('new-logo.png')]])
        ->call('save')
        ->assertHasNoFormErrors();

    $stored = app(Settings::class)->string(SettingKey::BrandingLogo);
    expect($stored)->toStartWith('branding/')->not->toBe('branding/old-logo.png')
        ->and(Storage::disk('public')->exists($stored))->toBeTrue()
        ->and(Storage::disk('public')->exists('branding/old-logo.png'))->toBeFalse()
        ->and(Storage::disk('public')->exists('news/canary.png'))->toBeTrue();
});

it('refuses the stored path of another image setting typed into an image field', function (): void {
    // The favicon pointed at the logo's file: the next favicon change would delete the logo.
    $page = Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::BrandingFavicon) => ['branding/old-logo.png']])
        ->call('save');

    $page->assertHasFormErrors([ManageSettings::fieldName(SettingKey::BrandingFavicon) => __('The file has to be uploaded here; a file path cannot be given.')]);
    expect(app(Settings::class)->get(SettingKey::BrandingFavicon))->toBeEmpty()
        ->and(app(Settings::class)->string(SettingKey::BrandingLogo))->toBe('branding/old-logo.png')
        ->and(Storage::disk('public')->exists('branding/old-logo.png'))->toBeTrue();
});
