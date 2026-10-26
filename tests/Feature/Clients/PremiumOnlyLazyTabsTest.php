<?php

use App\Auth\Permission;
use App\Auth\Role;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Clients\RelationManagers\AnswersRelationManager;
use App\Filament\Admin\Resources\Clients\RelationManagers\IdSessionsRelationManager;
use App\Filament\Admin\Resources\Clients\RelationManagers\LinkedClientsRelationManager;
use App\Filament\Admin\Resources\Clients\RelationManagers\PhoneNumbersRelationManager;
use App\Models\Client;
use App\Models\ClientAnswer;
use App\Models\ClientPhoneNumber;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/*
 * A client tab (relation manager) is lazy: the page sends a placeholder
 * whose snapshot loads the tab later. Livewire skips the hydrate hooks on
 * that first load, so the served-owner check must not live there alone.
 */

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');

    $this->standard = Client::factory()->synced()->standard()->create(['name' => 'Normál Nándor']);
    $this->standard->phoneNumbers()->create(['number_e164' => '+36301112233', 'source' => 'admin']);
});

/**
 * The tab as the page sends it: a placeholder with the snapshot that
 * loads it, before premium-only mode hides its client.
 *
 * @param  class-string  $tab
 * @return array{0: Testable, 1: string}
 */
function lazyClientTab(string $tab, Client $client): array
{
    $test = Livewire::test($tab, ['ownerRecord' => $client, 'pageClass' => ViewClient::class, 'lazy' => true]);

    preg_match('/__lazyLoad\((?:\'|&#039;)([^\'&]+)(?:\'|&#039;)\)/', $test->html(), $match);
    expect($match[1] ?? null)->not->toBeNull('the tab rendered as a lazy placeholder');

    return [$test, $match[1]];
}

function hideStandardClients(): void
{
    app(Settings::class)->set(SettingKey::PackagesPremiumOnly, true);
}

/**
 * @return array<string, mixed>
 */
function mountedCreatePhone(string $number): array
{
    return ['mountedActions' => [[
        'name' => 'create',
        'arguments' => [],
        'context' => ['table' => true],
        'data' => ['number_e164' => $number, 'label' => null, 'is_primary' => false],
    ]]];
}

it('refuses the first load of a lazy client tab once the client is hidden', function (string $tab): void {
    // The linked clients tab shows for a client that has (or had) links.
    $this->standard->sponsoredLinks()->create(['linked_client_id' => Client::factory()->synced()->create()->id]);
    [$test, $encoded] = lazyClientTab($tab, $this->standard);

    hideStandardClients();

    $test->call('__lazyLoad', $encoded)->assertNotFound();
})->with([
    'phone numbers' => PhoneNumbersRelationManager::class,
    'answers' => AnswersRelationManager::class,
    'identification sessions' => IdSessionsRelationManager::class,
    'linked clients' => LinkedClientsRelationManager::class,
]);

it('does not show the numbers of a hidden client through the lazy placeholder', function (): void {
    [$test, $encoded] = lazyClientTab(PhoneNumbersRelationManager::class, $this->standard);

    hideStandardClients();

    $test->call('__lazyLoad', $encoded)->assertNotFound()->assertDontSee('+36301112233');
});

it('adds no number to a hidden client when the lazy load and the create come in one request', function (): void {
    [$test, $encoded] = lazyClientTab(PhoneNumbersRelationManager::class, $this->standard);

    hideStandardClients();

    $test->update(
        calls: [
            ['method' => '__lazyLoad', 'params' => [$encoded], 'path' => ''],
            ['method' => 'callMountedAction', 'params' => [], 'path' => ''],
        ],
        updates: mountedCreatePhone('+36309999999'),
    )->assertNotFound();

    expect($this->standard->phoneNumbers()->pluck('number_e164')->all())->toBe(['+36301112233']);
});

it('adds no number to a hidden client when the placeholder snapshot is replayed without the lazy load', function (): void {
    [$test] = lazyClientTab(PhoneNumbersRelationManager::class, $this->standard);

    hideStandardClients();

    $test->update(
        calls: [['method' => 'callMountedAction', 'params' => [], 'path' => '']],
        updates: mountedCreatePhone('+36308887777'),
    )->assertNotFound();

    expect($this->standard->phoneNumbers()->pluck('number_e164')->all())->toBe(['+36301112233']);
});

it('renders nothing of a hidden client when the placeholder snapshot is refreshed without the lazy load', function (): void {
    [$test] = lazyClientTab(PhoneNumbersRelationManager::class, $this->standard);

    hideStandardClients();

    $test->call('$refresh')->assertNotFound()->assertDontSee('+36301112233');
});

it('refuses the lazy load of a hidden client tab over HTTP, through the whole middleware stack', function (): void {
    $html = $this->get('/admin/clients/'.$this->standard->id.'?relation=1')->assertOk()->getContent();
    preg_match('/<[a-z]+\s[^>]*wire:name="'.preg_quote(e(PhoneNumbersRelationManager::class), '/').'"[^>]*>/', $html, $tag);
    preg_match('/wire:snapshot="([^"]+)"/', $tag[0] ?? '', $snapshot);
    preg_match('/__lazyLoad\(&#039;([^&]+)&#039;\)/', $tag[0] ?? '', $encoded);
    expect($snapshot[1] ?? null)->not->toBeNull('the page sent the phone numbers tab as a lazy placeholder')
        ->and($encoded[1] ?? null)->not->toBeNull();

    hideStandardClients();
    $this->get('/admin/clients/'.$this->standard->id)->assertNotFound();

    $replay = fn () => $this->withHeaders(['X-Livewire' => 'true'])->postJson(Livewire::getUpdateUri(), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => html_entity_decode($snapshot[1]),
            'updates' => [],
            'calls' => [['method' => '__lazyLoad', 'params' => [$encoded[1]], 'path' => '']],
        ]],
    ]);

    $replay()->assertNotFound()->assertDontSee('+36301112233');
    $replay()->assertNotFound()->assertDontSee('+36301112233');
});

it('still loads the lazy tab of a served client', function (): void {
    [$test, $encoded] = lazyClientTab(PhoneNumbersRelationManager::class, $this->standard);

    $test->call('__lazyLoad', $encoded)->assertOk()->assertSee('+36301112233');

    $test->update(
        calls: [['method' => 'callMountedAction', 'params' => [], 'path' => '']],
        updates: mountedCreatePhone('+36305556677'),
    )->assertOk();

    expect($this->standard->phoneNumbers()->pluck('number_e164')->sort()->values()->all())->toBe(['+36301112233', '+36305556677']);
});

it('refuses the lazy tab to a user who lost the permission behind it', function (): void {
    [$test, $encoded] = lazyClientTab(PhoneNumbersRelationManager::class, $this->standard);

    $this->admin->syncRoles([]);

    $test->call('__lazyLoad', $encoded)->assertForbidden()->assertDontSee('+36301112233');
});

it('lets nobody create a phone number for a hidden client, by policy too', function (): void {
    $premium = Client::factory()->synced()->premium()->create();
    $tab = Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $this->standard, 'pageClass' => ViewClient::class]);
    expect($tab->invade()->getCreateAuthorizationResponse()->allowed())->toBeTrue();

    hideStandardClients();

    expect($tab->invade()->getCreateAuthorizationResponse()->allowed())->toBeFalse('the tab asks the policy about its own client');

    expect(Gate::forUser($this->admin)->allows('create', [ClientPhoneNumber::class, $this->standard]))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('create', [ClientPhoneNumber::class, $premium]))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('create', [ClientAnswer::class, $this->standard]))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('create', ClientPhoneNumber::class))->toBeTrue('without a client the permission alone decides, as before')
        ->and(Gate::forUser($this->admin)->allows('create', Client::class))->toBeTrue();
});

it('refuses every client tab to a panel user who keeps the panel but lost the client permission', function (string $tab): void {
    $this->standard->sponsoredLinks()->create(['linked_client_id' => Client::factory()->synced()->create(['name' => 'Kapcsolt Kató'])->id]);
    $deskOnly = SpatieRole::create(['name' => 'Desk only', 'guard_name' => 'web']);
    $deskOnly->givePermissionTo(Permission::HelpdeskAccess->value);

    [$lazy, $encoded] = lazyClientTab($tab, $this->standard);
    $loaded = Livewire::test($tab, ['ownerRecord' => $this->standard, 'pageClass' => ViewClient::class]);

    $this->admin->syncRoles([$deskOnly->name]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $lazy->call('__lazyLoad', $encoded)->assertForbidden()->assertDontSee('Kapcsolt Kató');
    $loaded->call('$refresh')->assertForbidden()->assertDontSee('Kapcsolt Kató');
})->with([
    'phone numbers' => PhoneNumbersRelationManager::class,
    'answers' => AnswersRelationManager::class,
    'identification sessions' => IdSessionsRelationManager::class,
    'linked clients' => LinkedClientsRelationManager::class,
]);
