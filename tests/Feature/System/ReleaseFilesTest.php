<?php

/**
 * The wiring of the container release: what the release attaches, what the preflight checks,
 * and that the published texts agree with the installer (install.sh).
 *
 * The files live under deploy/openhdid/repo in the main repository and at the root of the
 * exported tree; the release scripts and the HTML documents only in the main repository.
 */
function releaseFilesPath(string $path): ?string
{
    foreach ([base_path('deploy/openhdid/repo/'.$path), base_path($path)] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function releaseFilesRead(string $path): string
{
    $file = releaseFilesPath($path);

    if ($file === null) {
        test()->markTestSkipped("{$path} is not part of this tree.");
    }

    return (string) file_get_contents($file);
}

function releaseFilesMainRepo(string $path): string
{
    $file = base_path($path);

    if (! is_file($file)) {
        test()->markTestSkipped("{$path} lives in the main repository only.");
    }

    return (string) file_get_contents($file);
}

/**
 * The release asset name of a usage profile file, as the installer downloads it.
 */
function releaseFilesAssetName(string $profileFile): string
{
    [, $folder, $file] = explode('/', $profileFile);
    $base = basename($file, '.yaml');

    return $base === 'compose' ? "profile-{$folder}.yaml" : "profile-{$folder}-{$base}.yaml";
}

/**
 * The machine table of a guide: from its header row to the end of the table.
 */
function releaseFilesMachineTable(string $html): string
{
    $start = strpos($html, '<th>Profil</th><th>Gép vagy komponens</th>');
    expect($start)->not->toBeFalse();
    $end = strpos($html, '</table>', $start);

    return substr($html, $start, $end - $start);
}

it('attaches the installer and every profile file to the release under the name the installer downloads', function (): void {
    $workflow = releaseFilesRead('.github/workflows/release.yml');
    $installer = releaseFilesRead('install.sh');
    $root = dirname((string) releaseFilesPath('install.sh'));
    $profiles = glob($root.'/profiles/*/*.yaml') ?: [];

    expect($profiles)->not->toBeEmpty();
    expect($workflow)->toMatch('/^ +cp docs\/\*\.pdf compose\.yaml db\.env\.example backup\.sh install\.sh TROUBLESHOOTING\.md UPGRADE\.md "\$assets\/"$/m');

    foreach ($profiles as $file) {
        $relative = substr($file, strlen($root) + 1);
        $asset = releaseFilesAssetName($relative);

        expect($workflow)->toContain("cp {$relative} \"\$assets/{$asset}\"")
            ->and($installer)->toContain("{$asset}) printf '{$relative}'");
    }

    // 16 assets: 3 PDFs, the 6 files of the cp line, env.example, 4 profile files, the SBOM and SHA256SUMS.
    expect($profiles)->toHaveCount(4);
});

it('lets the preflight publish install.sh, write its release line and lint it with a pinned shellcheck', function (): void {
    $preflight = releaseFilesMainRepo('deploy/openhdid/preflight.sh');
    $release = releaseFilesMainRepo('deploy/openhdid/release.sh');
    $platform = releaseFilesMainRepo('deploy/openhdid/platform.sh');
    $installer = releaseFilesRead('install.sh');

    preg_match('/^ALLOWED_TOP=\(([^)]*)\)/ms', $preflight, $allowed);
    expect(preg_split('/\s+/', trim($allowed[1] ?? '')))->toContain('install.sh', 'backup.sh', 'profiles');

    expect($installer)->toMatch('/^OPENHDID_RELEASE=\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/m')
        ->and($preflight)->toContain('s/^OPENHDID_RELEASE=.*/OPENHDID_RELEASE=$VERSION/')
        ->and($preflight)->toContain('grep -qx "OPENHDID_RELEASE=$VERSION" "$OUT/install.sh"')
        ->and($release)->toContain('grep -qx "OPENHDID_RELEASE=$VERSION" "$REPO/install.sh"');

    expect($preflight)->toMatch('/^SHELLCHECK_IMAGE=koalaman\/shellcheck:v[0-9.]+@sha256:[0-9a-f]{64}$/m')
        ->and($preflight)->toContain('"$SHELLCHECK_IMAGE" -s bash install.sh backup.sh')
        ->and($platform)->toContain('"koalaman/shellcheck koalaman/shellcheck v"');

    // The new profile files are checked with Compose, the infra one standing alone with its own .env.
    expect($preflight)->toContain('-f profiles/10k/single.yaml --profile db --profile redis config --quiet')
        ->and($preflight)->toContain('COMPOSE_FILE=profiles/10k/infra.yaml');
});

it('runs the installer in the smoke test from an answers file, without a terminal, and expects a clean check', function (): void {
    $smoke = releaseFilesMainRepo('deploy/openhdid/smoke-test.sh');

    expect($smoke)->toContain('./install.sh "$@" --from "$TREE" --version "$SMOKE_VERSION" --lang hu --yes </dev/null')
        ->and($smoke)->toContain('./install.sh check --json --lang hu </dev/null')
        ->and($smoke)->toContain('installer_round trial PROFILE=trial')
        ->and($smoke)->toContain('installer_round 5k PROFILE=5k')
        ->and($smoke)->toContain('--ha) MODE=ha')
        ->and($smoke)->toContain('unset COMPOSE_FILE COMPOSE_PROJECT_NAME COMPOSE_PROFILES COMPOSE_ENV_FILES');

    // Errors are never accepted: the verdict refuses every finding that is neither info nor an expected warning.
    expect($smoke)->toContain('if ($finding["severity"] !== "info")');
});

it('ships no valkey-cli command that reads the password from a host variable or puts it on a command line', function (string $path): void {
    $text = str_ends_with($path, '.html') ? releaseFilesMainRepo($path) : releaseFilesRead($path);

    expect(preg_match_all('/valkey-cli[^\n<]*\s-a\s/', $text))->toBe(0, "{$path} passes the Valkey password with -a")
        ->and(str_contains($text, '-a "$REDIS_PASSWORD"'))->toBeFalse();
})->with([
    'UPGRADE.md',
    'TROUBLESHOOTING.md',
    'CHANGELOG.md',
    'README.md',
    'README.hu.md',
    'docs/2026-09-13/openhdid-telepitesi-utmutato.html',
    'docs/2026-09-13/openhdid-uzemeltetesi-kezikonyv.html',
    'docs/2026-09-13/hdid-telepitesi-utmutato.html',
    'docs/2026-09-13/hdid-uzemeltetesi-kezikonyv.html',
]);

it('names in each TROUBLESHOOTING.md chapter the check findings that refer to it, and lists every finding in chapter 24', function (): void {
    $installer = releaseFilesRead('install.sh');
    $guide = releaseFilesRead('TROUBLESHOOTING.md');

    $chapters = [];
    foreach (preg_split('/^## (?=\d+\. )/m', $guide) as $part) {
        if (preg_match('/^(\d+)\. /', $part, $number)) {
            $chapters[(int) $number[1]] = $part;
        }
    }

    preg_match_all('/^\s*(?:log_finding|finding)\s+"?([A-Z]+-[A-Za-z0-9_]+)"?\s.*?TROUBLESHOOTING\.md (\d+)\./m', $installer, $references, PREG_SET_ORDER);
    expect($references)->not->toBeEmpty();

    foreach ($references as [, $id, $chapter]) {
        expect($chapters)->toHaveKey((int) $chapter);
        expect(str_contains($chapters[(int) $chapter], "`{$id}`"))->toBeTrue("TROUBLESHOOTING.md {$chapter} does not name {$id}");
    }

    preg_match_all('/\b(HOST|DOCKER|FILES|ENV|TLS|NET|DB|SVC|VALKEY|SCHED|LOG|INFRA|K8S)-(\d\d)\b/', $installer, $all, PREG_SET_ORDER);
    $index = $chapters[24] ?? '';
    expect($index)->toContain('A telepítő: check, fix, report');

    foreach ($all as [$id, $area, $number]) {
        $inRange = preg_match('/`'.$area.'-(\d\d)`…`'.$area.'-(\d\d)`/', $index, $range) === 1
            && (int) $number >= (int) $range[1] && (int) $number <= (int) $range[2];
        $listed = str_contains($index, "`{$id}`") || $inRange;
        expect($listed)->toBeTrue("TROUBLESHOOTING.md 24 does not list {$id}");
    }

    preg_match_all('/\bFIX-[A-Z]+(?:-[A-Z]+)*\b/', $installer, $fixes);
    foreach (array_unique($fixes[0]) as $fix) {
        expect($index)->toContain($fix);
    }
});

it('keeps the machine table identical in the four guides, with the 10k single-host and the infra host rows', function (): void {
    $tables = [];
    foreach (['hdid-telepitesi-utmutato', 'hdid-uzemeltetesi-kezikonyv', 'openhdid-telepitesi-utmutato', 'openhdid-uzemeltetesi-kezikonyv'] as $guide) {
        $tables[$guide] = releaseFilesMachineTable(releaseFilesMainRepo("docs/2026-09-13/{$guide}.html"));
    }

    expect(array_unique($tables))->toHaveCount(1);

    $table = reset($tables);
    expect($table)->toContain('<tr><td>10k egy gépen (Compose)</td>')
        ->and($table)->toContain('<td>1</td><td>8</td><td>16 GB</td><td>120 GB</td>')
        ->and($table)->toContain('<tr><td>10k (Compose, infra gép)</td>')
        ->and($table)->toContain('<td>1</td><td>4</td><td>10 GB</td><td>100 GB</td>');
});

it('documents the installer in the published guides and the upgrade steps', function (): void {
    $upgrade = releaseFilesRead('UPGRADE.md');
    $changelog = releaseFilesRead('CHANGELOG.md');

    expect($upgrade)->toContain('-O "$base/install.sh"')
        ->and(substr_count($upgrade, "./install.sh check\n"))->toBe(2)
        ->and($upgrade)->toContain('./install.sh fix --only ENV-07')
        ->and(preg_match_all('/^v=/m', $upgrade))->toBe(2);

    foreach (['README.md', 'README.hu.md'] as $readme) {
        expect(releaseFilesRead($readme))->toContain('-O "$base/install.sh" -O "$base/SHA256SUMS"');
    }

    // The installer's block: [Unreleased] while it is being prepared, then the release that brought it.
    preg_match('/^## \[(?:Unreleased|1\.15\.0)\][^\n]*\n(.*?)^## \[/ms', $changelog, $installerBlock);
    expect($installerBlock[1] ?? '')->toContain('### Magyar', '### English', '**Frissítés előtt:**', '**Before upgrading:**', 'ÜZ-03.3 (új)', 'ÜZ-03.3 (new)', 'ENV-07', 'SCHED-01');

    $guide = releaseFilesMainRepo('docs/2026-09-13/openhdid-telepitesi-utmutato.html');
    expect($guide)->toContain('<h2 id="s16"><span class="num">16</span>A telepítő (install.sh)</h2>', '<h3>15.7 10k profil egy gépen</h3>', '<h3>15.8 Infra gép a kétgépes 10k-hoz</h3>', 'http-check send meth GET uri /up');
});
