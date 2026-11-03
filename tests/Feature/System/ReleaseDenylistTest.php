<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * The release denylist (deploy/openhdid/denylist.txt) covers what the published PDFs say: their text is
 * compressed, so the preflight reads the HTML they are printed from (the base64 screenshots cut out), the
 * manual's source and screenshot names, and the decoded metadata, link targets and alternate texts of the
 * PDFs; a PDF older than its sources, or titled differently, stops the release (owner decision D5, F41).
 *
 * The tests run the preflight's own functions (pdf_strings, changed_at, check_printed_pdfs,
 * check_denylist) in a throwaway tree with the test term below; the preflight and the denylist live
 * in the main repository only, so the tests skip in an exported tree. No real denylist term may
 * appear in this file.
 */
const DENYLIST_TEST_TERM = 'examplecorp';

/**
 * @return array{base: string, root: string, export: string, terms: string, harness: string, docs: list<string>, source: string, images: string}
 */
function denylistSandbox(): array
{
    $preflight = base_path('deploy/openhdid/preflight.sh');

    if (! is_file($preflight)) {
        test()->markTestSkipped('preflight.sh lives in the main repository only.');
    }

    $base = realpath(sys_get_temp_dir()).'/openhdid-denylist-'.bin2hex(random_bytes(6));
    mkdir($base.'/root', 0700, true);
    mkdir($base.'/export/docs', 0700, true);
    denylistSandboxFolders($base);

    $script = (string) file_get_contents($preflight);
    $parts = [
        '#!/usr/bin/env bash',
        'set -euo pipefail',
        'fail() { printf "HIBA: %s\n" "$*" >&2; exit 1; }',
        'ok() { printf "ok: %s\n" "$*"; }',
    ];
    preg_match_all('/^(?:MANUAL_[A-Z_]+|PRINTED_DOCS)=(?:\(.*?\)|\S*)$/ms', $script, $variables);
    expect($variables[0])->not->toBeEmpty();
    $parts = [...$parts, ...$variables[0]];
    foreach (['pdf_strings', 'changed_at', 'check_printed_pdfs', 'check_denylist'] as $function) {
        expect(preg_match('/^'.$function.'\(\) \{.*?^\}$/ms', $script, $body))->toBe(1, "preflight.sh has no {$function}()");
        $parts[] = $body[0];
    }
    $parts[] = 'paths() { printf "%s\n" "${PRINTED_DOCS[@]}"; printf "source=%s\nimages=%s\n" "$MANUAL_SOURCE" "$MANUAL_IMAGES"; }';
    $parts[] = '"$@"';
    file_put_contents($base.'/harness.sh', implode("\n\n", $parts)."\n");
    file_put_contents($base.'/terms.txt', "# Test terms\n".DENYLIST_TEST_TERM."\n");

    $docs = [];
    $source = $images = '';
    foreach (explode("\n", trim(denylistHarness($base, ['paths'])->throw()->output())) as $line) {
        match (true) {
            str_starts_with($line, 'source=') => $source = substr($line, 7),
            str_starts_with($line, 'images=') => $images = substr($line, 7),
            default => $docs[] = $line,
        };
    }

    $sandbox = [
        'base' => $base, 'root' => $base.'/root', 'export' => $base.'/export', 'terms' => $base.'/terms.txt',
        'harness' => $base.'/harness.sh', 'docs' => $docs, 'source' => $source, 'images' => $images,
    ];

    // A clean tree: every printed document with its PDF (also in the export, as the release names it),
    // the manual's source with one screenshot, and a text file.
    foreach ($docs as $doc) {
        $title = 'Dokumentum '.basename($doc, '.html');
        denylistWrite($sandbox['root'].'/'.$doc, "<!doctype html>\n<html><head><title>{$title}</title></head>\n<body><p>OpenHDID</p><img alt=\"Belépés\" src=\"data:image/jpeg;base64,/9j/4AAQSkZJRg==\"></body></html>\n");
        denylistWrite($sandbox['root'].'/'.substr($doc, 0, -5).'.pdf', denylistPdf($title));
        denylistWrite($sandbox['export'].'/docs/'.basename($doc, '.html').'.pdf', denylistPdf($title));
    }
    denylistWrite($sandbox['root'].'/'.$source, "<h1>Kézikönyv</h1>\n[[IMG:01-belepes|<b>Belépő oldal.</b> Jelszavas belépés.]]\n");
    denylistWrite($sandbox['root'].'/'.$images.'/01-belepes.jpg', 'jpeg');
    denylistWrite($sandbox['export'].'/README.md', "# OpenHDID\n");

    return $sandbox;
}

/**
 * The sandboxes of the running test, removed after it.
 *
 * @return list<string>
 */
function denylistSandboxFolders(?string $add = null, bool $forget = false): array
{
    static $folders = [];

    if ($add !== null) {
        $folders[] = $add;
    }
    $current = $folders;
    if ($forget) {
        $folders = [];
    }

    return $current;
}

function denylistWrite(string $file, string $content): void
{
    File::ensureDirectoryExists(dirname($file));
    file_put_contents($file, $content);
}

/**
 * The smallest PDF the checks read: a document information dictionary as Chrome writes it, the
 * title as a hex UTF-16 string, and an optional link and further entries.
 */
function denylistPdf(string $title, string $more = ''): string
{
    $hex = 'FEFF'.strtoupper(bin2hex(mb_convert_encoding($title, 'UTF-16BE', 'UTF-8')));

    return "%PDF-1.4\n1 0 obj\n<</Title <{$hex}>\n/Creator (Mozilla/5.0 \\(Macintosh\\) HeadlessChrome/155.0.0.0)\n/Producer (Skia/PDF m155)>>\nendobj\n"
        ."2 0 obj\n<</Type /Annot\n/A <</Type /Action\n/S /URI\n/URI (https://github.com/adamfhun/openhdid/releases)>>{$more}>>\nendobj\n"
        ."trailer\n<</Size 3\n/Info 1 0 R>>\n%%EOF\n";
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 */
function denylistHarness(string $base, array $arguments, array $env = []): ProcessResult
{
    return Process::path($base)
        ->env(['TMPDIR' => $base, ...$env])
        ->timeout(60)
        ->run(['bash', $base.'/harness.sh', ...$arguments]);
}

/**
 * @param  array{base: string, root: string, export: string, terms: string}  $sandbox
 */
function denylistCheck(array $sandbox): ProcessResult
{
    return denylistHarness($sandbox['base'], ['check_denylist', $sandbox['terms'], $sandbox['root'], $sandbox['export']]);
}

/**
 * @param  array{base: string, root: string}  $sandbox
 * @param  list<string>  $arguments
 */
function denylistGit(array $sandbox, array $arguments, string $date = '2026-11-01T10:00:00Z'): void
{
    Process::path($sandbox['root'])
        ->env([
            'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => $sandbox['base'].'/gitconfig', 'HOME' => $sandbox['base'],
            'GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.org', 'GIT_AUTHOR_DATE' => $date,
            'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.org', 'GIT_COMMITTER_DATE' => $date,
        ])
        ->run(['git', ...$arguments])
        ->throw();
}

afterEach(function (): void {
    foreach (denylistSandboxFolders(forget: true) as $folder) {
        File::deleteDirectory($folder);
    }
});

it('runs the denylist and the freshness check over the printed sources of every published PDF', function (): void {
    $sandbox = denylistSandbox();
    $preflight = (string) file_get_contents(base_path('deploy/openhdid/preflight.sh'));
    $exportList = (string) file_get_contents(base_path('deploy/openhdid/export.list'));

    // The printed documents are exactly the HTML of the PDFs export.list publishes.
    preg_match_all('/^(docs\/\S+)\.pdf -> /m', $exportList, $published);
    expect($published[1])->not->toBeEmpty();
    $printed = $sandbox['docs'];
    sort($printed);
    $expected = array_map(fn (string $pdf): string => $pdf.'.html', $published[1]);
    sort($expected);
    expect($printed)->toBe($expected);

    expect($preflight)
        ->toContain('check_denylist "$HERE/denylist.txt" "$ROOT" "$OUT"')
        ->toContain('check_printed_pdfs "$ROOT" "$DIRTY"')
        ->toMatch('/^    for doc in "\$\{PRINTED_DOCS\[@\]\}"; do$/m')
        ->toMatch('/^for doc in "\$\{PRINTED_DOCS\[@\]\}"; do$/m')
        ->not->toMatch('/^\s*for doc in docs\//m');
});

it('keeps every denylisted term out of the sources and titles of the published documents', function (): void {
    $sandbox = denylistSandbox();
    $denylist = base_path('deploy/openhdid/denylist.txt');
    File::cleanDirectory($sandbox['export']);

    // The PDFs export.list publishes, under their published names.
    preg_match_all('/^(\S+\.pdf) -> (\S+)$/m', (string) file_get_contents(base_path('deploy/openhdid/export.list')), $pdfs, PREG_SET_ORDER);
    foreach ($pdfs as [, $from, $to]) {
        denylistWrite($sandbox['export'].'/'.$to, (string) file_get_contents(base_path($from)));
    }

    $result = denylistHarness($sandbox['base'], ['check_denylist', $denylist, base_path(), $sandbox['export']]);

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
});

it('passes a clean tree', function (): void {
    $sandbox = denylistSandbox();

    $result = denylistCheck($sandbox);

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
});

it('stops the preflight on a denylisted term in the manual source, the printed manual, an image name, a decoded PDF title or a PDF link', function (string $case): void {
    $sandbox = denylistSandbox();
    $term = DENYLIST_TEST_TERM;
    $manual = $sandbox['docs'][array_key_last($sandbox['docs'])];

    $where = match ($case) {
        'manual source' => (function () use ($sandbox, $term): string {
            File::append($sandbox['root'].'/'.$sandbox['source'], "<p>Az {$term} ügyfélszolgálata.</p>\n");

            return $sandbox['source'];
        })(),
        'printed manual outside its images' => (function () use ($sandbox, $term, $manual): string {
            $html = (string) file_get_contents($sandbox['root'].'/'.$manual);
            file_put_contents($sandbox['root'].'/'.$manual, str_replace('<p>OpenHDID</p>', "<p>OpenHDID, {$term}</p>", $html));

            return $manual;
        })(),
        'image name' => (function () use ($sandbox, $term): string {
            denylistWrite($sandbox['root'].'/'.$sandbox['images']."/12_{$term}_belepes.jpg", 'jpeg');

            return $sandbox['images'];
        })(),
        'hex UTF-16 PDF title' => (function () use ($sandbox, $term): string {
            denylistWrite($sandbox['export'].'/docs/kezikonyv.pdf', denylistPdf("Kézikönyv – {$term}"));

            return 'docs/kezikonyv.pdf';
        })(),
        'PDF link' => (function () use ($sandbox, $term): string {
            denylistWrite($sandbox['export'].'/docs/link.pdf', str_replace('https://github.com/adamfhun/openhdid/releases', "https://{$term}.test/releases", denylistPdf('Útmutató')));

            return 'docs/link.pdf';
        })(),
        'escaped PDF string' => (function () use ($sandbox): string {
            // \( and \) do not end the string, \145 is an "e".
            denylistWrite($sandbox['export'].'/docs/author.pdf', denylistPdf('Útmutató', "\n/Author (\\(x\\) \\145xamplecorp)"));

            return 'docs/author.pdf';
        })(),
    };

    $result = denylistCheck($sandbox);

    expect($result->exitCode())->not->toBe(0)
        ->and($result->errorOutput())->toContain('denylist.txt')->toContain($where);
})->with(['manual source', 'printed manual outside its images', 'image name', 'hex UTF-16 PDF title', 'PDF link', 'escaped PDF string']);

it('stops instead of passing when the metadata of the published PDFs cannot be read', function (): void {
    $sandbox = denylistSandbox();
    denylistWrite($sandbox['export'].'/docs/kezikonyv.pdf', denylistPdf('Kézikönyv – '.DENYLIST_TEST_TERM));
    // A php that cannot run (a broken install, a missing extension): no output is no "no hit".
    denylistWrite($sandbox['base'].'/bin/php', "#!/bin/sh\nexit 255\n");
    chmod($sandbox['base'].'/bin/php', 0755);

    $result = denylistHarness(
        $sandbox['base'],
        ['check_denylist', $sandbox['terms'], $sandbox['root'], $sandbox['export']],
        ['PATH' => $sandbox['base'].'/bin:'.getenv('PATH')],
    );

    expect($result->exitCode())->not->toBe(0)
        ->and($result->errorOutput())->toContain('nem olvasható');
});

it('ignores denylisted letters inside the base64 images of the printed manual', function (): void {
    $sandbox = denylistSandbox();
    $manual = $sandbox['docs'][array_key_last($sandbox['docs'])];
    $html = (string) file_get_contents($sandbox['root'].'/'.$manual);
    // "+" and "/" are base64 letters and word boundaries alike: unstripped, this is a whole-word hit.
    file_put_contents($sandbox['root'].'/'.$manual, str_replace('/9j/4AAQSkZJRg==', '/9j/4AAQ+'.DENYLIST_TEST_TERM.'/SkZJRg==', $html));

    $result = denylistCheck($sandbox);

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
});

it('refuses a published PDF older than the HTML it is printed from', function (): void {
    $sandbox = denylistSandbox();
    $doc = $sandbox['docs'][0];
    $pdf = substr($doc, 0, -5).'.pdf';
    denylistGit($sandbox, ['init', '--quiet', '-b', 'main']);
    denylistGit($sandbox, ['add', '-A']);
    denylistGit($sandbox, ['commit', '--quiet', '-m', 'Documents and their PDFs']);

    expect(denylistHarness($sandbox['base'], ['check_printed_pdfs', $sandbox['root'], '0'])->exitCode())->toBe(0);

    // The HTML changes a day later, the PDF is not printed again.
    File::append($sandbox['root'].'/'.$doc, "<p>Új bekezdés.</p>\n");
    denylistGit($sandbox, ['commit', '--quiet', '-am', 'Edited text'], '2026-11-02T10:00:00Z');
    $result = denylistHarness($sandbox['base'], ['check_printed_pdfs', $sandbox['root'], '0']);
    expect($result->exitCode())->not->toBe(0)
        ->and($result->errorOutput())->toContain($pdf)->toContain('régebbi');

    // Printed again: in step.
    File::append($sandbox['root'].'/'.$pdf, "\n");
    denylistGit($sandbox, ['commit', '--quiet', '-am', 'Printed again'], '2026-11-02T11:00:00Z');
    expect(denylistHarness($sandbox['base'], ['check_printed_pdfs', $sandbox['root'], '0'])->exitCode())->toBe(0);

    // A new screenshot makes the manual's PDF stale: it is printed from forras.html and the screenshots.
    $manual = $sandbox['docs'][array_key_last($sandbox['docs'])];
    denylistWrite($sandbox['root'].'/'.$sandbox['images'].'/02-vezerlopult.jpg', 'jpeg');
    denylistGit($sandbox, ['add', '-A']);
    denylistGit($sandbox, ['commit', '--quiet', '-m', 'New screenshot'], '2026-11-02T12:00:00Z');
    $result = denylistHarness($sandbox['base'], ['check_printed_pdfs', $sandbox['root'], '0']);
    expect($result->exitCode())->not->toBe(0)
        ->and($result->errorOutput())->toContain(substr($manual, 0, -5).'.pdf');
});

it('compares uncommitted changes by their file time under --dirty', function (): void {
    $sandbox = denylistSandbox();
    $doc = $sandbox['docs'][0];
    $pdf = substr($doc, 0, -5).'.pdf';
    denylistGit($sandbox, ['init', '--quiet', '-b', 'main']);
    denylistGit($sandbox, ['add', '-A']);
    denylistGit($sandbox, ['commit', '--quiet', '-m', 'Documents and their PDFs']);

    // An edited, not yet printed HTML.
    File::append($sandbox['root'].'/'.$doc, "<p>Új bekezdés.</p>\n");
    touch($sandbox['root'].'/'.$doc, time() + 60);
    $result = denylistHarness($sandbox['base'], ['check_printed_pdfs', $sandbox['root'], '1']);
    expect($result->exitCode())->not->toBe(0)
        ->and($result->errorOutput())->toContain($pdf);

    // Printed again afterwards, not yet committed.
    File::append($sandbox['root'].'/'.$pdf, "\n");
    touch($sandbox['root'].'/'.$pdf, time() + 120);
    expect(denylistHarness($sandbox['base'], ['check_printed_pdfs', $sandbox['root'], '1'])->exitCode())->toBe(0);
});

it('dates an uncommitted screenshot folder by the files git keeps, not by the files it ignores', function (): void {
    $sandbox = denylistSandbox();
    $manual = $sandbox['docs'][array_key_last($sandbox['docs'])];
    $pdf = substr($manual, 0, -5).'.pdf';
    $images = $sandbox['root'].'/'.$sandbox['images'];
    denylistWrite($sandbox['root'].'/.gitignore', ".DS_Store\n");
    denylistGit($sandbox, ['init', '--quiet', '-b', 'main']);
    denylistGit($sandbox, ['add', '-A']);
    denylistGit($sandbox, ['commit', '--quiet', '-m', 'Documents and their PDFs']);

    // A new screenshot, then the manual printed again; neither committed yet.
    denylistWrite($images.'/02-vezerlopult.jpg', 'jpeg');
    touch($images.'/02-vezerlopult.jpg', time() + 60);
    File::append($sandbox['root'].'/'.$pdf, "\n");
    touch($sandbox['root'].'/'.$pdf, time() + 120);
    expect(denylistHarness($sandbox['base'], ['check_printed_pdfs', $sandbox['root'], '1'])->exitCode())->toBe(0);

    // The file manager writes its ignored folder settings afterwards: no source of the manual.
    denylistWrite($images.'/.DS_Store', 'folder settings');
    touch($images.'/.DS_Store', time() + 180);
    $result = denylistHarness($sandbox['base'], ['check_printed_pdfs', $sandbox['root'], '1']);
    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());

    // A further screenshot after the print still makes the manual's PDF stale.
    denylistWrite($images.'/03-ugyfel.jpg', 'jpeg');
    touch($images.'/03-ugyfel.jpg', time() + 240);
    $result = denylistHarness($sandbox['base'], ['check_printed_pdfs', $sandbox['root'], '1']);
    expect($result->exitCode())->not->toBe(0)
        ->and($result->errorOutput())->toContain($pdf);
});

it('refuses a published PDF whose title is not the title of its HTML', function (): void {
    $sandbox = denylistSandbox();
    $doc = $sandbox['docs'][0];
    $pdf = substr($doc, 0, -5).'.pdf';
    denylistWrite($sandbox['root'].'/'.$pdf, denylistPdf('Régi cím'));
    denylistGit($sandbox, ['init', '--quiet', '-b', 'main']);
    denylistGit($sandbox, ['add', '-A']);
    denylistGit($sandbox, ['commit', '--quiet', '-m', 'Documents and their PDFs']);

    $result = denylistHarness($sandbox['base'], ['check_printed_pdfs', $sandbox['root'], '0']);

    expect($result->exitCode())->not->toBe(0)
        ->and($result->errorOutput())->toContain($pdf)->toContain('Régi cím');
});
