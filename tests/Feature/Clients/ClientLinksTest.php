<?php

use App\Auth\Role;
use App\Clients\ClientLinks;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Clients\RelationManagers\LinkedClientsRelationManager;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientLink;
use App\Models\User;
use App\Sync\AccountProvisioner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('links explicit-premium clients to an implicit-premium sponsor only', function (): void {
    $links = app(ClientLinks::class);
    $sponsor = Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null]);
    $dependent = Client::factory()->synced()->create(['implicit_package' => 'Basic', 'explicit_package' => 'Premium']);
    $another = Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null]);

    $link = $links->link($sponsor, $dependent);
    expect($link->isActive())->toBeTrue()
        ->and($dependent->fresh()->sponsor()?->id)->toBe($sponsor->id)
        ->and($sponsor->activeLinks()->count())->toBe(1);

    expect(fn () => $links->link($sponsor, $dependent))->toThrow(ValidationException::class, 'already linked');
    expect(fn () => $links->link($dependent, $another))->toThrow(ValidationException::class, 'implicit premium package can have');
    expect(fn () => $links->link($sponsor, $another))->toThrow(ValidationException::class, 'does not need a link');
    expect(fn () => $links->link($sponsor, $sponsor))->toThrow(ValidationException::class, 'itself');

    $links->unlink($link);
    expect($link->fresh()->ended_at)->not->toBeNull()->and($dependent->fresh()->sponsor())->toBeNull();
});

it('refuses to link an open client to a closed sponsor', function (): void {
    $links = app(ClientLinks::class);
    $closedSponsor = Client::factory()->synced()->closed('admin')->create(['implicit_package' => 'Premium', 'explicit_package' => null]);
    $client = Client::factory()->synced()->create(['implicit_package' => null, 'explicit_package' => 'Premium']);

    expect($links->canSponsor($closedSponsor))->toBeFalse('a closed client is not an open, implicit-premium sponsor');
    expect(fn () => $links->link($closedSponsor, $client))->toThrow(ValidationException::class, 'Reopen it first');

    expect(ClientLink::query()->count())->toBe(0, 'no link row may be created under a closed sponsor')
        ->and($client->fresh()->isClosed())->toBeFalse('the client must not be silently closed either')
        ->and($client->fresh()->sponsor())->toBeNull()
        ->and(AuditLog::query()->where('event', 'client.linked')->count())->toBe(0);
});

it('keeps the link action visible but disabled with a reason on a closed sponsor in the panel', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');

    $openSponsor = Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null]);
    $closedSponsor = Client::factory()->synced()->closed('admin')->create(['implicit_package' => 'Premium', 'explicit_package' => null]);

    Livewire::test(LinkedClientsRelationManager::class, ['ownerRecord' => $openSponsor, 'pageClass' => ViewClient::class])
        ->assertActionVisible(TestAction::make('link')->table())
        ->assertActionEnabled(TestAction::make('link')->table());

    // The button stays visible for the permission holder but is disabled with the reason on a closed sponsor.
    Livewire::test(LinkedClientsRelationManager::class, ['ownerRecord' => $closedSponsor, 'pageClass' => ViewClient::class])
        ->assertActionVisible(TestAction::make('link')->table())
        ->assertActionDisabled(TestAction::make('link')->table());

    expect(LinkedClientsRelationManager::linkBlocker($closedSponsor, app(ClientLinks::class)))->toContain('reopen it first')
        ->and(LinkedClientsRelationManager::linkBlocker($openSponsor, app(ClientLinks::class)))->toBeNull();
});

it('closes and reopens linked clients together with their sponsor', function (): void {
    $links = app(ClientLinks::class);
    $sponsor = Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null]);
    $dependent = Client::factory()->synced()->create(['implicit_package' => null, 'explicit_package' => 'Premium']);
    $manuallyClosed = Client::factory()->synced()->closed('admin')->create(['implicit_package' => null, 'explicit_package' => 'Premium']);
    $links->link($sponsor, $dependent);
    $links->link($sponsor, $manuallyClosed);

    $sponsor->close(AccountProvisioner::CLOSE_REASON_MISSING);

    expect($dependent->fresh()->isClosed())->toBeTrue()
        ->and($dependent->fresh()->closed_reason)->toBe(ClientLinks::CLOSE_REASON_SPONSOR)
        ->and($manuallyClosed->fresh()->closed_reason)->toBe('admin', 'already closed accounts keep their reason')
        ->and(ClientLink::query()->active()->count())->toBe(2, 'links survive the closure');

    $sponsor->reopen();

    expect($dependent->fresh()->isClosed())->toBeFalse()
        ->and($manuallyClosed->fresh()->isClosed())->toBeTrue('closed for another reason stays closed');
});

it('stores an internal note on the client', function (): void {
    $client = Client::factory()->create();
    $client->update(['notes' => "Hallássérült, lassan beszéljen.\nA fia intézi az ügyeit."]);

    expect($client->fresh()->notes)->toContain('Hallássérült');
});

it('offers only configured package names on the client form and warns before closing a sponsor', function (): void {
    $options = ClientResource::packageOptions('Legacy');
    expect(array_keys($options))->toBe(['Premium', 'Basic', 'Legacy']);

    $sponsor = Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null, 'name' => 'Fő Ügyfél']);
    $dependent = Client::factory()->synced()->create(['implicit_package' => null, 'explicit_package' => 'Premium', 'name' => 'Kapcsolt Kata']);
    app(ClientLinks::class)->link($sponsor, $dependent);

    expect(ClientResource::closeWarning($sponsor))->toContain('Kapcsolt Kata')->toContain('1')
        ->and(ClientResource::closeWarning($dependent))->not->toContain('Kapcsolt Kata')
        ->and(ClientResource::nameMarks($sponsor->fresh()))->toContain('Premium')
        ->and(ClientResource::nameMarks(Client::factory()->closed()->create()))->toContain('Account closed');
});
