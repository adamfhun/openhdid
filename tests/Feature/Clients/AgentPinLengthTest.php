<?php

use App\Auth\Role;
use App\Enums\IdChannel;
use App\Enums\IdSessionStatus;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Identification\PinService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\SettingRules;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

it('lets an agent set a pin of any length between six and ten digits', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    Filament::setCurrentPanel('admin');
    $client = Client::factory()->synced()->withPhone()->create();

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->callAction(TestAction::make('setPin')->schemaComponent('pin_hash'), ['pin' => '12345'])
        ->assertHasActionErrors(['pin']);

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->callAction(TestAction::make('setPin')->schemaComponent('pin_hash'), ['pin' => '123456789'])
        ->assertHasNoActionErrors();

    expect(Hash::check('123456789', $client->fresh()->pin_hash))->toBeTrue();
});

it('uses configured limits for the suggested and manually entered agent pin', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    Filament::setCurrentPanel('admin');
    app(Settings::class)->setMany([
        SettingKey::PinMinLength->value => 9,
        SettingKey::PinMaxLength->value => 10,
    ]);
    $client = Client::factory()->synced()->withPhone()->create();

    $page = Livewire::test(ViewClient::class, ['record' => $client->id])->mountAction(TestAction::make('setPin')->schemaComponent('pin_hash'));
    $suggestion = $page->get('mountedActions.0.data.pin');
    expect($suggestion)->toMatch('/^[0-9]{9}$/');

    $page->fillForm(['pin' => '12345678'])->callMountedAction()->assertHasActionErrors(['pin']);
    expect($client->fresh()->hasPin())->toBeFalse();

    $page->fillForm(['pin' => '0123456789'])->callMountedAction()->assertHasNoActionErrors();
    expect(Hash::check('0123456789', $client->fresh()->pin_hash))->toBeTrue();
});

it('shows a field error when an agent selects an already assigned pin', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    Filament::setCurrentPanel('admin');
    Client::factory()->withPin('123456')->create();
    $client = Client::factory()->synced()->withPhone()->create();

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->callAction(TestAction::make('setPin')->schemaComponent('pin_hash'), ['pin' => '123456'])
        ->assertHasActionErrors(['pin']);

    expect($client->fresh()->hasPin())->toBeFalse();
});

it('names the staff member for a PIN set and cleared on the panel, also while a client is signed in to the portal in the same browser', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $agent = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->synced()->withPhone()->create();
    // The same browser holds a portal session of a client (here: of the client being edited).
    $this->actingAs($client, 'client');
    $this->actingAs($agent, 'web');
    Filament::setCurrentPanel('admin');

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->callAction(TestAction::make('setPin')->schemaComponent('pin_hash'), ['pin' => '482915'])
        ->assertHasNoActionErrors();
    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->callAction(TestAction::make('clearPin')->schemaComponent('pin_hash'))
        ->assertHasNoActionErrors();

    $entries = AuditLog::query()->whereIn('event', ['client.pin_set', 'client.pin_cleared'])->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('actor_id')->unique()->all())->toBe([$agent->id])
        ->and($entries->pluck('subject_id')->unique()->all())->toBe([$client->id]);
});

it('records the actor a PIN change is given, whoever else is signed in', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $agent = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->synced()->withPhone()->create();
    // Signed in on both sides; the service is told who acts.
    $this->actingAs($client, 'client');
    $this->actingAs($agent, 'web');

    app(PinService::class)->setPin($client, '482915', $client);
    app(PinService::class)->clearPin($client, actor: $client);

    expect(AuditLog::query()->whereIn('event', ['client.pin_set', 'client.pin_cleared'])->pluck('actor_id')->unique()->all())->toBe([$client->id]);
});

it('allows a new PIN of four to ten digits, a short one only without unique PINs, and still checks a longer PIN set earlier', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $pins = app(PinService::class);
    expect(PinService::MIN_ALLOWED_LENGTH)->toBe(4)
        ->and(PinService::MAX_ALLOWED_LENGTH)->toBe(10)
        // Unique PINs on (the default): the floor stays six, whatever the stored minimum says.
        ->and(SettingRules::crossErrors([SettingKey::PinMinLength->value => 4, SettingKey::PinMaxLength->value => 10, SettingKey::PinUniqueRequired->value => true]))->toHaveKey(SettingKey::PinMinLength->value)
        ->and(SettingRules::crossErrors([SettingKey::PinMinLength->value => 4, SettingKey::PinMaxLength->value => 10, SettingKey::PinUniqueRequired->value => false]))->not->toHaveKey(SettingKey::PinMinLength->value);

    app(Settings::class)->setMany([SettingKey::PinMinLength->value => 4, SettingKey::PinMaxLength->value => 10]);
    expect($pins->minLength())->toBe(6);

    app(Settings::class)->set(SettingKey::PinUniqueRequired, false);
    expect($pins->minLength())->toBe(4);

    $client = Client::factory()->synced()->withPhone()->create();
    $pins->setPin($client, '4821');
    expect(Hash::check('4821', $client->fresh()->pin_hash))->toBeTrue()
        ->and(fn () => $pins->setPin($client, '482'))->toThrow(ValidationException::class)
        ->and(fn () => $pins->setPin($client, '12345678901'))->toThrow(ValidationException::class);

    // A twelve-digit PIN from before the change is still accepted when checked.
    $client->forceFill(['pin_hash' => Hash::make('012345678901'), 'pin_lookup' => $pins->lookupOf('012345678901')])->save();
    app(Settings::class)->set(SettingKey::PinAgentVerificationEnabled, true);
    expect($pins->verify($client, '012345678901', IdChannel::Manual, User::factory()->withRole(Role::Agent)->create())->status)->toBe(IdSessionStatus::Passed);

    $migration = require database_path('migrations/2026_11_18_100003_cap_the_longest_new_pin_at_ten_digits.php');
    DB::table('settings')->updateOrInsert(['key' => SettingKey::PinMaxLength->value], ['value' => '12']);
    $migration->up();
    expect(DB::table('settings')->where('key', SettingKey::PinMaxLength->value)->value('value'))->toBe('10');
});

it('keeps the PIN masked in the dialog and uncovers it only when the setting allows, with an audit entry', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $agent = User::factory()->withRole(Role::Agent)->create();
    $this->actingAs($agent);
    Filament::setCurrentPanel('admin');
    $client = Client::factory()->synced()->withPhone()->create();

    $page = Livewire::test(ViewClient::class, ['record' => $client->id])->mountAction(TestAction::make('setPin')->schemaComponent('pin_hash'));
    $field = fn () => $page->instance()->getSchemaComponent('mountedActionSchema0.pin');
    $shown = fn (Testable $page) => $page->instance()->getSchemaComponent('mountedActionSchema0.pin');
    expect($field()->isPassword())->toBeTrue()
        ->and($field()->getSuffixActions()['revealPin']->isVisible())->toBeFalse('off by default: no button to uncover it');

    app(Settings::class)->set(SettingKey::PinStaffMayReveal, true);
    $page = Livewire::test(ViewClient::class, ['record' => $client->id])->mountAction(TestAction::make('setPin')->schemaComponent('pin_hash'));
    expect($shown($page)->isPassword())->toBeTrue()
        ->and($shown($page)->getSuffixActions()['revealPin']->isVisible())->toBeTrue();

    $page->callFormComponentAction('pin', 'revealPin', formName: 'mountedActionSchema0');
    expect($shown($page)->isPassword())->toBeFalse();

    $entry = AuditLog::query()->where('event', 'client.pin_revealed')->sole();
    expect($entry->subject_id)->toBe($client->id)
        ->and($entry->actor_id)->toBe($agent->id)
        ->and(json_encode($entry->context))->not->toContain((string) $page->get('mountedActions.0.data.pin'));
});
