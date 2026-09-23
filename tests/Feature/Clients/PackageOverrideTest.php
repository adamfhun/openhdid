<?php

use App\Clients\ClientTiers;
use App\Clients\PackageOverrides;
use App\Enums\ClientTier;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Sync\AccountProvisioner;
use Illuminate\Validation\ValidationException;

it('counts an active override as the explicit package until it ends', function (): void {
    $client = Client::factory()->synced()->create(['implicit_package' => null, 'explicit_package' => null]);
    $overrides = app(PackageOverrides::class);
    $admin = User::factory()->create();

    expect(app(ClientTiers::class)->tierFor($client))->toBeNull();

    $overrides->set($client, 'Premium', 'Hibajegy 123', now()->addDays(10), $admin);
    expect(app(ClientTiers::class)->tierFor($client->fresh()))->toBe(ClientTier::Premium)
        ->and($client->fresh()->explicit_package)->toBeNull('the directory value is untouched');

    $this->travel(11)->days();
    expect($client->fresh()->hasActivePackageOverride())->toBeFalse()
        ->and(app(ClientTiers::class)->tierFor($client->fresh()))->toBeNull();

    expect($overrides->endExpired())->toBe(1)
        ->and($client->fresh()->package_override)->toBeNull()
        ->and(AuditLog::query()->where('event', 'client.package_override_ended')->count())->toBe(1);
});

it('validates the override and lets it be ended by hand', function (): void {
    $client = Client::factory()->synced()->unentitled()->create();
    $overrides = app(PackageOverrides::class);

    expect(fn () => $overrides->set($client, 'Nonexistent', 'x', now()->addDay()))->toThrow(ValidationException::class);
    expect(fn () => $overrides->set($client, 'Basic', '  ', now()->addDay()))->toThrow(ValidationException::class);
    expect(fn () => $overrides->set($client, 'Basic', 'ok', now()->subDay()))->toThrow(ValidationException::class);

    $overrides->set($client, 'Basic', 'ok', now()->addDay());
    $overrides->end($client->fresh(), 'manual');
    expect($client->fresh()->package_override)->toBeNull();
});

it('keeps the override through a sync and ends it once the directory carries the same value', function (): void {
    $client = Client::factory()->synced()->create(['implicit_package' => 'Basic', 'explicit_package' => null]);
    $record = $client->externalRecord;
    $overrides = app(PackageOverrides::class);
    $overrides->set($client, 'Premium', 'ok', now()->addDays(5));

    $record->update(['implicit_package' => 'Basic', 'explicit_package' => null]);
    app(AccountProvisioner::class)->syncAccount($record);
    expect($client->fresh()->hasActivePackageOverride())->toBeTrue('a sync that does not change the value leaves the override alone');

    $record->update(['explicit_package' => 'Premium']);
    app(AccountProvisioner::class)->syncAccount($record);
    expect($client->fresh()->package_override)->toBeNull('the directory caught up, the override is moot')
        ->and($client->fresh()->explicit_package)->toBe('Premium');
});

it('never lets an override lower the level the directory gives', function (): void {
    $overrides = app(PackageOverrides::class);
    $premium = Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null]);
    $standard = Client::factory()->synced()->create(['implicit_package' => 'Basic', 'explicit_package' => null]);

    expect(fn () => $overrides->set($premium, 'Basic', 'downgrade', now()->addDay()))->toThrow(ValidationException::class, 'only raise');
    expect(ClientResource::upgradeOptions($premium))->toBe([])
        ->and(array_keys(ClientResource::upgradeOptions($standard)))->toBe(['Premium']);

    $overrides->set($standard, 'Premium', 'upgrade', now()->addDay());
    expect(app(ClientTiers::class)->tierFor($standard->fresh()))->toBe(ClientTier::Premium);

    // the directory value outranks a weaker override that somehow remained
    $standard->forceFill(['package_override' => 'Basic'])->save();
    $standard->fresh()->update(['explicit_package' => 'Premium']);
    expect(app(ClientTiers::class)->tierFor($standard->fresh()))->toBe(ClientTier::Premium);
});
