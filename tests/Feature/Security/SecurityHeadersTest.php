<?php

use App\Auth\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

it('sends hardening headers on every response', function (): void {
    config()->set('hdid.security.csp_enabled', true);

    $response = $this->get('/login');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")->toContain("object-src 'none'")
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()');
});

it('admits the documentation renderer origin only on the api documentation pages', function (): void {
    config()->set('hdid.security.csp_enabled', true);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    $page = $this->get('/docs/ivr')->assertOk();
    $docs = $page->headers->get('Content-Security-Policy');
    $panel = $this->get('/admin')->headers->get('Content-Security-Policy');

    // Only the one pinned package path, not the whole CDN: every other package
    // there could otherwise run in an administrator's session.
    preg_match_all('#https://unpkg\.com/[^"\' ]+#', $page->getContent(), $assets);
    preg_match('/script-src ([^;]*)/', $docs, $scripts);
    $sources = array_values(array_filter(explode(' ', $scripts[1]), fn (string $source): bool => str_contains($source, 'unpkg.com')));

    expect($assets[0])->not->toBeEmpty()
        ->and($sources)->toHaveCount(1)
        ->and($sources[0])->toEndWith('/')->not->toBe('https://unpkg.com/');
    foreach ($assets[0] as $asset) {
        expect($asset)->toStartWith($sources[0], 'the policy covers every file the page loads; update the pinned path after a Scramble upgrade');
    }
    expect($docs)->toMatch('#style-src [^;]*'.preg_quote($sources[0], '#').'#')
        ->toMatch('#font-src [^;]*'.preg_quote($sources[0], '#').'#')
        ->and($panel)->not->toContain('unpkg.com');
});

it('adds hsts only over https', function (): void {
    $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('never redirects to a foreign host from the language or the call-view switch', function (): void {
    $this->withHeader('Referer', 'https://evil.example/phish')
        ->get('/locale/en')
        ->assertRedirect('/');

    $this->withHeader('Referer', url('/premium/login'))
        ->get('/locale/hu')
        ->assertRedirect(url('/premium/login'));

    $this->seed(RolesAndPermissionsSeeder::class);
    $agent = User::factory()->withRole(Role::Agent)->create();

    $this->actingAs($agent)
        ->withHeader('Referer', 'https://evil.example/phish')
        ->post(route('tier.switch', 'premium'))
        ->assertRedirect('/admin');
});
