<?php

use App\Auth\AccountLogin;
use App\Auth\LoginRejectedException;
use App\Clients\ClientTiers;
use App\Enums\ClientTier;
use App\Enums\PrincipalType;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Identification\ClientSearch;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;

it('derives the tier from either package, ignoring case and whitespace', function (): void {
    app(Settings::class)->set(SettingKey::PackagesPremium, [' Gold ', 'Premium+']);
    app(Settings::class)->set(SettingKey::PackagesStandard, ['basic']);
    $tiers = app(ClientTiers::class);

    expect($tiers->tierFor(Client::factory()->make(['implicit_package' => 'gold', 'explicit_package' => null])))->toBe(ClientTier::Premium)
        ->and($tiers->tierFor(Client::factory()->make(['implicit_package' => 'Basic', 'explicit_package' => 'PREMIUM+'])))->toBe(ClientTier::Premium)
        ->and($tiers->tierFor(Client::factory()->make(['implicit_package' => 'BASIC ', 'explicit_package' => null])))->toBe(ClientTier::Standard)
        ->and($tiers->tierFor(Client::factory()->make(['implicit_package' => 'Silver', 'explicit_package' => null])))->toBeNull()
        ->and($tiers->tierFor(Client::factory()->make(['implicit_package' => null, 'explicit_package' => null])))->toBeNull();
});

it('rejects logins of clients without a listed package', function (): void {
    $login = app(AccountLogin::class);
    $entitled = Client::factory()->synced()->standard()->create();
    $blocked = Client::factory()->synced()->unentitled()->create();

    expect($login->assertEligible($entitled, PrincipalType::Client, 'test')->is($entitled))->toBeTrue();
    expect(fn () => $login->assertEligible($blocked, PrincipalType::Client, 'test'))->toThrow(LoginRejectedException::class);
});

it('returns the support contact of the tier', function (): void {
    app(Settings::class)->set(SettingKey::SupportPremiumPhone, '+36 1 111');
    app(Settings::class)->set(SettingKey::SupportStandardPhone, '+36 1 222');

    expect(app(ClientTiers::class)->supportFor(ClientTier::Premium)['phone'])->toBe('+36 1 111')
        ->and(app(ClientTiers::class)->supportFor(ClientTier::Standard)['phone'])->toBe('+36 1 222');
});

it('links factory accounts to a directory record with the same e-mail', function (): void {
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $user = User::factory()->synced()->create(['email' => 'u@x.hu']);

    expect($client->externalRecord->email)->toBe('c@x.hu')
        ->and($client->externalRecord->email_domain)->toBe('x.hu')
        ->and($user->externalRecord->email)->toBe('u@x.hu');
});

it('rejects an account whose directory record carries another e-mail', function (): void {
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $client->externalRecord->update(['email' => 'someone-else@x.hu']);

    expect(fn () => app(AccountLogin::class)->assertEligible($client->fresh(), PrincipalType::Client, 'test'))
        ->toThrow(LoginRejectedException::class, 'does not match');
});

it('shows only relevant clients by default and keeps the rest reachable', function (): void {
    $premium = Client::factory()->synced()->create(['name' => 'Prémium Pál']);
    $closed = Client::factory()->synced()->unentitled()->closed()->create(['name' => 'Lezárt Lajos']);
    $withPin = Client::factory()->synced()->unentitled()->withPin('123456')->create(['name' => 'Pines Piri']);
    $untouched = Client::factory()->synced()->unentitled()->create(['name' => 'Érintetlen Ernő']);

    $relevant = ClientResource::relevantIds()->pluck('id')->all();

    expect($relevant)->toContain($premium->id, $closed->id, $withPin->id)
        ->and($relevant)->not->toContain($untouched->id)
        ->and(app(Settings::class)->bool(SettingKey::ClientsListRelevantOnly))->toBeTrue()
        ->and(app(ClientSearch::class)->search('Ernő')->pluck('id')->all())->toBe([$untouched->id], 'search still finds everyone');
});
