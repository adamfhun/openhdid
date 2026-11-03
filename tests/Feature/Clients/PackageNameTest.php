<?php

use App\Clients\PackageName;
use App\Models\Client;
use App\System\CheckStatus;
use App\System\HealthChecks;

/*
 * Package names compare case-, accent- and space-blind, but a character
 * with no ASCII spelling (CJK, ✓, ®) is kept: names that differ only in it
 * are different packages, not one.
 */

it('keeps characters that have no ASCII spelling, so package names differing only in them stay apart', function (): void {
    expect(PackageName::normalize('VIP 金'))->not->toBe(PackageName::normalize('VIP 银'))
        ->and(PackageName::normalize('Gold ✓'))->not->toBe(PackageName::normalize('Gold'))
        ->and(PackageName::normalize('Premium®'))->not->toBe(PackageName::normalize('Premium'))
        ->and(PackageName::normalize('金卡'))->toBe('金卡')
        ->and(PackageName::normalize('★★★'))->toBe('★★★');
});

it('still folds case, accents, compatibility forms, invisible characters and spaces as before', function (string $given, string $expected): void {
    expect(PackageName::normalize($given))->toBe($expected);
})->with([
    ['  PRÉMIUM  ', 'premium'],
    ['Ｐｒｅｍｉｕｍ', 'premium'],
    ['Straße', 'strasse'],
    ["Gold\u{200B}", 'gold'],
    ["Gold\tPlus", 'gold plus'],
    ["Gold\nPlus", 'gold plus'],
    ["Gold\u{200B}Plus", 'gold plus'],
    ["Gold\u{00AD}en", 'golden'],
]);

it('names an entry that has no comparable spelling', function (): void {
    expect(PackageName::problem("\u{200B}"))->not->toBeNull()
        ->and(PackageName::problem('Premium'))->toBeNull()
        ->and(PackageName::same("Gold\tPlus", 'Gold Plus'))->toBeTrue();
});

it('lists on the status page the package names in use that compare differently since 1.15.1', function (): void {
    Client::factory()->synced()->create(['implicit_package' => 'VIP 金', 'explicit_package' => null]);
    Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null]);

    $check = app(HealthChecks::class)->packageNames();

    expect($check->status)->toBe(CheckStatus::Info)
        ->and($check->detail)->toContain('VIP 金')->not->toContain('Premium')
        ->and(HealthChecks::hint('package_names'))->not->toBeNull();
});
