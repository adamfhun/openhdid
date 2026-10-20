<?php

use App\Auth\Permission;
use App\Auth\Role;
use App\Filament\Admin\Resources\ApiKeys\Pages\ManageApiKeys;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('links the interface documents of every partner from the API keys page, each in a new tab', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    $page = Livewire::test(ManageApiKeys::class)->assertSee(__('API documentation'));

    foreach (['scramble.ivr.ui', 'scramble.mobile.ui', 'scramble.docs.ui'] as $route) {
        $page->assertSeeHtml('href="'.route($route).'"');
    }
    $page->assertSeeHtml('target="_blank"');
});

it('offers no documentation links to whoever may not read the documents', function (): void {
    $keeper = User::factory()->synced()->create();
    $keeper->givePermissionTo([Permission::HelpdeskAccess->value, Permission::ApiKeysManage->value]);
    $this->actingAs($keeper);

    Livewire::test(ManageApiKeys::class)
        ->assertOk()
        ->assertDontSee(__('API documentation'))
        ->assertDontSeeHtml('href="'.route('scramble.ivr.ui').'"');
    $this->get(route('scramble.ivr.ui'))->assertForbidden();
});
