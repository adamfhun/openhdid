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
