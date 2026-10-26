<?php

use App\Mail\MagicLinkMail;
use App\Models\Client;

/**
 * The fallback (markdown) body of the login link mail prints the client's
 * name. Markdown syntax in the name must stay text: no link, no image, no
 * markup, also when it tries to escape the escaping with a backslash.
 */
it('prints markdown in a client name literally in the fallback login link mail', function (string $name, string $shown): void {
    $client = Client::factory()->synced()->create(['name' => $name]);

    $html = (new MagicLinkMail($client, 'https://hdid.test/login/link#token=abc', 720))->render();

    preg_match_all('/\b(?:href|src)="([^"]+)"/', $html, $targets);
    expect($targets[1])->not->toContain('https://evil.example')
        ->and($targets[1])->not->toContain('https://evil.example/p.png')
        ->and($targets[1])->toContain('https://hdid.test/login/link#token=abc')
        ->and($html)->toContain('Hello '.$shown);
})->with([
    'a link' => ['Kiss [Kattints](https://evil.example)', 'Kiss [Kattints](https://evil.example)'],
    'a link behind a backslash' => ['Kiss \[Kattints](https://evil.example)', 'Kiss [Kattints](https://evil.example)'],
    'a closing bracket behind a backslash' => ['Kiss\](https://evil.example)', 'Kiss](https://evil.example)'],
    'an image' => ['![x](https://evil.example/p.png)', '![x](https://evil.example/p.png)'],
    'html' => ['Kovács & Fia <b>x</b>', 'Kovács &amp; Fia &lt;b&gt;x&lt;/b&gt;'],
]);

it('puts exactly the issued link into the button and no event attribute anywhere', function (): void {
    $client = Client::factory()->synced()->create(['name' => 'Kiss "Anna" onmouseover=alert(1)']);
    $url = 'https://hdid.test/premium/login/link#token=AbC123xyz';

    $html = (new MagicLinkMail($client, $url, 720))->render();

    preg_match_all('/<a [^>]*href="([^"]*)"/', $html, $links);
    expect($links[1])->toContain($url)
        ->and(preg_match('/<[^>]+\son[a-z]+\s*=/i', $html))->toBe(0, 'no element carries an on…= attribute');
});
