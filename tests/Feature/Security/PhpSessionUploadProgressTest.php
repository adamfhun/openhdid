<?php

/**
 * CVE-2026-109619 and CVE-2026-115560: the PHP session upload progress
 * tracking runs while the request body is parsed, before any application
 * code, so it is switched off in the image's php.ini and locked in the
 * PHP-FPM pool; the classic installation guide asks for the same. The
 * container files live under deploy/openhdid/repo in the development tree
 * and at the root of the published OpenHDID tree.
 */
function openhdidPhpConfig(string $name): string
{
    foreach (['deploy/openhdid/repo/docker/'.$name, 'docker/'.$name] as $path) {
        if (is_file(base_path($path))) {
            return (string) file_get_contents(base_path($path));
        }
    }

    test()->markTestSkipped("The container's {$name} is not part of this tree.");
}

it('switches the session upload progress off in the image for every PHP process', function (): void {
    expect(openhdidPhpConfig('php.ini'))->toMatch('/^session\.upload_progress\.enabled = 0$/m');
});

it('locks the session upload progress off for every web request in the PHP-FPM pool', function (): void {
    expect(openhdidPhpConfig('php-fpm.conf'))->toMatch('/^php_admin_flag\[session\.upload_progress\.enabled\] = off$/m');
});

it('asks for the same in the PHP settings and the pool of the classic installation', function (): void {
    $path = base_path('docs/2026-09-13/hdid-telepitesi-utmutato.html');

    if (! is_file($path)) {
        $this->markTestSkipped('The classic installation guide is not part of this tree.');
    }

    expect((string) file_get_contents($path))->toContain("session.upload_progress.enabled = 0\n")
        ->toContain('php_admin_flag[session.upload_progress.enabled] = off');
});
