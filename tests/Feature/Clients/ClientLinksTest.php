<?php

use App\Clients\ClientLinks;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Models\Client;
use App\Models\ClientLink;
use App\Sync\AccountProvisioner;
use Illuminate\Validation\ValidationException;

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
