<?php

/*
 * The classic installation has no backup script: the commands in its
 * documents are the procedure. A restored dump must not read the host's
 * files (LOAD DATA LOCAL), must not run as root (the SQL of a dump could
 * write files or create accounts), and the backup is private and encrypted.
 */

/**
 * A document of the classic installation, or a skip where it is not part of the tree.
 */
function classicDoc(string $name): string
{
    $path = base_path('docs/2026-09-13/'.$name);

    if (! is_file($path)) {
        test()->markTestSkipped('The classic installation documents are not part of this tree.');
    }

    return (string) file_get_contents($path);
}

/**
 * A chapter of a document: from its heading id up to the next chapter.
 */
function classicChapter(string $html, string $id, string $nextId): string
{
    $start = strpos($html, 'id="'.$id.'"');
    $end = strpos($html, 'id="'.$nextId.'"', (int) $start);
    expect($start)->not->toBeFalse("no #{$id}")->and($end)->not->toBeFalse("no #{$nextId}");

    return substr($html, (int) $start, (int) $end - (int) $start);
}

it('restores the classic database dump in sandbox mode, without local file reads and without root', function (): void {
    $backup = classicChapter(classicDoc('hdid-uzemeltetesi-kezikonyv.html'), 's4', 's5');

    expect($backup)->toMatch('/mariadb --defaults-extra-file=\S+ --sandbox --local-infile=0 hdid/')
        ->and($backup)->not->toContain('| mariadb hdid</code>')
        ->and($backup)->not->toMatch('/mariadb\s+-u\s*root/');
});

it('writes the classic database backup private, encrypted and with the limit of its checksum stated', function (): void {
    $backup = classicChapter(classicDoc('hdid-uzemeltetesi-kezikonyv.html'), 's4', 's5');

    expect($backup)->toContain('umask 077')
        ->and($backup)->toContain('age -r')
        ->and($backup)->toContain('sha256sum')
        ->and($backup)->toContain('szándékos módosítást')
        ->and($backup)->not->toContain('| gzip > hdid-$(date +%F).sql.gz</code>');
});

it('switches LOAD DATA LOCAL off on the classic database server as well', function (): void {
    $guide = classicDoc('hdid-telepitesi-utmutato.html');

    expect($guide)->toContain('[mariadbd]')
        ->and($guide)->toContain('local_infile = 0')
        ->and($guide)->toContain('SELECT @@GLOBAL.local_infile');
});

it('empties the schema before the classic restore loads the dump, so a retried upgrade does not stop on tables of newer migrations', function (): void {
    $backup = classicChapter(classicDoc('hdid-uzemeltetesi-kezikonyv.html'), 's4', 's5');

    expect(strpos($backup, 'DROP DATABASE hdid'))->not->toBeFalse()
        ->and(strpos($backup, 'DROP DATABASE hdid'))->toBeLessThan((int) strpos($backup, '--sandbox --local-infile=0 hdid'));
});

it('stops serving before the classic restore loads the dump, and starts again only after everyone is signed out', function (): void {
    $backup = classicChapter(classicDoc('hdid-uzemeltetesi-kezikonyv.html'), 's4', 's5');
    $down = strpos($backup, 'php artisan down');
    $load = strpos($backup, '--sandbox --local-infile=0 hdid');
    $signOut = strpos($backup, 'php artisan hdid:sign-out-all');
    $up = strrpos($backup, 'php artisan up');

    expect($down)->not->toBeFalse()->and($load)->not->toBeFalse()->and($signOut)->not->toBeFalse()->and($up)->not->toBeFalse()
        ->and($down)->toBeLessThan($load)
        ->and($load)->toBeLessThan($signOut)
        ->and($signOut)->toBeLessThan($up)
        // The sign-out sweeps by prefix (1.15.1): the queue and the replay guards stay.
        ->and($backup)->not->toContain('FLUSHDB');
});

it('runs the nginx workers of the classic sample under an account of their own, outside the group that reads .env', function (): void {
    $guide = classicDoc('hdid-telepitesi-utmutato.html');

    expect($guide)->toContain("user nginx;\n")
        ->and($guide)->not->toContain("user www-data;\n")
        ->and($guide)->toContain('listen.group = nginx')
        ->and($guide)->toContain('setfacl')
        ->and($guide)->not->toContain('Az nginx-dolgozó ezért <code>www-data</code> legyen, vagy tagja a csoportnak.')
        ->and($guide)->toContain('sudo -u nginx cat /var/www/hdid/.env');
});
