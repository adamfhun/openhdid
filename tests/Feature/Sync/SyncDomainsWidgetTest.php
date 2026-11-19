<?php

use App\Auth\Role;
use App\Filament\Admin\Resources\SyncRuns\SyncRunResource;
use App\Filament\Admin\Widgets\SyncDomainsWidget;
use App\Models\ExternalRecord;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

it('lists the set and the synced e-mail domains on the sync runs page, for information', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create());
    app(Settings::class)->set(SettingKey::SyncUserDomains, ['corp.hu']);
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['client.hu', 'ures.hu']);
    // The domain of a record follows its address.
    ExternalRecord::factory()->client()->create(['email' => 'a@client.hu']);
    ExternalRecord::factory()->client()->create(['email' => 'b@client.hu']);
    ExternalRecord::factory()->client()->missing()->create(['email' => 'c@client.hu']);
    ExternalRecord::factory()->client()->create(['email' => 'd@regi.hu']);

    $rows = collect(app(SyncDomainsWidget::class)->getRows())->keyBy(fn (array $row): string => $row['kind']->value.':'.$row['domain']);

    expect($rows->keys()->all())->toContain('client:client.hu', 'client:regi.hu', 'client:ures.hu', 'user:corp.hu')
        ->and($rows['user:corp.hu'])->toMatchArray(['configured' => true, 'present' => 0])
        ->and($rows['client:client.hu'])->toMatchArray(['configured' => true, 'present' => 2, 'missing' => 1])
        ->and($rows['client:ures.hu'])->toMatchArray(['configured' => true, 'present' => 0])
        ->and($rows['client:regi.hu'])->toMatchArray(['configured' => false, 'present' => 1]);

    $this->get(SyncRunResource::getUrl())->assertOk()
        ->assertSee(__('E-mail domains of the sync'))
        ->assertSeeHtml('data-hdid-sync-domain="client:ures.hu"')
        ->assertSee(__('set, no record came'));

    // An agent has no business with the sync: the block does not render for them.
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    expect(SyncDomainsWidget::canView())->toBeFalse();
    Livewire::test(SyncDomainsWidget::class)->assertForbidden();
});
