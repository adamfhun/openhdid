<?php

use App\Auth\Role;
use App\Clients\ClientLinks;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Cache::forget('clients:unlinked-premium-count');
});

it('removes a muted client from the warning, the tab, the badge and the filter, and keeps the reason visible', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $muted = Client::factory()->synced()->create(['name' => 'Kivétel Kázmér', 'implicit_package' => 'Basic', 'explicit_package' => 'Premium']);
    $waiting = Client::factory()->synced()->create(['name' => 'Váró Vilmos', 'implicit_package' => 'Basic', 'explicit_package' => 'Premium']);

    expect(ClientResource::unlinkedPremiumCount())->toBe(2);

    app(ClientLinks::class)->muteWarning($muted, 'Ticket 4711: önálló prémium szerződés', null, $admin);
    $muted->refresh();

    expect($muted->hasMutedLinkWarning())->toBeTrue()
        ->and(ClientResource::linkWarning($muted))->toBeNull()
        ->and(ClientResource::linkWarningMutedNote($muted))->toContain('Ticket 4711', $admin->name)
        ->and(ClientResource::linkWarning($waiting))->not->toBeNull()
        ->and(ClientResource::unlinkedPremiumCount())->toBe(1)
        ->and(ClientResource::getNavigationBadge())->toBe('1')
        ->and(Client::query()->linkWarningMuted()->pluck('id')->all())->toBe([$muted->id])
        ->and(AuditLog::query()->where('event', 'client.link_warning_muted')->where('subject_id', $muted->id)->count())->toBe(1);

    $this->actingAs($admin);

    Livewire::test(ListClients::class, ['activeTab' => 'unlinked'])
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$muted]);

    Livewire::test(ListClients::class)
        ->filterTable('link_warning_muted', true)
        ->assertCanSeeTableRecords([$muted])
        ->assertCanNotSeeTableRecords([$waiting]);
});

it('refuses a short reason and a past end date', function (): void {
    $client = Client::factory()->synced()->create(['implicit_package' => 'Basic', 'explicit_package' => 'Premium']);

    expect(fn () => app(ClientLinks::class)->muteWarning($client, 'rövid'))->toThrow(ValidationException::class)
        ->and(fn () => app(ClientLinks::class)->muteWarning($client, 'elég hosszú indoklás', now()->subDay()))->toThrow(ValidationException::class)
        ->and($client->refresh()->hasMutedLinkWarning())->toBeFalse();
});

it('lets an expiry date bring the warning back and ends the mute when the client gets linked', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $sponsor = Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null]);
    $expiring = Client::factory()->synced()->create(['implicit_package' => 'Basic', 'explicit_package' => 'Premium']);
    $linkedLater = Client::factory()->synced()->create(['implicit_package' => 'Basic', 'explicit_package' => 'Premium']);

    app(ClientLinks::class)->muteWarning($expiring, 'Átmeneti kivétel a migráció idejére', now()->addDays(3), $admin);
    app(ClientLinks::class)->muteWarning($linkedLater, 'Kivétel, amíg a főügyfél megérkezik', null, $admin);

    expect(ClientResource::unlinkedPremiumCount())->toBe(0);

    $this->travel(4)->days();
    $expiring->refresh();

    expect($expiring->hasMutedLinkWarning())->toBeFalse()
        ->and(ClientResource::linkWarning($expiring))->not->toBeNull()
        ->and(ClientResource::scopeUnlinkedPremium(Client::query())->pluck('id')->all())->toBe([$expiring->id]);

    app(ClientLinks::class)->link($sponsor, $linkedLater, $admin);
    $linkedLater->refresh();

    expect($linkedLater->link_warning_muted_at)->toBeNull()
        ->and(AuditLog::query()->where('event', 'client.link_warning_unmuted')->where('subject_id', $linkedLater->id)->value('context'))->toMatchArray(['how' => 'linked']);
});

it('offers mute and unmute on the client page only to clients.manage, and only in the matching state', function (): void {
    $client = Client::factory()->synced()->create(['implicit_package' => 'Basic', 'explicit_package' => 'Premium']);

    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertActionHidden('muteLinkWarning')
        ->assertActionHidden('unmuteLinkWarning');

    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin);

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertActionVisible('muteLinkWarning')
        ->assertActionHidden('unmuteLinkWarning')
        ->callAction('muteLinkWarning', ['reason' => 'Ticket 4711: önálló prémium szerződés'])
        ->assertHasNoActionErrors();

    expect($client->refresh()->hasMutedLinkWarning())->toBeTrue()
        ->and($client->linkWarningMutedBy?->id)->toBe($admin->id);

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertActionHidden('muteLinkWarning')
        ->assertActionVisible('unmuteLinkWarning')
        ->callAction('unmuteLinkWarning');

    expect($client->refresh()->hasMutedLinkWarning())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'client.link_warning_unmuted')->where('subject_id', $client->id)->count())->toBe(1);
});
