<?php

use Illuminate\Testing\TestResponse;

/**
 * The running release is named in public in one place only: the "Source code" link, which points at
 * the exact source of the running build (AGPL-3.0 section 13). This is the documented exception to
 * "the version shows on the status page only" (owner decision D6, 2026-11-02); SHOW_SC=false removes it.
 */
const SOURCE_LINK_VERSION = '9.8.7';

const SOURCE_LINK_URL = 'https://github.com/adamfhun/openhdid/tree/v9.8.7';

/**
 * Every place the response could name the version: the body and the header values.
 */
function sourceLinkVersionHits(TestResponse $response): int
{
    $headers = collect($response->headers->all())->flatten()->implode("\n");

    return substr_count((string) $response->getContent(), SOURCE_LINK_VERSION)
        + substr_count($headers, SOURCE_LINK_VERSION);
}

beforeEach(function (): void {
    config()->set('hdid.version', SOURCE_LINK_VERSION);
    config()->set('hdid.source_url', SOURCE_LINK_URL);
});

it('names the running version publicly only in the source link, which SHOW_SC=false removes', function (): void {
    $branding = $this->getJson('/api/v1/branding')->assertOk();
    expect(sourceLinkVersionHits($branding))->toBe(1)
        ->and($branding->json('branding.portal.source_url'))->toBe(SOURCE_LINK_URL);

    // The portal shell: once, inside the inline branding (Blade's @json escapes the slashes).
    $portal = $this->get('/')->assertOk();
    $html = (string) $portal->getContent();
    expect(sourceLinkVersionHits($portal))->toBe(1);
    preg_match('/window\.__BRANDING__ = (.*?);<\/script>/', $html, $inline);
    expect(json_decode($inline[1] ?? 'null', true)['portal']['source_url'] ?? null)->toBe(SOURCE_LINK_URL);

    // The panel's sign-in page: once, in the footer link.
    $login = $this->get('/admin/login')->assertOk();
    expect(sourceLinkVersionHits($login))->toBe(1)
        ->and((string) $login->getContent())->toMatch('/<div class="hdid-source-link">\s*<a href="'.preg_quote(SOURCE_LINK_URL, '/').'"/');

    foreach (['/health', '/up', '/vendor/no-such-file', '/api/v1/no-such-endpoint'] as $path) {
        expect(sourceLinkVersionHits($this->get($path)))->toBe(0, "{$path} names the running version");
    }

    config()->set('hdid.show_source_url', false);

    foreach (['/api/v1/branding', '/', '/admin/login', '/health', '/up', '/vendor/no-such-file'] as $path) {
        expect(sourceLinkVersionHits($this->get($path)))->toBe(0, "{$path} names the running version with SHOW_SC=false");
    }
});

it('says in the published env example that the source link names the running release', function (): void {
    $file = base_path('deploy/openhdid/repo/.env.example');

    if (! is_file($file)) {
        $file = base_path('.env.example');
    }

    $lines = explode("\n", (string) file_get_contents($file));
    $at = array_search('# HDID_SOURCE_URL=', $lines, true);

    if ($at === false) {
        $this->markTestSkipped('This env example is not the container one.');
    }

    // The comment block right above the key.
    $comment = [];
    for ($line = $at - 1; $line >= 0 && str_starts_with($lines[$line], '# '); $line--) {
        array_unshift($comment, $lines[$line]);
    }

    expect(implode("\n", $comment))->toContain('tree/v')
        ->toContain('SHOW_SC')
        ->toContain('AGPL-3.0');
});
