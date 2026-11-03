<?php

use App\Auth\Role;
use App\Clients\ClientLinks;
use App\Clients\ClientTiers;
use App\Clients\PackageListImpact;
use App\Filament\Admin\Pages\ManageSettings;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Support\HuDate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

function premiumField(): string
{
    return ManageSettings::fieldName(SettingKey::PackagesPremium);
}

function standardField(): string
{
    return ManageSettings::fieldName(SettingKey::PackagesStandard);
}

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');
});

it('saves with the Save button without any dialog when nothing needs confirming', function (): void {
    $field = ManageSettings::fieldName(SettingKey::PortalFooterText);

    Livewire::test(ManageSettings::class)
        ->fillForm([$field => 'Új lábléc'])
        ->mountAction('save')
        ->assertHasNoFormErrors();

    expect(app(Settings::class)->string(SettingKey::PortalFooterText))->toBe('Új lábléc');
});

it('estimates who loses access, which sponsors stop sponsoring and whose tier moves', function (): void {
    $sponsor = Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null, 'name' => 'Fő Ügyfél']);
    $dependent = Client::factory()->synced()->create(['implicit_package' => 'Basic', 'explicit_package' => 'Premium', 'name' => 'Kapcsolt Kata']);
    app(ClientLinks::class)->link($sponsor, $dependent);
    Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null, 'name' => 'Magányos Márk']);
    Client::factory()->synced()->closed('admin')->create(['implicit_package' => 'Premium', 'explicit_package' => null, 'name' => 'Lezárt Lajos']);
    Client::factory()->synced()->create(['implicit_package' => 'Basic', 'explicit_package' => null, 'name' => 'Normál Nóra']);
    $impact = app(PackageListImpact::class);

    // Premium moves to the standard list: nobody loses access, the sponsor stops sponsoring, premium clients are downgraded.
    $moved = $impact->estimate(['Gold'], ['Basic', 'Premium']);
    expect($moved['changed'])->toBeTrue()
        ->and($moved['duplicates'])->toBe([])
        ->and($moved['losing_access']['count'])->toBe(0)
        ->and($moved['sponsors'])->toMatchArray(['count' => 1, 'links' => 1, 'sample' => ['Fő Ügyfél']])
        ->and($moved['downgraded']['count'])->toBe(3)
        ->and($moved['upgraded']['count'])->toBe(0)
        ->and($impact->needsConfirmation($moved))->toBeTrue()
        ->and($impact->errors($moved))->toBe([])
        ->and($impact->summary($moved))->toContain('Fő Ügyfél');

    // Premium dropped from both lists: its open wearers would lose access, a hard error; the closed one does not count.
    $dropped = $impact->estimate(['Gold'], ['Basic']);
    expect($dropped['losing_access']['count'])->toBe(2)
        ->and(collect($dropped['losing_access']['sample'])->sort()->values()->all())->toBe(['Fő Ügyfél', 'Magányos Márk'])
        ->and($dropped['downgraded']['count'])->toBe(1)
        ->and($impact->errors($dropped))->toHaveCount(1);

    // A name on both lists is refused even if nobody wears it; unchanged lists have no impact.
    expect($impact->errors($impact->estimate(['Premium', 'Shared'], ['Basic', 'Shared']))[0])->toContain('shared')
        ->and($impact->estimate(['Premium'], ['Basic'])['changed'])->toBeFalse();
});

it('refuses to save a package name that is on both lists', function (): void {
    Livewire::test(ManageSettings::class)
        ->fillForm([premiumField() => ['Premium', 'Shared'], standardField() => ['Basic', 'Shared']])
        ->call('save')
        ->assertHasFormErrors([premiumField()])
        ->assertNotified(__('Settings not saved'));

    expect(app(Settings::class)->array(SettingKey::PackagesPremium))->toBe(['Premium']);
});

it('refuses to drop a package that open clients wear unless it moves to the standard list', function (): void {
    Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null, 'name' => 'Prémium Pál']);

    Livewire::test(ManageSettings::class)
        ->fillForm([premiumField() => ['Gold'], standardField() => ['Basic']])
        ->call('save')
        ->assertHasFormErrors([premiumField()]);
    expect(app(Settings::class)->array(SettingKey::PackagesPremium))->toBe(['Premium']);

    // Through the standard list it is allowed, after a confirmation that names the downgraded client.
    Livewire::test(ManageSettings::class)
        ->fillForm([premiumField() => ['Gold'], standardField() => ['Basic', 'Premium']])
        ->mountAction('save')
        ->assertActionMounted('save')
        ->assertMountedActionModalSee('Prémium Pál')
        ->callMountedAction()
        ->assertHasNoFormErrors();
    expect(app(Settings::class)->array(SettingKey::PackagesStandard))->toContain('Premium');
});

it('asks for confirmation when a sponsor package leaves the premium list and ends the links on save', function (): void {
    $sponsor = Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null, 'name' => 'Fő Ügyfél']);
    $dependent = Client::factory()->synced()->create(['implicit_package' => 'Basic', 'explicit_package' => 'Premium']);
    $link = app(ClientLinks::class)->link($sponsor, $dependent);

    Livewire::test(ManageSettings::class)
        ->fillForm([premiumField() => ['Gold'], standardField() => ['Basic', 'Premium']])
        ->mountAction('save')
        ->assertActionMounted('save')
        ->assertMountedActionModalSee('Fő Ügyfél')
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified(__('Settings saved'));

    expect($link->fresh()->isActive())->toBeFalse('the link ends on save, not at the hourly sweep')
        ->and($link->fresh()->ended_reason)->toBe(ClientLinks::END_REASON_SPONSOR_INELIGIBLE)
        ->and($dependent->fresh()->isClosed())->toBeFalse()
        ->and($sponsor->fresh()->isClosed())->toBeFalse();
});

it('saves the package lists without confirmation when no open client is affected', function (): void {
    Livewire::test(ManageSettings::class)
        ->fillForm([premiumField() => ['Premium', 'Gold']])
        ->callAction('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('Settings saved'));

    expect(app(Settings::class)->array(SettingKey::PackagesPremium))->toContain('Gold');
});

it('saves two packages on different lists that differ only in a character without an ASCII spelling', function (): void {
    Livewire::test(ManageSettings::class)
        ->fillForm([premiumField() => ['Premium', 'VIP 金'], standardField() => ['Basic', 'VIP 银']])
        ->mountAction('save')
        ->assertHasNoFormErrors();

    expect(app(Settings::class)->array(SettingKey::PackagesPremium))->toContain('VIP 金')
        ->and(app(Settings::class)->array(SettingKey::PackagesStandard))->toContain('VIP 银');
});

it('refuses a package list entry that has no comparable spelling, naming it', function (): void {
    Livewire::test(ManageSettings::class)
        ->fillForm([premiumField() => ['Premium', "\u{200B}"]])
        ->call('save')
        ->assertHasFormErrors([premiumField()]);

    expect(app(Settings::class)->array(SettingKey::PackagesPremium))->toBe(['Premium']);
});

it('asks for confirmation naming the clients that a package added to a list lets into the portal, and says it after saving', function (string $list): void {
    Client::factory()->synced()->create(['implicit_package' => 'Trial', 'explicit_package' => null, 'name' => 'Trial Tamás']);
    $lists = $list === 'premium'
        ? [premiumField() => ['Premium', 'Trial'], standardField() => ['Basic']]
        : [premiumField() => ['Premium'], standardField() => ['Basic', 'Trial']];

    Livewire::test(ManageSettings::class)
        ->fillForm($lists)
        ->mountAction('save')
        ->assertActionMounted('save')
        ->assertMountedActionModalSee('Trial Tamás')
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(app(ClientTiers::class)->isEntitled(Client::query()->where('name', 'Trial Tamás')->sole()))->toBeTrue();
})->with(['premium', 'standard']);

it('names the save that added the package when taking it off would lock its wearers out', function (): void {
    Livewire::test(ManageSettings::class)
        ->fillForm([premiumField() => ['Premium', 'Trial'], standardField() => ['Basic']])
        ->mountAction('save')->assertHasNoFormErrors();
    Client::factory()->synced()->create(['implicit_package' => 'Trial', 'explicit_package' => null, 'name' => 'Trial Tamás']);

    $errors = app(PackageListImpact::class)->errors(app(PackageListImpact::class)->estimate(['Premium'], ['Basic']));

    expect(implode(' ', $errors))->toContain('Trial Tamás')
        ->toContain(__('The package :package was added to the lists by the save of :time (:by).', ['package' => 'Trial', 'time' => now()->format(HuDate::DATETIME), 'by' => auth()->user()->name]));
});

it('names in the confirmation and ends on save the links of linked clients whose own implicit package joins the premium list', function (): void {
    app(Settings::class)->set(SettingKey::PackagesPremium, ['Premium']);
    app(Settings::class)->set(SettingKey::PackagesStandard, ['Basic', 'Gold']);
    $links = app(ClientLinks::class);
    $sponsor = Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null]);
    $open = Client::factory()->synced()->create(['implicit_package' => 'Gold', 'explicit_package' => 'Premium', 'name' => 'Arany Anna']);
    $openLink = $links->link($sponsor, $open);
    $closedSponsor = Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null]);
    $closed = Client::factory()->synced()->create(['implicit_package' => 'Gold', 'explicit_package' => 'Premium', 'name' => 'Arany Béla']);
    $links->link($closedSponsor, $closed);
    $closedSponsor->close('admin');
    expect($closed->fresh()->closed_reason)->toBe(ClientLinks::CLOSE_REASON_SPONSOR);

    $impact = app(PackageListImpact::class)->estimate(['Premium', 'Gold'], ['Basic']);
    expect($impact['self_sponsored']['count'])->toBe(2)
        ->and($impact['reopened']['sample'])->toBe(['Arany Béla']);

    Livewire::test(ManageSettings::class)
        ->fillForm([premiumField() => ['Premium', 'Gold'], standardField() => ['Basic']])
        ->mountAction('save')
        ->assertActionMounted('save')
        ->assertMountedActionModalSee('Arany Anna')
        ->assertMountedActionModalSee('Arany Béla')
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified(__('Settings saved'));

    expect($openLink->fresh()->ended_reason)->toBe(ClientLinks::END_REASON_LINKED_OWN_PREMIUM)
        ->and($closed->fresh()->isClosed())->toBeFalse()
        ->and($closedSponsor->fresh()->isClosed())->toBeTrue();
});
