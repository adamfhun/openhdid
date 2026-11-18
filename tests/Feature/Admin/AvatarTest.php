<?php

use App\Auth\Role;
use App\Filament\InitialsAvatarProvider;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The avatar in the user menu: Filament's default loads it from ui-avatars.com
 * with the staff member's full name; the panel draws the initials itself.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * The SVG an avatar URL carries.
 */
function avatarSvg(string $url): string
{
    expect($url)->toStartWith('data:image/svg+xml;base64,');

    return (string) base64_decode(substr($url, strlen('data:image/svg+xml;base64,')), true);
}

it('draws the user menu avatar from the initials without loading anything from another host', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Kovács Éva']);

    $html = $this->actingAs($admin)->get('/admin')->assertOk()->getContent();

    expect($html)->not->toContain('ui-avatars.com')
        ->and($html)->toContain('data:image/svg+xml;base64,')
        ->and(avatarSvg(app(InitialsAvatarProvider::class)->get($admin)))->toContain('>KÉ</text>');
});

it('takes at most two initials and skips leading punctuation, so no markup of the name gets in', function (): void {
    $provider = app(InitialsAvatarProvider::class);

    expect(avatarSvg($provider->get(User::factory()->make(['name' => '[SYSTEM] admin  of things']))))->toContain('>SA</text>')
        ->and(avatarSvg($provider->get(User::factory()->make(['name' => '<b>x</b> & "y"']))))->toContain('>BY</text>')->not->toContain('<b>')
        ->and(avatarSvg($provider->get(User::factory()->make(['name' => XSS_SURVIVOR_CANARY]))))->not->toContain('<a ', '<img', 'hdid-canary');
});
