<?php

use App\Auth\Role;
use App\CallCenter\CallCenterService;
use App\Clients\ClientTiers;
use App\Enums\CallStatus;
use App\Enums\ClientTier;
use App\Enums\PhoneNumberSource;
use App\Filament\Admin\Pages\ManageSettings;
use App\Filament\Admin\Widgets\OngoingCallsWidget;
use App\Models\Call;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('derives the call level from the queue, then the caller, then the default', function (): void {
    app(Settings::class)->set(SettingKey::CallsQueueTiers, ['Normal-sor' => 'standard', 'VIP' => 'Premium']);
    $tiers = app(ClientTiers::class);

    expect($tiers->tierForCall('normal-sor'))->toBe(ClientTier::Standard)
        ->and($tiers->tierForCall('vip'))->toBe(ClientTier::Premium)
        ->and($tiers->tierForCall('unknown', Client::factory()->standard()->make()))->toBe(ClientTier::Standard)
        ->and($tiers->tierForCall(null))->toBe(ClientTier::Premium, 'pilot default');

    app(Settings::class)->set(SettingKey::CallsDefaultTier, 'standard');
    expect($tiers->tierForCall('whatever'))->toBe(ClientTier::Standard);
});

it('stores the level on inbound calls', function (): void {
    app(Settings::class)->set(SettingKey::CallsQueueTiers, ['normal' => 'standard']);
    $client = Client::factory()->synced()->standard()->create();
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin]);
    $service = app(CallCenterService::class);

    expect($service->upsertCall('c-1', null, CallStatus::Ringing, 'premium-line')->tier)->toBe(ClientTier::Premium)
        ->and($service->upsertCall('c-2', null, CallStatus::Ringing, 'normal')->tier)->toBe(ClientTier::Standard)
        ->and($service->upsertCall('c-3', '+36301234567', CallStatus::Ringing, 'unmapped')->tier)->toBe(ClientTier::Standard, 'known standard caller');
});

it('lets a user narrow the view to one of the levels they handle', function (): void {
    $user = User::factory()->create(['handles_tiers' => ['premium', 'standard']]);

    expect($user->visibleTiers())->toBe(ClientTier::cases())->and($user->activeTier())->toBeNull();

    $user->switchTier(ClientTier::Standard);
    expect($user->fresh()->visibleTiers())->toBe([ClientTier::Standard])->and($user->fresh()->activeTier())->toBe(ClientTier::Standard);

    $premiumOnly = User::factory()->create(['handles_tiers' => ['premium']]);
    $premiumOnly->switchTier(ClientTier::Standard);
    expect($premiumOnly->fresh()->visibleTiers())->toBe([ClientTier::Premium], 'cannot switch to a level they do not handle')
        ->and($premiumOnly->activeTier())->toBeNull();
});

it('shows agents only the calls of the level they are viewing', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $agent = User::factory()->withRole(Role::Agent)->create(['handles_tiers' => ['premium', 'standard'], 'active_tier' => 'standard']);
    $this->actingAs($agent);

    $premium = Call::factory()->create(['tier' => 'premium']);
    $standard = Call::factory()->create(['tier' => 'standard']);

    Livewire::test(OngoingCallsWidget::class)
        ->assertCanSeeTableRecords([$standard])
        ->assertCanNotSeeTableRecords([$premium]);

    $this->post(route('tier.switch', 'all'))->assertRedirect();
    expect($agent->fresh()->active_tier)->toBeNull();

    Livewire::test(OngoingCallsWidget::class)->assertCanSeeTableRecords([$premium, $standard]);
});

it('hides a level that has no packages configured', function (): void {
    app(Settings::class)->set(SettingKey::PackagesStandard, []);
    $user = User::factory()->create(['handles_tiers' => ['premium', 'standard']]);

    expect(app(ClientTiers::class)->activeTiers())->toBe([ClientTier::Premium])
        ->and($user->handledTiers())->toBe([ClientTier::Premium])
        ->and($user->activeTier())->toBeNull();

    $user->switchTier(ClientTier::Standard);
    expect($user->fresh()->visibleTiers())->toBe([ClientTier::Premium]);
});

it('shows the current level in the top bar and lets the agent switch there', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $agent = User::factory()->withRole(Role::Agent)->create(['handles_tiers' => ['premium', 'standard'], 'active_tier' => 'premium']);

    $this->actingAs($agent)->get('/admin')->assertOk()
        ->assertSee(__('You are viewing'))
        ->assertSee(route('tier.switch', 'standard'), escape: false)
        ->assertSee('hdid-tier-pill-premium', escape: false);

    app(Settings::class)->set(SettingKey::PackagesStandard, []);
    $this->actingAs($agent)->get('/admin')->assertOk()
        ->assertDontSee(route('tier.switch', 'standard'), escape: false)
        ->assertSee('hdid-tier-pill-premium', escape: false);
});

it('edits the queue-to-level mapping with pickers and stores it as a map', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin);
    Call::factory()->create(['queue' => 'Ügyfélszolgálat']);
    app(Settings::class)->set(SettingKey::CallsQueueTiers, ['Normal-sor' => 'standard']);

    expect(ManageSettings::knownQueues())->toHaveKeys(['Ügyfélszolgálat', 'Normal-sor']);

    Livewire::test(ManageSettings::class)
        ->fillForm(['calls__queue_tiers' => [['queue' => 'Normal-sor', 'tier' => 'standard'], ['queue' => 'VIP', 'tier' => 'premium']], 'calls__default_tier' => 'standard'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(Settings::class)->array(SettingKey::CallsQueueTiers))->toBe(['Normal-sor' => 'standard', 'VIP' => 'premium'])
        ->and(app(Settings::class)->string(SettingKey::CallsDefaultTier))->toBe('standard');
});
