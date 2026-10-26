<?php

use App\Auth\Oidc\OidcProvider;
use App\Auth\Role;
use App\Enums\PrincipalType;
use App\Filament\Admin\Pages\ManageSettings;
use App\Models\ExternalRecord;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Under the client Entra switch the settings page lists the tenants of the
 * installation (CLIENT_ENTRA_TENANTS) and, per domain, what the EMD sync
 * makes of it (owner's request, 2026-10-25).
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');
    app(Settings::class)->set(SettingKey::SyncUserDomains, ['staff.hu']);
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['clients.hu', 'other.hu']);
});

/**
 * @param  string|null  $list  CLIENT_ENTRA_TENANTS
 */
function settingsTenants(?string $list): void
{
    config()->set('hdid.oidc.client.entra', ['tenant' => null, 'tenants' => $list, 'client_id' => 'client-app', 'client_secret' => 's']);
}

it('lists every allowed tenant under the client Entra switch with the state of its domains', function (): void {
    settingsTenants('11111111-1111-4111-8111-111111111111,22222222-2222-4222-8222-222222222222:clients.hu|staff.hu|stray.hu');
    app(Settings::class)->set(SettingKey::ClientSsoEntraEnabled, true);
    ExternalRecord::factory()->client()->create(['email' => 'a@clients.hu']);

    Livewire::test(ManageSettings::class)
        ->assertSee(__('Allowed tenants (CLIENT_ENTRA_TENANTS)'))
        ->assertSee('11111111-1111-4111-8111-111111111111')
        ->assertSee(__('any domain (trusted tenant)'))
        ->assertSee('22222222-2222-4222-8222-222222222222')
        ->assertSeeInOrder(['clients.hu', __('client domain in the EMD sync, :count record(s)', ['count' => 1])])
        ->assertSeeInOrder(['staff.hu', 'filed as staff'])
        ->assertSeeInOrder(['stray.hu', __('on no EMD domain list: the sync skips these rows, and without an EMD record no client signs in (CLIENT_LOGIN_REQUIRE_EMD)')])
        ->assertSee(__('Legend:'))
        ->assertDontSee(__('The switch above is off in the saved settings, so this list does not act now.'))
        // A trusted tenant reaches other.hu, so nothing is unreachable.
        ->assertDontSee(__('No tenant allows these EMD client domains, so their clients cannot sign in with Microsoft: :list', ['list' => 'other.hu (0 record(s))']));
});

it('shows the list while the switch is off, saying it does not act now, and names the unreachable client domains', function (): void {
    settingsTenants('22222222-2222-4222-8222-222222222222:clients.hu');

    Livewire::test(ManageSettings::class)
        ->assertSee('22222222-2222-4222-8222-222222222222')
        ->assertSee(__('The switch above is off in the saved settings, so this list does not act now.'))
        ->assertSee(__('No tenant allows these EMD client domains, so their clients cannot sign in with Microsoft: :list', ['list' => 'other.hu (0 record(s))']));
});

it('says when no tenant is set and what is wrong with a broken list', function (): void {
    settingsTenants(null);

    Livewire::test(ManageSettings::class)
        ->assertSee(__('Allowed tenants (CLIENT_ENTRA_TENANTS)'))
        ->assertSee(__('No tenant is set: give the directory (tenant) ids in CLIENT_ENTRA_TENANTS (a single tenant may also go in CLIENT_ENTRA_TENANT) in the .env file, then restart the services.'));

    settingsTenants('organizations,22222222-2222-4222-8222-222222222222:clients.hu');
    $problem = OidcProvider::Entra->configFor(PrincipalType::Client)->tenantProblem;

    Livewire::test(ManageSettings::class)
        ->assertSee(__('The tenant list cannot be used, so nobody signs in with Microsoft until it is fixed: :problem', ['problem' => $problem]))
        ->assertSee('22222222-2222-4222-8222-222222222222');

    // An oddity that does not stop sign-ins is shown as well.
    settingsTenants('11111111-1111-4111-8111-111111111111:clients.hu,22222222-2222-4222-8222-222222222222:clients.hu');

    Livewire::test(ManageSettings::class)
        ->assertSee(OidcProvider::Entra->configFor(PrincipalType::Client)->tenantNote);
});
