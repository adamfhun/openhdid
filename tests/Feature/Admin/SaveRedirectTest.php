<?php

use App\Auth\Role;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Pages\CreateClient;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Admin\Resources\MessageTemplates\Pages\EditMessageTemplate;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Client;
use App\Models\MessageTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');
});

it('returns to the client page after editing or creating a client', function (): void {
    $client = Client::factory()->create(['name' => 'Régi Név']);

    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm(['name' => 'Új Név'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(ClientResource::getUrl('view', ['record' => $client]));

    expect($client->fresh()->name)->toBe('Új Név');

    Livewire::test(CreateClient::class)
        ->fillForm(['name' => 'Új Ügyfél', 'email' => 'uj@example.test', 'explicit_package' => 'Premium'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(ClientResource::getUrl('view', ['record' => Client::query()->where('email', 'uj@example.test')->firstOrFail()]));
});

it('returns to the list after editing a record that has no view page', function (): void {
    $user = User::factory()->withRole(Role::Agent)->create();

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm(['name' => 'Új Munkatárs'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(UserResource::getUrl('index'));

    $this->get('/admin/content/message-templates')->assertOk();
    $template = MessageTemplate::query()->where('key', 'pin_sms')->where('locale', 'hu')->firstOrFail();

    Livewire::test(EditMessageTemplate::class, ['record' => $template->id])
        ->fillForm(['body' => 'Új PIN: {{ pin }}'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(MessageTemplateResource::getUrl('index'));
});
