<?php

use App\Auth\Role;
use App\Filament\Admin\Pages\ManageSettings;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Pages\CreateClient;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\MessageTemplates\Pages\EditMessageTemplate;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Client;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Settings\SettingSection;
use App\Settings\SettingTab;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');
});

it('puts the leave guard on every page with a Save button and nowhere else', function (): void {
    $client = Client::factory()->synced()->create();

    $this->get(ManageSettings::getUrl())->assertOk()->assertSee('hdid-unsaved-guard', false)->assertSee(__('Save and go on'));
    $this->get(ClientResource::getUrl('edit', ['record' => $client]))->assertOk()->assertSee('hdid-unsaved-guard', false);
    $this->get(ClientResource::getUrl('create'))->assertOk()->assertSee('hdid-unsaved-guard', false);
    $this->get(UserResource::getUrl('edit', ['record' => auth()->user()]))->assertOk()->assertSee('hdid-unsaved-guard', false);
    $this->get(ClientResource::getUrl('index'))->assertOk()->assertDontSee('hdid-unsaved-guard', false);
});

it('remembers the saved form and names every field with its tab', function (): void {
    $domains = ManageSettings::fieldName(SettingKey::SyncUserDomains);
    $page = Livewire::test(ManageSettings::class);

    // The group is the tab (its dot marks a change), the section the block within it.
    expect($page->get('unsavedLabels')[$domains])->toBe(['label' => SettingKey::SyncUserDomains->label(), 'group' => SettingTab::Directory->label(), 'section' => SettingSection::DirectoryClassification->label()])
        ->and($page->instance()->isUnchangedSinceSaved())->toBeTrue();

    $page->fillForm([$domains => ['domain.com']]);
    expect($page->instance()->isUnchangedSinceSaved())->toBeFalse();

    $page->call('save')->assertHasNoFormErrors();
    expect($page->instance()->isUnchangedSinceSaved())->toBeTrue()
        ->and(json_decode($page->get('unsavedBaseline'), true)[$domains])->toBe(['domain.com']);
});

it('saves and then follows the link when leaving with Save and go on', function (): void {
    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::PortalLegalName) => 'Helpdesk Kft.'])
        ->call('saveAndLeave', '/admin/clients')
        ->assertHasNoFormErrors()
        ->assertRedirect('/admin/clients');

    expect(app(Settings::class)->string(SettingKey::PortalLegalName))->toBe('Helpdesk Kft.');

    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::PortalLegalName) => 'Másik Kft.'])
        ->call('saveAndLeave', 'https://elsewhere.example/steal')
        ->assertRedirect(Filament::getUrl());
});

it('asks for the confirmation first and follows the link once it is given', function (): void {
    Client::factory()->synced()->standard()->create(['name' => 'Normál Nándor']);

    $page = Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::PackagesPremiumOnly) => true])
        ->call('saveAndLeave', '/admin/clients')
        ->assertActionMounted('save')
        ->assertNoRedirect();
    expect(app(Settings::class)->bool(SettingKey::PackagesPremiumOnly))->toBeFalse();

    $page->callMountedAction()->assertRedirect('/admin/clients');
    expect(app(Settings::class)->bool(SettingKey::PackagesPremiumOnly))->toBeTrue();
});

it('keeps the page open with its errors when the save is refused', function (): void {
    $user = User::factory()->withRole(Role::Agent)->create(['name' => 'Régi Név']);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm(['name' => ''])
        ->call('saveAndLeave', '/admin/clients')
        ->assertHasFormErrors(['name' => 'required'])
        ->assertNoRedirect();

    expect($user->fresh()->name)->toBe('Régi Név');
});

it('saves a record page, or creates the record, and then follows the link', function (): void {
    $user = User::factory()->withRole(Role::Agent)->create();
    $client = Client::factory()->synced()->create(['notes' => null]);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm(['name' => 'Új Munkatárs'])
        ->call('saveAndLeave', '/admin/clients')
        ->assertHasNoFormErrors()
        ->assertRedirect('/admin/clients');
    expect($user->fresh()->name)->toBe('Új Munkatárs');

    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm(['notes' => 'Visszahívást kért.'])
        ->call('saveAndLeave', '/admin/system/settings')
        ->assertRedirect('/admin/system/settings');
    expect($client->fresh()->notes)->toBe('Visszahívást kért.');

    Livewire::test(CreateClient::class)
        ->fillForm(['name' => 'Új Ügyfél', 'email' => 'uj@example.test', 'explicit_package' => 'Premium'])
        ->call('saveAndLeave', '/admin/clients')
        ->assertHasNoFormErrors()
        ->assertRedirect('/admin/clients');
    expect(Client::query()->where('email', 'uj@example.test')->exists())->toBeTrue();
});

it('only saves, without following anything, when a panel form asked to leave', function (): void {
    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::PortalLegalName) => 'Helpdesk Kft.'])
        ->call('saveAndLeave')
        ->assertHasNoFormErrors()
        ->assertNoRedirect();

    expect(app(Settings::class)->string(SettingKey::PortalLegalName))->toBe('Helpdesk Kft.');
});

it('takes a refilled form as the saved state: create another, reset to default', function (): void {
    $create = Livewire::test(CreateClient::class)
        ->fillForm(['name' => 'Első Ügyfél', 'email' => 'elso@example.test', 'explicit_package' => 'Premium'])
        ->call('create', true)
        ->assertHasNoFormErrors();
    expect($create->instance()->isUnchangedSinceSaved())->toBeTrue();

    $this->get('/admin/content/message-templates')->assertOk();
    $template = MessageTemplate::query()->where('key', 'pin_sms')->where('locale', 'hu')->firstOrFail();
    $template->forceFill(['body' => 'Saját PIN: {{ pin }}'])->save();

    $edit = Livewire::test(EditMessageTemplate::class, ['record' => $template->id])->callAction('resetToDefault');
    expect($edit->instance()->isUnchangedSinceSaved())->toBeTrue();
});

it('follows only this site\'s own addresses', function (string $url, bool $followed): void {
    $home = Filament::getUrl();
    $absolute = str_replace('{site}', request()->getSchemeAndHttpHost(), $url);

    expect(ManageSettings::safeLeaveUrl($absolute))->toBe($followed ? $absolute : $home);
})->with([
    'path' => ['/admin/clients', true],
    'own absolute address' => ['{site}/admin/clients', true],
    'protocol-relative' => ['//evil.example/x', false],
    'backslash after the slash' => ['/\\evil.example', false],
    'tab inside' => ["/\t/evil.example", false],
    'line break inside' => ["/\n/evil.example", false],
    'backslash before an at sign' => ['https://evil.example\\@localhost/x', false],
    'user info' => ['{site}@evil.example/x', false],
    'longer host' => ['{site}.evil.example/x', false],
    'script' => ['javascript:alert(1)', false],
]);

it('follows the panel\'s own links when the configured root is written differently from the request', function (string $root): void {
    URL::forceRootUrl($root);
    $own = rtrim(url('/'), '/').'/admin/clients';

    try {
        expect(ManageSettings::safeLeaveUrl($own))->toBe($own)
            ->and(ManageSettings::safeLeaveUrl('https://evil.example/admin/clients'))->toBe(Filament::getUrl());
    } finally {
        URL::forceRootUrl(null);
    }
})->with([
    'a written-out :443 port' => ['https://hdid.example.test:443'],
    'https while the request is http' => ['https://localhost'],
]);

it('lets a panel form go on after a save that needed a confirmation', function (): void {
    Client::factory()->synced()->standard()->create(['name' => 'Normál Nándor']);

    $page = Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::PackagesPremiumOnly) => true])
        ->call('saveAndLeave', null, true)
        ->assertActionMounted('save')
        ->assertNotDispatched('hdid-leave-continue');

    $page->callMountedAction()->assertDispatched('hdid-leave-continue')->assertNoRedirect();
    expect(app(Settings::class)->bool(SettingKey::PackagesPremiumOnly))->toBeTrue();
});

it('keeps a removed image on the disk until the change is saved', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('branding/logo.png', 'x');
    app(Settings::class)->set(SettingKey::BrandingLogo, 'branding/logo.png');
    $page = Livewire::test(ManageSettings::class);

    $field = collect($page->instance()->form->getFlatFields(withHidden: true))
        ->first(fn ($field): bool => $field->getName() === ManageSettings::fieldName(SettingKey::BrandingLogo));
    $field->deleteUploadedFile((string) array_key_first((array) $field->getRawState()));

    expect(Storage::disk('public')->exists('branding/logo.png'))->toBeTrue();
});
