<?php

use App\Auth\Role;
use App\Models\User;
use App\Support\SafeRedirect;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\URL;

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

it('sends the health result uncached and with hsts over https, like every other response', function (): void {
    $response = $this->getJson('https://localhost/health');

    $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
        ->assertHeader('X-Frame-Options', 'DENY');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) file_get_contents(app_path('Http/Controllers/HealthController.php')))->not->toContain('->header(');
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

it('never redirects to an address the browser reads differently from the server', function (): void {
    $host = (string) parse_url(url('/'), PHP_URL_HOST);

    // parse_url reads "evil.example\" as the user name and the application as the
    // host; a browser treats the backslash as a slash and goes to evil.example.
    $this->withHeader('Referer', 'https://evil.example\\@'.$host.'/')
        ->get('/locale/en')
        ->assertRedirect('/');
});

it('accepts only plain local addresses as the place to go back to', function (): void {
    // As AppServiceProvider sets it up for an https APP_URL.
    URL::forceRootUrl('https://hdid.example.test');
    URL::forceScheme('https');

    $refused = [
        'backslash before the host' => 'https://evil.example\\@hdid.example.test/',
        'user info' => 'https://user@hdid.example.test/',
        'user and password' => 'https://user:secret@hdid.example.test/',
        'relative path with a backslash' => '/\\evil.example',
        'scheme-relative address' => '//evil.example/',
        'scheme-relative address of the application' => '//hdid.example.test/admin',
        'script scheme' => 'javascript://hdid.example.test/%0Aalert(1)',
        'other scheme' => 'ftp://hdid.example.test/',
        'tab inside the path' => "https://hdid.example.test/pre\tmium",
        'newline inside the path' => "/admin\n/x",
        'space' => '/admin x',
        'delete character' => "/admin\x7F",
        'special scheme without slashes' => 'https:/evil.example',
        'relative without a leading slash' => 'evil.example',
        'empty' => '',
        'foreign host' => 'https://evil.example/',
        'other port' => 'https://hdid.example.test:8443/',
        'plain http to the https application' => 'http://hdid.example.test/',
    ];
    foreach ($refused as $case => $url) {
        expect(SafeRedirect::isLocal($url))->toBeFalse($case);
    }

    $accepted = [
        'application url' => url('/premium/login'),
        'relative path' => '/admin',
        'relative path with a query' => '/admin?tab=1',
        'upper-case host' => 'https://HDID.example.test/admin',
        'default port written out' => 'https://hdid.example.test:443/premium/login',
    ];
    foreach ($accepted as $case => $url) {
        expect(SafeRedirect::isLocal($url))->toBeTrue($case);
    }
});
