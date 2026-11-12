<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * The components of the image that no package manager knows, and that Trivy therefore cannot see:
 * the source-built nginx, the libcurl PHP loads, PHP itself, phpredis and headers-more (F18, TEL-5).
 *
 * - .github/scripts/upstream-advisories.sh compares their versions with the vendors' advisories
 *   (nginx.org, curl.se, php.net, the GitHub advisories of phpredis and headers-more); a waiver needs a
 *   reason and a review date in the public upstream-advisories.exceptions file.
 * - The preflight runs it in the dependency step and stops the release; the weekly security-scan.yml
 *   runs it with the versions read from the latest image and audits the release tag's lock files,
 *   only to alert; platform.sh check shows its result.
 *
 * The script runs here against fixture feeds (file:// URLs and a fake GitHub API command), so no test
 * needs the network. The script and the workflows live under deploy/openhdid/repo in the main
 * repository and at the root of an exported tree; the preflight and platform.sh only in the main one.
 */
function upstreamAdvisoriesPath(string $path): ?string
{
    foreach ([base_path('deploy/openhdid/repo/'.$path), base_path($path)] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function upstreamAdvisoriesRelease(string $path): string
{
    $file = upstreamAdvisoriesPath($path);

    if ($file === null) {
        test()->markTestSkipped("{$path} is not part of this tree.");
    }

    return (string) file_get_contents($file);
}

function upstreamAdvisoriesMain(string $path): string
{
    if (! is_file(base_path($path))) {
        test()->markTestSkipped("{$path} lives in the main repository only.");
    }

    return (string) file_get_contents(base_path($path));
}

/**
 * One job of a workflow: its key line and every line indented deeper.
 */
function upstreamAdvisoriesJob(string $workflow, string $job): string
{
    preg_match('/^  '.preg_quote($job, '/').':\n(?:(?:    .*|)\n)*/m', $workflow, $match);

    return $match[0] ?? '';
}

function upstreamAdvisoriesFolders(?string $add = null, bool $forget = false): array
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

afterEach(function (): void {
    foreach (upstreamAdvisoriesFolders(forget: true) as $folder) {
        File::deleteDirectory($folder);
    }
});

/**
 * One advisory of nginx.org/en/security_advisories.html, one line as the page writes it.
 */
function upstreamAdvisoriesNginx(string $cve, string $vulnerable, string $notVulnerable, string $severity = 'medium', string $title = 'Buffer overflow in a test module'): string
{
    return '<li><p>'.$title.'<br>Severity: '.$severity.'<br><a href="https://example.invalid/'.$cve.'">Advisory</a><br>'
        .'<a href="https://www.cve.org/CVERecord?id='.$cve.'">'.$cve.'</a><br>Not vulnerable: '.$notVulnerable.'<br>Vulnerable: '.$vulnerable.'</p></li>';
}

/**
 * @param  list<string>  $advisories
 */
function upstreamAdvisoriesNginxPage(array $advisories): string
{
    return '<!DOCTYPE html><html><head><title>nginx security advisories</title></head><body><div id="content"><h2>nginx security advisories</h2><p>'."\n"
        .'Patches are signed using one of the <a href="pgp_keys.html">PGP public keys</a>.'."\n".'</p><ul>'."\n\n"
        .implode("\n\n", $advisories)."\n\n".'</ul></div></body></html>'."\n";
}

/**
 * One entry of curl.se/docs/vuln.json (OSV).
 *
 * @param  list<array<string, string>>  $events
 * @return array<string, mixed>
 */
function upstreamAdvisoriesCurl(string $cve, array $events, string $affects = 'both', string $summary = 'test overflow in libcurl'): array
{
    return [
        'schema_version' => '1.5.0',
        'id' => 'CURL-'.$cve,
        'aliases' => [$cve],
        'summary' => $summary,
        'database_specific' => ['package' => 'curl', 'affects' => $affects, 'www' => "https://curl.se/docs/{$cve}.html", 'severity' => 'Medium'],
        'affected' => [[
            'ranges' => [['type' => 'SEMVER', 'events' => $events], ['type' => 'GIT', 'repo' => 'https://github.com/curl/curl.git', 'events' => [['introduced' => 'abc'], ['fixed' => 'def']]]],
            'versions' => ['8.0.0'],
        ]],
    ];
}

/**
 * A GitHub repository security advisory (GET /repos/{owner}/{repo}/security-advisories).
 *
 * @return array<string, mixed>
 */
function upstreamAdvisoriesGitHub(string $cve, string $range, string $patched = '', ?string $withdrawnAt = null): array
{
    $ghsa = 'GHSA-'.implode('-', str_split(substr(hash('sha256', $cve), 0, 12), 4));

    return [
        'ghsa_id' => $ghsa,
        'cve_id' => $cve,
        'html_url' => "https://github.com/phpredis/phpredis/security/advisories/{$ghsa}",
        'summary' => "Test advisory {$cve}",
        'severity' => 'high',
        'state' => 'published',
        'withdrawn_at' => $withdrawnAt,
        'vulnerabilities' => [[
            'package' => ['ecosystem' => 'other', 'name' => 'phpredis'],
            'vulnerable_version_range' => $range,
            'patched_versions' => $patched,
            'vulnerable_functions' => [],
        ]],
    ];
}

/**
 * The php.net release list of one branch: version => tags.
 *
 * @param  array<string, list<string>>  $releases
 */
function upstreamAdvisoriesPhp(array $releases): string
{
    $list = [];
    foreach ($releases as $version => $tags) {
        $list[$version] = ['announcement' => true, 'tags' => $tags, 'date' => '01 Jan 2026', 'source' => []];
    }

    return (string) json_encode($list);
}

/**
 * Fixture feeds that hold old advisories only, with any feed replaced, and a fake GitHub API command.
 *
 * @param  array{nginx?: string, curl?: string, php?: string, phpredis?: string, headers-more?: string}  $feeds  raw bodies
 * @return array{dir: string}
 */
function upstreamAdvisoriesSandbox(array $feeds = []): array
{
    $dir = realpath(sys_get_temp_dir()).'/openhdid-advisories-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    upstreamAdvisoriesFolders($dir);

    $bodies = [
        'nginx.html' => $feeds['nginx'] ?? upstreamAdvisoriesNginxPage([upstreamAdvisoriesNginx('CVE-2099-1000', '1.25.0-1.29.6', '1.29.7+, 1.28.3+')]),
        'vuln.json' => $feeds['curl'] ?? (string) json_encode([upstreamAdvisoriesCurl('CVE-2099-0000', [['introduced' => '7.1'], ['fixed' => '8.0.0']])]),
        'php.json' => $feeds['php'] ?? upstreamAdvisoriesPhp(['8.5.13' => [], '8.5.12' => ['security'], '8.5.11' => ['security']]),
        'gh-phpredis_phpredis.json' => $feeds['phpredis'] ?? '[]',
        'gh-openresty_headers-more-nginx-module.json' => $feeds['headers-more'] ?? '[]',
    ];
    foreach ($bodies as $name => $body) {
        file_put_contents($dir.'/'.$name, $body);
    }

    file_put_contents($dir.'/gh', <<<'SH'
#!/bin/sh
# The GitHub API of the tests: the advisories of a repository from gh-<owner>_<repo>.json beside this file.
dir=$(dirname "$0")
printf '%s\n' "$*" >> "$dir/gh-calls"
case "$1" in
    repos/*/security-advisories\?*) ;;
    *) echo "unexpected GitHub API call: $*" >&2; exit 1 ;;
esac
repo=${1#repos/}
repo=${repo%%/security-advisories*}
file="$dir/gh-$(printf '%s' "$repo" | tr '/' '_').json"
[ -f "$file" ] || { echo "no fixture for $repo" >&2; exit 1; }
cat "$file"
SH);
    chmod($dir.'/gh', 0755);

    return ['dir' => $dir];
}

/**
 * @param  array{dir: string}  $sandbox
 * @param  list<string>  $arguments
 */
function upstreamAdvisoriesRun(array $sandbox, array $arguments, ?string $exceptions = null): ProcessResult
{
    $script = upstreamAdvisoriesPath('.github/scripts/upstream-advisories.sh');
    if ($script === null) {
        test()->fail('.github/scripts/upstream-advisories.sh is missing.');
    }
    foreach (['bash', 'curl', 'jq'] as $tool) {
        if (! Process::run(['sh', '-c', 'command -v '.$tool])->successful()) {
            test()->markTestSkipped("{$tool} is not installed here; the preflight and the weekly scan have it.");
        }
    }

    if ($exceptions !== null) {
        file_put_contents($sandbox['dir'].'/exceptions', $exceptions);
        $arguments = ['--exceptions', $sandbox['dir'].'/exceptions', ...$arguments];
    }

    return Process::path($sandbox['dir'])
        ->env([
            'ADVISORY_NGINX_URL' => 'file://'.$sandbox['dir'].'/nginx.html',
            'ADVISORY_CURL_URL' => 'file://'.$sandbox['dir'].'/vuln.json',
            'ADVISORY_PHP_URL' => 'file://'.$sandbox['dir'].'/php.json',
            'ADVISORY_GH' => $sandbox['dir'].'/gh',
            'TMPDIR' => $sandbox['dir'],
        ])
        ->timeout(60)
        ->run(['bash', $script, ...$arguments]);
}

/**
 * The exceptions file of a test: the comment header the shipped one has, then the given lines.
 *
 * @param  list<string>  $lines
 */
function upstreamAdvisoriesExceptions(array $lines): string
{
    return "# komponens | azonosító | felülvizsgálat | indoklás\n\n".implode("\n", $lines)."\n";
}

function upstreamAdvisoriesReviewDate(int $days): string
{
    return now('UTC')->addDays($days)->format('Y-m-d');
}

it('checks the source-built nginx, PHP, libcurl and phpredis of the latest image against the upstream advisories every week', function (): void {
    $scan = upstreamAdvisoriesRelease('.github/workflows/security-scan.yml');
    $job = upstreamAdvisoriesJob($scan, 'upstream');

    expect($job)->not->toBe('')
        ->and($job)->toContain('runs-on: ubuntu-24.04')
        ->and($job)->toMatch('/^    permissions:\n      contents: read\n(?!      )/m');

    // The versions come from the latest image itself: what runs, not what a file says.
    expect($job)->toContain('"$IMAGE" version')
        ->and($job)->toContain('PHP_VERSION')
        ->and($job)->toContain('curl_version()["version"]')
        ->and($job)->toContain('phpversion("redis")')
        ->and($job)->toContain('nginx "$IMAGE" -V')
        ->and($job)->toContain('headers-more-nginx-module-');

    // The release tag of that image is checked out, and the script and the waivers come from it.
    expect($job)->toContain('ref: v${{ steps.image.outputs.release }}')
        ->and($job)->toContain('persist-credentials: false')
        ->and($job)->toContain('bash .github/scripts/upstream-advisories.sh "nginx=$NGINX" "libcurl=$LIBCURL" "php=$PHP" "phpredis=$PHPREDIS" "headers-more=$HEADERS_MORE"')
        ->and($job)->toContain('GH_TOKEN: ${{ github.token }}');

    // The checks after the checkout run even when an earlier one fails, so one alert shows every finding.
    expect(substr_count($job, "if: \${{ !cancelled() && steps.checkout.outcome == 'success' }}"))->toBe(3);
});

it('flags a pinned version inside a fixed upstream advisory range and passes a fixed one', function (string $component, array $feeds, string $affected, string $fixed, string $id): void {
    $sandbox = upstreamAdvisoriesSandbox($feeds);

    $flagged = upstreamAdvisoriesRun($sandbox, ["{$component}={$affected}"]);

    expect($flagged->exitCode())->toBe(1, $flagged->output().$flagged->errorOutput())
        ->and($flagged->output())->toMatch('/ÉRINTETT\s+'.preg_quote($component, '/').' '.preg_quote($affected, '/').'\s+'.preg_quote($id, '/').'\b.*'.preg_quote($fixed, '/').'/u')
        ->and($flagged->errorOutput())->toContain('platform.sh');

    $passed = upstreamAdvisoriesRun($sandbox, ["{$component}={$fixed}"]);

    expect($passed->exitCode())->toBe(0, $passed->output().$passed->errorOutput())
        ->and($passed->output())->not->toContain('ÉRINTETT')
        ->and($passed->output())->toContain("{$component} {$fixed}");
})->with([
    'libcurl (curl.se OSV feed)' => ['libcurl', ['curl' => (string) json_encode([
        upstreamAdvisoriesCurl('CVE-2099-0001', [['introduced' => '8.0.0'], ['fixed' => '8.24.0']]),
        upstreamAdvisoriesCurl('CVE-2099-0000', [['introduced' => '7.1'], ['fixed' => '8.0.0']]),
    ])], '8.23.0', '8.24.0', 'CVE-2099-0001'],
    'nginx (nginx.org advisories page)' => ['nginx', ['nginx' => upstreamAdvisoriesNginxPage([
        upstreamAdvisoriesNginx('CVE-2099-1001', '1.31.2-1.31.8, 1.30.0-1.30.7', '1.31.9+, 1.30.8+'),
        upstreamAdvisoriesNginx('CVE-2099-1000', '1.25.0-1.29.6', '1.29.7+, 1.28.3+'),
    ])], '1.31.8', '1.31.9', 'CVE-2099-1001'],
    'PHP (php.net security release)' => ['php', ['php' => upstreamAdvisoriesPhp([
        '8.5.13' => ['security'], '8.5.12' => ['security'], '8.5.11' => [],
    ])], '8.5.12', '8.5.13', 'PHP-8.5.13'],
    'phpredis (GitHub advisories)' => ['phpredis', ['phpredis' => (string) json_encode([
        upstreamAdvisoriesGitHub('CVE-2099-2001', '< 6.3.1', '6.3.1'),
    ])], '6.3.0', '6.3.1', 'CVE-2099-2001'],
    'headers-more (GitHub advisories)' => ['headers-more', ['headers-more' => (string) json_encode([
        upstreamAdvisoriesGitHub('CVE-2099-3001', '<= 0.41', '0.42'),
    ])], '0.41', '0.42', 'CVE-2099-3001'],
]);

it('reads the version ranges of GitHub advisories as GitHub writes them', function (string $range, string $version, bool $affected): void {
    $sandbox = upstreamAdvisoriesSandbox(['phpredis' => (string) json_encode([upstreamAdvisoriesGitHub('CVE-2099-2002', $range)])]);

    $result = upstreamAdvisoriesRun($sandbox, ["phpredis={$version}"]);

    expect($result->exitCode())->toBe($affected ? 1 : 0, $result->output().$result->errorOutput())
        ->and(str_contains($result->output(), 'CVE-2099-2002'))->toBe($affected);
})->with([
    'below a bound, affected' => ['< 6.3.1', '6.3.0', true],
    'below a bound, fixed' => ['< 6.3.1', '6.3.1', false],
    'up to a bound, included' => ['<= 6.3.0', '6.3.0', true],
    'two bounds, inside' => ['>= 6.0.0, < 6.3.1', '6.3.0', true],
    'two bounds, below the first' => ['>= 6.0.0, < 6.3.1', '5.3.7', false],
    'an exclusive lower bound' => ['> 6.3.0', '6.3.0', false],
    'two ranges, the second one' => ['5.3.0 - 5.3.7, 6.0.0 - 6.3.0', '6.3.0', true],
    'two ranges, between them' => ['5.3.0 - 5.3.7, 6.0.0 - 6.3.0', '5.3.8', false],
    'one exact version' => ['6.3.0', '6.3.0', true],
    'another exact version' => ['= 6.2.0', '6.3.0', false],
    'no range at all counts as affected' => ['', '6.3.0', true],
]);

it('asks GitHub only for published advisories and ignores withdrawn ones', function (): void {
    $sandbox = upstreamAdvisoriesSandbox(['phpredis' => (string) json_encode([
        upstreamAdvisoriesGitHub('CVE-2099-2003', '< 7.0.0', '7.0.0', '2026-01-01T00:00:00Z'),
    ])]);

    $result = upstreamAdvisoriesRun($sandbox, ['phpredis=6.3.0', 'headers-more=0.41']);

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and($result->output())->not->toContain('CVE-2099-2003')
        ->and((string) file_get_contents($sandbox['dir'].'/gh-calls'))
        ->toContain('repos/phpredis/phpredis/security-advisories?state=published&per_page=100')
        ->toContain('repos/openresty/headers-more-nginx-module/security-advisories?state=published&per_page=100');
});

it('skips the advisories that cannot apply to the image and says why', function (): void {
    $sandbox = upstreamAdvisoriesSandbox([
        'curl' => (string) json_encode([upstreamAdvisoriesCurl('CVE-2099-0002', [['introduced' => '7.0.0'], ['fixed' => '8.25.0']], 'tool', 'command line tool only')]),
        'nginx' => upstreamAdvisoriesNginxPage([
            upstreamAdvisoriesNginx('CVE-2009-4487', 'all', 'none', 'none', 'An error log data are not sanitized'),
            upstreamAdvisoriesNginx('CVE-2099-1002', 'nginx/Windows 0.7.52-1.31.8', '1.31.9+'),
        ]),
    ]);

    $result = upstreamAdvisoriesRun($sandbox, ['libcurl=8.23.0', 'nginx=1.31.8']);

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and($result->output())->not->toContain('ÉRINTETT')
        ->and($result->output())->toMatch('/KIHAGYVA\s+libcurl 8\.23\.0\s+CVE-2099-0002\b.*curl parancssori eszköz/u')
        ->and($result->output())->toMatch('/KIHAGYVA\s+nginx 1\.31\.8\s+CVE-2009-4487\b.*Severity: none/u')
        ->and($result->output())->toMatch('/KIHAGYVA\s+nginx 1\.31\.8\s+CVE-2099-1002\b.*Windows/u');
});

it('fails loudly when an advisory feed changes its format or cannot be read', function (string $component, array $feeds, string $version): void {
    $sandbox = upstreamAdvisoriesSandbox($feeds);
    if (isset($feeds['unreachable'])) {
        unlink($sandbox['dir'].'/'.$feeds['unreachable']);
    }

    $result = upstreamAdvisoriesRun($sandbox, ["{$component}={$version}"]);

    expect($result->exitCode())->toBe(3, $result->output().$result->errorOutput())
        ->and($result->errorOutput())->toContain($component)
        ->and($result->output())->not->toContain('Nincs érintett');
})->with([
    'curl: an object instead of the OSV list' => ['libcurl', ['curl' => '{"vulnerabilities": []}'], '8.23.0'],
    'curl: an entry without a version range' => ['libcurl', ['curl' => (string) json_encode([['id' => 'CURL-CVE-2099-0003', 'aliases' => ['CVE-2099-0003'], 'affected' => [['ranges' => [['type' => 'GIT', 'events' => [['introduced' => 'abc']]]]]]]])], '8.23.0'],
    'curl: an unknown range event' => ['libcurl', ['curl' => (string) json_encode([upstreamAdvisoriesCurl('CVE-2099-0004', [['introduced' => '8.0.0'], ['patched' => '8.24.0']])])], '8.23.0'],
    'nginx: a page without advisories' => ['nginx', ['nginx' => '<html><body><ul><li>moved</li></ul></body></html>'], '1.31.8'],
    'nginx: an unreadable vulnerable range' => ['nginx', ['nginx' => upstreamAdvisoriesNginxPage([upstreamAdvisoriesNginx('CVE-2099-1003', 'since 1.25', '1.31.9+')])], '1.31.8'],
    'nginx: an advisory without a vulnerable line' => ['nginx', ['nginx' => upstreamAdvisoriesNginxPage([str_replace('<br>Vulnerable: 1.0.0-1.31.8', '', upstreamAdvisoriesNginx('CVE-2099-1004', '1.0.0-1.31.8', '1.31.9+'))])], '1.31.8'],
    // A page that changes for some advisories only must not hide them behind the ones still read.
    'nginx: one advisory written over two lines' => ['nginx', ['nginx' => upstreamAdvisoriesNginxPage([
        upstreamAdvisoriesNginx('CVE-2099-1000', '1.25.0-1.29.6', '1.29.7+, 1.28.3+'),
        str_replace('<br>Severity:', "<br>\nSeverity:", upstreamAdvisoriesNginx('CVE-2099-1005', '1.0.0-1.31.8', '1.31.9+')),
    ])], '1.31.8'],
    'nginx: one advisory without a severity' => ['nginx', ['nginx' => upstreamAdvisoriesNginxPage([
        upstreamAdvisoriesNginx('CVE-2099-1000', '1.25.0-1.29.6', '1.29.7+, 1.28.3+'),
        str_replace('<br>Severity: medium', '', upstreamAdvisoriesNginx('CVE-2099-1006', '1.0.0-1.31.8', '1.31.9+')),
    ])], '1.31.8'],
    'php: an error instead of the release list' => ['php', ['php' => '{"error":"Unknown version"}'], '8.5.12'],
    'php: a list without the pinned version' => ['php', ['php' => upstreamAdvisoriesPhp(['8.4.30' => ['security']])], '8.5.12'],
    'GitHub: an object instead of the advisory list' => ['phpredis', ['phpredis' => '{"message":"Not Found"}'], '6.3.0'],
    'GitHub: an unreadable version range' => ['phpredis', ['phpredis' => (string) json_encode([upstreamAdvisoriesGitHub('CVE-2099-2004', '~6.3')])], '6.3.0'],
    'GitHub: the API call fails' => ['phpredis', ['unreachable' => 'gh-phpredis_phpredis.json'], '6.3.0'],
    'curl: the feed cannot be downloaded' => ['libcurl', ['unreachable' => 'vuln.json'], '8.23.0'],
]);

it('refuses an advisory exception without a reason or past its review date', function (string $line, string $problem): void {
    $sandbox = upstreamAdvisoriesSandbox();
    $line = str_replace('{soon}', upstreamAdvisoriesReviewDate(30), $line);

    $result = upstreamAdvisoriesRun($sandbox, ['libcurl=8.23.0'], upstreamAdvisoriesExceptions([
        'libcurl | CVE-2099-0005 | '.upstreamAdvisoriesReviewDate(30).' | kikapcsolva: --disable-ftp (a teszt kivétele)',
        $line,
    ]));

    // The bad line is named by its line number (the header comment and a blank line come first).
    expect($result->exitCode())->toBe(2, $result->output().$result->errorOutput())
        ->and($result->errorOutput())->toContain('exceptions:4')
        ->and($result->errorOutput())->toContain($problem)
        ->and($result->errorOutput())->not->toContain('exceptions:3');
})->with([
    'no reason' => ['libcurl | CVE-2099-0001 | {soon} |', 'indoklás'],
    'no reason column at all' => ['libcurl | CVE-2099-0001 | {soon}', 'indoklás'],
    'a review date in the past' => ['libcurl | CVE-2099-0001 | 2020-01-01 | kikapcsolva: --disable-ftp', 'lejárt'],
    'a review date more than a year ahead' => ['libcurl | CVE-2099-0001 | 2999-12-31 | kikapcsolva: --disable-ftp', 'egy év'],
    'a date that is no date' => ['libcurl | CVE-2099-0001 | jövőre | kikapcsolva: --disable-ftp', 'dátum'],
    'an unknown component' => ['curl-cli | CVE-2099-0001 | {soon} | kikapcsolva: --disable-ftp', 'komponens'],
    'the same advisory twice' => ['libcurl | cve-2099-0005 | {soon} | kikapcsolva: --disable-ftp, ugyanaz még egyszer', 'kétszer'],
]);

it('lists the advisories it waived together with their reasons', function (): void {
    $sandbox = upstreamAdvisoriesSandbox(['curl' => (string) json_encode([
        upstreamAdvisoriesCurl('CVE-2099-0006', [['introduced' => '8.0.0'], ['fixed' => '8.24.0']], 'lib', 'FTP state machine overflow'),
    ])]);
    $review = upstreamAdvisoriesReviewDate(30);

    $result = upstreamAdvisoriesRun($sandbox, ['libcurl=8.23.0'], upstreamAdvisoriesExceptions([
        "libcurl | CURL-CVE-2099-0006 | {$review} | kikapcsolva: --disable-ftp, az image libcurl-je FTP nélkül épül",
        "nginx | CVE-2099-1999 | {$review} | nincs befordítva: --without-http_ssi_module",
    ]));

    // The waiver matches the feed's own id as well as its CVE alias, and an unused one is pointed out.
    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and($result->output())->not->toContain('ÉRINTETT')
        ->and($result->output())->toMatch('/KIVÉTEL\s+libcurl 8\.23\.0\s+CVE-2099-0006\b.*kikapcsolva: --disable-ftp, az image libcurl-je FTP nélkül épül.*'.preg_quote($review, '/').'/u')
        ->and($result->output())->toContain('exceptions:3')
        ->and($result->output())->not->toContain('CVE-2099-1999');

    $all = upstreamAdvisoriesRun($sandbox, ['libcurl=8.23.0', 'nginx=1.31.8'], upstreamAdvisoriesExceptions([
        "libcurl | CVE-2099-0006 | {$review} | kikapcsolva: --disable-ftp, az image libcurl-je FTP nélkül épül",
        "nginx | CVE-2099-1999 | {$review} | nincs befordítva: --without-http_ssi_module",
    ]));

    expect($all->exitCode())->toBe(0, $all->output().$all->errorOutput())
        ->and($all->output())->toMatch('/nem használt kivétel.*exceptions:4.*CVE-2099-1999/u');
});

it('reads the pinned versions from the Dockerfile and accepts the exceptions it ships with', function (): void {
    $dockerfile = upstreamAdvisoriesRelease('Dockerfile');
    $arg = function (string $name) use ($dockerfile): string {
        preg_match('/^ARG '.$name.'=(\S+)$/m', $dockerfile, $match);

        return $match[1] ?? '';
    };
    preg_match('/^php:(\d+\.\d+\.\d+)-/', $arg('PHP_IMAGE'), $php);
    $versions = [
        'nginx' => $arg('NGINX_VERSION'),
        'libcurl' => $arg('CURL_VERSION'),
        'php' => $php[1] ?? '',
        'phpredis' => $arg('PHPREDIS_VERSION'),
        'headers-more' => $arg('HEADERS_MORE_VERSION'),
    ];
    expect(array_filter($versions))->toHaveCount(5);

    $sandbox = upstreamAdvisoriesSandbox(['php' => upstreamAdvisoriesPhp([$versions['php'] => ['security']])]);
    $result = upstreamAdvisoriesRun($sandbox, ['--dockerfile', (string) upstreamAdvisoriesPath('Dockerfile')]);

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
    foreach ($versions as $component => $version) {
        expect($result->output())->toContain("{$component} {$version}");
    }
});

it('audits the bundled JavaScript of the latest release tag every week', function (): void {
    $job = upstreamAdvisoriesJob(upstreamAdvisoriesRelease('.github/workflows/security-scan.yml'), 'upstream');
    $package = json_decode(upstreamAdvisoriesRelease('package.json'), true);

    expect(strpos($job, 'npm audit --package-lock-only --audit-level=high'))->toBeGreaterThan((int) strpos($job, 'ref: v${{ steps.image.outputs.release }}'));

    // vue and vue-router are bundled into the browser code but listed as build dependencies, like the
    // rest of the front end: an audit without the dev dependencies would audit nothing at all.
    expect(array_keys($package['devDependencies'] ?? []))->toContain('vue', 'vue-router')
        ->and($job)->not->toContain('--omit=dev');
});

it('audits the PHP dependencies of the latest release tag every week', function (): void {
    $job = upstreamAdvisoriesJob(upstreamAdvisoriesRelease('.github/workflows/security-scan.yml'), 'upstream');

    // The Composer of the tag's own Dockerfile, pinned by digest, audits the tag's lock file.
    expect($job)->toContain("composer_image=\$(sed -n 's/^ARG COMPOSER_IMAGE=//p' Dockerfile)")
        ->and($job)->toContain('"$composer_image" composer audit --locked --no-dev --format=plain')
        ->and(strpos($job, 'composer audit'))->toBeGreaterThan((int) strpos($job, 'ref: v${{ steps.image.outputs.release }}'));
});

it('lets the preflight publish the advisory script and lint it with the pinned shellcheck', function (): void {
    $preflight = upstreamAdvisoriesMain('deploy/openhdid/preflight.sh');
    preg_match('/^    github_extra=\$\(find \.github .*\)$/m', $preflight, $line);
    expect($line)->not->toBe([]);

    // The published tree may carry the advisory script, its exceptions file and the waivers of the helper
    // image scans, nothing else new.
    $tree = realpath(sys_get_temp_dir()).'/openhdid-advisories-tree-'.bin2hex(random_bytes(6));
    upstreamAdvisoriesFolders($tree);
    foreach (['.github/workflows/release.yml', '.github/scripts/upstream-advisories.sh', '.github/scripts/upstream-advisories.exceptions', '.github/scripts/helper-images.trivyignore.yaml', '.github/scripts/fetch.py', '.github/scripts/notes.yaml', '.github/dependabot.yml'] as $file) {
        File::ensureDirectoryExists(dirname($tree.'/'.$file));
        file_put_contents($tree.'/'.$file, "x\n");
    }
    $found = Process::path($tree)->run(['bash', '-c', $line[0].'; printf "%s\n" "$github_extra"']);

    expect($found->successful())->toBeTrue($found->errorOutput())
        ->and(array_filter(explode("\n", $found->output())))->toEqualCanonicalizing(['.github/scripts/fetch.py', '.github/scripts/notes.yaml', '.github/dependabot.yml']);

    expect($preflight)->toContain('"$SHELLCHECK_IMAGE" -s bash install.sh backup.sh .github/scripts/upstream-advisories.sh');

    // bash 3.2 to 5 under set -euo pipefail: no associative arrays or case conversion, no early-exiting readers.
    $script = upstreamAdvisoriesRelease('.github/scripts/upstream-advisories.sh');
    expect($script)->toContain('set -euo pipefail')
        ->and($script)->not->toMatch('/declare -A|mapfile|readarray|\$\{\w+(,,|\^\^)\}|\|\s*grep -q|\|\s*head\b/');
    expect(is_executable((string) upstreamAdvisoriesPath('.github/scripts/upstream-advisories.sh')))->toBeTrue();
});

it('runs the upstream advisory check in the dependency step of the preflight, also with --skip-image', function (): void {
    $preflight = upstreamAdvisoriesMain('deploy/openhdid/preflight.sh');
    preg_match('/^step "5\..*?^step "6\./ms', $preflight, $step);

    expect($step[0] ?? '')->toContain('bash .github/scripts/upstream-advisories.sh --dockerfile Dockerfile </dev/null || fail');
});

it('shows the upstream advisory result in platform.sh check without stopping the check', function (): void {
    $platform = upstreamAdvisoriesMain('deploy/openhdid/platform.sh');
    preg_match('/^if \[\[ \$MODE == check \]\]; then\n.*?^fi$/ms', $platform, $check);

    expect($check[0] ?? '')->toContain('bash "$HERE/repo/.github/scripts/upstream-advisories.sh" --dockerfile "$DOCKERFILE" </dev/null ||')
        ->and($check[0] ?? '')->toContain('exit 0');
});
