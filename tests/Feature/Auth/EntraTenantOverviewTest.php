<?php

use App\Auth\Oidc\EntraDomainState;
use App\Auth\Oidc\EntraTenantOverview;
use App\Models\ExternalRecord;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\System\CheckStatus;
use App\System\HealthChecks;

const OVERVIEW_TRUSTED = '11111111-1111-4111-8111-111111111111';

const OVERVIEW_FILTERED = '22222222-2222-4222-8222-222222222222';

/**
 * The client portal's Entra provider with this tenant list, and the two
 * EMD domain lists the sync classifies by.
 *
 * @param  list<string>  $staffDomains
 * @param  list<string>  $clientDomains
 */
function overviewSetup(?string $list, array $staffDomains = ['staff.hu'], array $clientDomains = ['clients.hu', 'empty.hu', 'other.hu'], ?string $single = null): void
{
    config()->set('hdid.oidc.client.entra', ['tenant' => $single, 'tenants' => $list, 'client_id' => 'client-app', 'client_secret' => 's']);
    app(Settings::class)->set(SettingKey::SyncUserDomains, $staffDomains);
    app(Settings::class)->set(SettingKey::SyncClientDomains, $clientDomains);
    app(Settings::class)->set(SettingKey::ClientSsoEntraEnabled, true);
}

/**
 * @param  array<string, mixed>  $overview
 * @return array<string, array{domain: string, state: EntraDomainState, records: int, color: string, text: string}>
 */
function overviewDomains(array $overview, int $tenant): array
{
    return collect($overview['tenants'][$tenant]['domains'])->keyBy('domain')->all();
}

it('tells for every tenant domain whether the EMD sync brings clients of it, counting the records', function (): void {
    overviewSetup(OVERVIEW_TRUSTED.','.OVERVIEW_FILTERED.':Clients.hu|empty.hu|staff.hu|stray.hu');
    ExternalRecord::factory()->client()->count(2)->sequence(['email' => 'a@clients.hu'], ['email' => 'b@CLIENTS.hu'])->create();
    // Neither a missing record nor a staff row of the same domain counts as a client record.
    ExternalRecord::factory()->client()->missing()->create(['email' => 'gone@clients.hu']);
    ExternalRecord::factory()->user()->create(['email' => 'boss@clients.hu']);
    ExternalRecord::factory()->user()->create(['email' => 'x@staff.hu']);

    $overview = app(EntraTenantOverview::class)->clients();

    expect($overview['enabled'])->toBeTrue()
        ->and($overview['emd_required'])->toBeTrue()
        ->and($overview['problem'])->toBeNull()
        ->and(array_column($overview['tenants'], 'id'))->toBe([OVERVIEW_TRUSTED, OVERVIEW_FILTERED])
        ->and($overview['tenants'][0]['trusted'])->toBeTrue()
        ->and($overview['tenants'][0]['domains'])->toBe([])
        ->and($overview['tenants'][1]['trusted'])->toBeFalse();

    $domains = overviewDomains($overview, 1);
    expect(array_keys($domains))->toBe(['clients.hu', 'empty.hu', 'staff.hu', 'stray.hu'])
        ->and($domains['clients.hu'])->toMatchArray(['state' => EntraDomainState::ClientDomain, 'records' => 2, 'color' => 'success'])
        ->and($domains['clients.hu']['text'])->toContain('2 record(s)')
        ->and($domains['empty.hu'])->toMatchArray(['state' => EntraDomainState::ClientDomainWithoutRecords, 'records' => 0, 'color' => 'warning'])
        ->and($domains['staff.hu'])->toMatchArray(['state' => EntraDomainState::StaffDomain, 'color' => 'danger'])
        ->and($domains['staff.hu']['text'])->toContain('filed as staff')
        ->and($domains['stray.hu'])->toMatchArray(['state' => EntraDomainState::Unlisted, 'color' => 'danger'])
        ->and($domains['stray.hu']['text'])->toContain('CLIENT_LOGIN_REQUIRE_EMD');

    // A trusted tenant reaches every client domain: nothing to list the other way round.
    expect($overview['unreachable'])->toBe([]);
});

it('marks an unlisted domain yellow when clients sign in without an EMD record', function (): void {
    config()->set('hdid.client_login.require_emd', false);
    overviewSetup(OVERVIEW_FILTERED.':stray.hu|staff.hu');

    $overview = app(EntraTenantOverview::class)->clients();
    $domains = overviewDomains($overview, 0);

    expect($overview['emd_required'])->toBeFalse()
        ->and($domains['stray.hu'])->toMatchArray(['state' => EntraDomainState::Unlisted, 'color' => 'warning'])
        ->and($domains['stray.hu']['text'])->toContain('created in the panel')
        // Filed as staff is a slip either way.
        ->and($domains['staff.hu']['color'])->toBe('danger');
});

it('files a domain on both EMD lists as staff, as the sync does', function (): void {
    app(Settings::class)->set(SettingKey::SyncUniqueDomains, false);
    overviewSetup(OVERVIEW_FILTERED.':both.hu', staffDomains: ['both.hu'], clientDomains: ['both.hu']);

    expect(overviewDomains(app(EntraTenantOverview::class)->clients(), 0)['both.hu']['state'])->toBe(EntraDomainState::StaffDomain);
});

it('lists the EMD client domains no tenant allows when every tenant filters by domain', function (): void {
    app(Settings::class)->set(SettingKey::SyncUniqueDomains, false);
    overviewSetup(OVERVIEW_TRUSTED.':clients.hu,'.OVERVIEW_FILTERED.':empty.hu|stray.hu', staffDomains: ['staff.hu', 'shared.hu'], clientDomains: ['clients.hu', 'empty.hu', 'other.hu', 'third.hu', 'shared.hu']);
    ExternalRecord::factory()->client()->count(3)->sequence(fn ($sequence) => ['email' => "c{$sequence->index}@other.hu"])->create();

    $unreachable = app(EntraTenantOverview::class)->clients()['unreachable'];

    // A domain the staff list takes is not a client domain, so it is not listed.
    expect($unreachable)->toBe([['domain' => 'other.hu', 'records' => 3], ['domain' => 'third.hu', 'records' => 0]]);
});

it('reports a broken tenant list and an empty one as they are', function (): void {
    overviewSetup('organizations,'.OVERVIEW_FILTERED.':clients.hu');
    $broken = app(EntraTenantOverview::class)->clients();

    expect($broken['problem'])->toContain('organizations')
        ->and(array_column($broken['tenants'], 'id'))->toBe([OVERVIEW_FILTERED]);

    overviewSetup(null);
    app(Settings::class)->set(SettingKey::ClientSsoEntraEnabled, false);
    $empty = app(EntraTenantOverview::class)->clients();

    expect($empty['tenants'])->toBe([])
        ->and($empty['problem'])->toBeNull()
        ->and($empty['unreachable'])->toBe([])
        ->and($empty['enabled'])->toBeFalse();
});

it('keeps the sign-in tile green while every tenant domain is a client domain, and yellow otherwise', function (): void {
    overviewSetup(OVERVIEW_TRUSTED.','.OVERVIEW_FILTERED.':clients.hu|empty.hu');
    expect(app(HealthChecks::class)->oidc()->status)->toBe(CheckStatus::Ok);

    overviewSetup(OVERVIEW_TRUSTED.','.OVERVIEW_FILTERED.':clients.hu|staff.hu|stray.hu');
    $check = app(HealthChecks::class)->oidc();

    expect($check->status)->toBe(CheckStatus::Warn)
        ->and($check->detail)->toContain('staff.hu (staff domain)')
        ->toContain('stray.hu (on no EMD domain list)')
        ->not->toContain('clients.hu (');

    // Switched off, the list does not act: the tile does not nag about it.
    app(Settings::class)->set(SettingKey::ClientSsoEntraEnabled, false);
    expect(app(HealthChecks::class)->oidc()->status)->toBe(CheckStatus::Ok);
});
