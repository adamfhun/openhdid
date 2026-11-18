<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * The npm packages of the release are build dependencies only. `npm audit` stops the release and
 * alerts every week on a high or critical advisory; one without a fixed version that does not reach
 * the release may be waived in the public .github/scripts/npm-audit.exceptions, per package and
 * advisory, with a reason and a review date at most a year ahead (owner's decision of 2026-11-18,
 * picomatch GHSA-fc5q-39xc-vjpf). Everything else still stops.
 *
 * The script runs here against a fake npm that prints a fixture report, so no test needs the
 * network. The dates of the waivers are counted from today; nothing here pins an expiry.
 */
function npmAuditPath(string $path): ?string
{
    foreach ([base_path('deploy/openhdid/repo/'.$path), base_path($path)] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * An `npm audit --json` report with the given advisories: [package, GHSA id, severity].
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $advisories
 */
function npmAuditReport(array $advisories): string
{
    $vulnerabilities = [];
    foreach ($advisories as [$package, $id, $severity]) {
        $vulnerabilities[$package] ??= ['name' => $package, 'severity' => $severity, 'via' => [], 'range' => '*'];
        $vulnerabilities[$package]['via'][] = ['source' => 1, 'name' => $package, 'title' => "{$package} is vulnerable", 'url' => 'https://github.com/advisories/'.$id, 'severity' => $severity, 'range' => '*'];
        // A package that only depends on the vulnerable one names it as a string.
        $vulnerabilities['uses-'.$package] = ['name' => 'uses-'.$package, 'severity' => $severity, 'via' => [$package], 'range' => '*'];
    }

    return (string) json_encode(['auditReportVersion' => 2, 'vulnerabilities' => (object) $vulnerabilities]);
}

/**
 * Runs npm-audit.sh with a fake npm that prints the report (and exits like npm audit does).
 */
function npmAuditRun(string $report, string $exceptions, array $arguments = []): ProcessResult
{
    $script = npmAuditPath('.github/scripts/npm-audit.sh');
    if ($script === null) {
        test()->markTestSkipped('.github/scripts/npm-audit.sh is not part of this tree.');
    }
    foreach (['bash', 'jq'] as $tool) {
        if (! Process::run(['sh', '-c', 'command -v '.$tool])->successful()) {
            test()->markTestSkipped("{$tool} is not installed here; the preflight and the weekly scan have it.");
        }
    }

    $dir = realpath(sys_get_temp_dir()).'/hdid-npm-audit-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    file_put_contents($dir.'/report.json', $report);
    file_put_contents($dir.'/exceptions', $exceptions);
    file_put_contents($dir.'/npm', "#!/bin/sh\nprintf '%s\\n' \"\$*\" > '{$dir}/arguments'\ncat '{$dir}/report.json'\nexit 1\n");
    chmod($dir.'/npm', 0700);

    try {
        $result = Process::env(['NPM_AUDIT_NPM' => $dir.'/npm'])->run(['bash', $script, '--exceptions', $dir.'/exceptions', ...$arguments]);
        test()->npmArguments = is_file($dir.'/arguments') ? trim((string) file_get_contents($dir.'/arguments')) : null;

        return $result;
    } finally {
        File::deleteDirectory($dir);
    }
}

function npmAuditWaiver(string $package, string $id, string $modify = '+30 days', string $reason = 'only the build uses it, with patterns of our own configuration'): string
{
    return "{$package} | {$id} | ".now('UTC')->modify($modify)->toDateString()." | {$reason}\n";
}

it('passes a high advisory that is waived with a reason, and says so', function (): void {
    $result = npmAuditRun(npmAuditReport([['picomatch', 'GHSA-fc5q-39xc-vjpf', 'high']]), npmAuditWaiver('picomatch', 'ghsa-FC5Q-39xc-vjpf'));

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and($result->output())->toContain('KIVÉTEL   picomatch  GHSA-FC5Q-39XC-VJPF (high)', 'only the build uses it')
        ->and($result->output())->not->toContain('LELET')
        ->and(test()->npmArguments)->toBe('audit --json');
});

it('stops on a high or critical advisory that is not waived, also next to a waived one', function (string $package, string $id, string $severity): void {
    $result = npmAuditRun(
        npmAuditReport([['picomatch', 'GHSA-fc5q-39xc-vjpf', 'high'], [$package, $id, $severity]]),
        npmAuditWaiver('picomatch', 'GHSA-fc5q-39xc-vjpf'),
    );

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain("LELET     {$package}  ".strtoupper($id)." ({$severity})")
        ->and($result->errorOutput())->toContain('npm-audit.exceptions');
})->with([
    'another package' => ['vite', 'GHSA-aaaa-bbbb-cccc', 'high'],
    'a critical one' => ['vue', 'GHSA-dddd-eeee-ffff', 'critical'],
    // The waiver names one advisory of the package, not the package.
    'another advisory of the waived package' => ['picomatch', 'GHSA-1111-2222-3333', 'high'],
]);

it('does not stop on moderate and low advisories, as before', function (): void {
    $result = npmAuditRun(npmAuditReport([['postcss', 'GHSA-aaaa-bbbb-cccc', 'moderate'], ['nanoid', 'GHSA-dddd-eeee-ffff', 'low']]), "# no waivers\n");

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and($result->output())->toContain('nincs magas vagy kritikus közlemény');
});

it('says when a waiver is not needed any more', function (): void {
    $result = npmAuditRun(npmAuditReport([]), npmAuditWaiver('picomatch', 'GHSA-fc5q-39xc-vjpf'));

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain('picomatch GHSA-FC5Q-39XC-VJPF kivételre már nincs szükség');
});

it('refuses a waiver file with a missing reason, a wrong id or a review date that passed or lies more than a year ahead', function (string $line, string $message): void {
    $result = npmAuditRun(npmAuditReport([['picomatch', 'GHSA-fc5q-39xc-vjpf', 'high']]), $line);

    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain('a kivételfájl hibás', $message)
        // The audit is not even asked: a wrong file fails the same way offline.
        ->and(test()->npmArguments)->toBeNull();
})->with([
    'no reason' => [fn () => npmAuditWaiver('picomatch', 'GHSA-fc5q-39xc-vjpf', reason: 'build'), 'nincs indoklás'],
    'not a GHSA id' => [fn () => npmAuditWaiver('picomatch', 'CVE-2026-112366'), 'GHSA-… alakú'],
    'expired' => [fn () => npmAuditWaiver('picomatch', 'GHSA-fc5q-39xc-vjpf', '-1 day'), 'lejárt'],
    'more than a year ahead' => [fn () => npmAuditWaiver('picomatch', 'GHSA-fc5q-39xc-vjpf', '+370 days'), 'legfeljebb egy évre'],
    'twice' => [fn () => npmAuditWaiver('picomatch', 'GHSA-fc5q-39xc-vjpf').npmAuditWaiver('picomatch', 'GHSA-fc5q-39xc-vjpf'), 'kétszer szerepel'],
]);

it('fails, and does not pass, when npm audit gives no readable report', function (string $report): void {
    $result = npmAuditRun($report, "# no waivers\n");

    expect($result->exitCode())->toBe(3)
        ->and($result->errorOutput())->toContain('a vizsgálat nem futott le');
})->with(['an error text' => ['npm error network request failed'], 'a report without the list' => ['{"error":{"code":"ENOLOCK"}}'], 'nothing' => ['']]);

it('audits the lock file alone when asked, as the weekly scan does on the release tag', function (): void {
    npmAuditRun(npmAuditReport([]), "# no waivers\n", ['--package-lock-only']);

    expect(test()->npmArguments)->toBe('audit --json --package-lock-only');
});

it('ships a readable waiver file whose entries name a package, an advisory and a reason', function (): void {
    $file = npmAuditPath('.github/scripts/npm-audit.exceptions');
    if ($file === null) {
        test()->markTestSkipped('.github/scripts/npm-audit.exceptions is not part of this tree.');
    }

    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file)), fn (string $line): bool => ! preg_match('/^\s*(#|$)/', $line)));

    foreach ($lines as $line) {
        $fields = array_map('trim', explode('|', $line, 4));

        expect($fields)->toHaveCount(4)
            ->and($fields[0])->toMatch('/^(@[a-z0-9._-]+\/)?[a-z0-9._-]+$/')
            ->and($fields[1])->toMatch('/^GHSA-[a-z0-9]+-[a-z0-9]+-[a-z0-9]+$/i')
            ->and($fields[2])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
            ->and(mb_strlen($fields[3]))->toBeGreaterThan(40);
    }

    expect(is_executable((string) npmAuditPath('.github/scripts/npm-audit.sh')))->toBeTrue();
});

it('runs the audit through the script in the preflight and in the weekly scan, never plain npm audit', function (): void {
    $scan = npmAuditPath('.github/workflows/security-scan.yml');
    expect($scan)->not->toBeNull()
        ->and((string) file_get_contents((string) $scan))->toContain('run: bash .github/scripts/npm-audit.sh --package-lock-only')
        ->and((string) file_get_contents((string) $scan))->not->toContain('npm audit --');

    if (! is_file(base_path('deploy/openhdid/preflight.sh'))) {
        return;
    }

    $preflight = (string) file_get_contents(base_path('deploy/openhdid/preflight.sh'));
    expect($preflight)->toContain('bash .github/scripts/npm-audit.sh </dev/null || fail')
        ->and($preflight)->not->toContain('npm audit --audit-level');
});
