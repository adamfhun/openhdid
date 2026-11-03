<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * The platform pins of the container release (deploy/openhdid/platform.sh, the Dockerfile, the scans).
 *
 * - A new curl or nginx source is pinned, and the curl source is built, only when a pinned key signed
 *   it: the downloaded key files may hold other keys, and those sign nothing (F44, TEL-4).
 * - Every image is pinned by digest in every file that names it, and a rebuilt image of the same tag
 *   is a change of its own; an unreachable registry never leaves an empty digest behind (F55).
 * - The bundled MariaDB and Valkey images are scanned in the preflight and every week (F55, TEL-5).
 *
 * platform.sh runs in a throwaway copy of deploy/openhdid with a fake docker and curl in front of PATH:
 * the fake curl serves every version feed with the versions pinned now, so only digests differ, and
 * the fake docker answers "buildx imagetools inspect" from a registry table. platform.sh and the
 * preflight live in the main repository only; the Dockerfile and the workflows also in an exported tree.
 */

/**
 * A release file: under deploy/openhdid/repo in the main repository, at the root of an exported tree.
 */
function platformScriptRelease(string $path): string
{
    foreach ([base_path('deploy/openhdid/repo/'.$path), base_path($path)] as $candidate) {
        if (is_file($candidate)) {
            return (string) file_get_contents($candidate);
        }
    }

    test()->markTestSkipped("{$path} is not part of this tree.");
}

function platformScriptMain(string $path): string
{
    if (! is_file(base_path($path))) {
        test()->markTestSkipped("{$path} lives in the main repository only.");
    }

    return (string) file_get_contents(base_path($path));
}

/**
 * Every pinned image reference in the files under a folder (not the Markdown texts): "name:tag" => "sha256:…".
 *
 * @return array<string, string>
 */
function platformScriptPins(string $folder): array
{
    $pins = [];
    foreach (File::allFiles($folder, true) as $file) {
        // A changelog may quote a digest; only the files that pin one count.
        if ($file->getExtension() === 'md') {
            continue;
        }
        preg_match_all('/([a-z0-9][a-z0-9.\/_-]*:[^@"\s]+)@(sha256:[0-9a-f]{64})/', $file->getContents(), $matches, PREG_SET_ORDER);
        foreach ($matches as [, $tag, $digest]) {
            $pins[$tag] = $digest;
        }
    }
    ksort($pins);

    return $pins;
}

/**
 * The content hash of every file under a folder, by relative path.
 *
 * @return array<string, string>
 */
function platformScriptHashes(string $folder): array
{
    $hashes = [];
    foreach (File::allFiles($folder, true) as $file) {
        $hashes[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
    }
    ksort($hashes);

    return $hashes;
}

function platformScriptFolders(?string $add = null, bool $forget = false): array
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
    foreach (platformScriptFolders(forget: true) as $folder) {
        File::deleteDirectory($folder);
    }
});

/**
 * The first pinned tag of an image ("mariadb" => "mariadb:12.3.3").
 *
 * @param  array<string, string>  $pins
 */
function platformScriptTag(array $pins, string $image): string
{
    foreach (array_keys($pins) as $tag) {
        if (str_starts_with($tag, $image.':')) {
            return $tag;
        }
    }

    return '';
}

/**
 * What the test registry answers: the pinned digests, except where a test says otherwise.
 *
 * @param  array{root: string, pins: array<string, string>}  $sandbox
 * @param  array<string, string|null>  $answers  "name:tag" => the digest the registry gives now; null: the
 *                                               lookup fails, '': it answers with an empty digest
 */
function platformScriptRegistry(array $sandbox, array $answers): void
{
    $table = [];
    foreach ([...$sandbox['pins'], ...$answers] as $tag => $digest) {
        $table[] = $tag.' '.($digest === null ? 'unreachable' : ($digest === '' ? 'empty' : $digest));
    }
    file_put_contents($sandbox['root'].'/registry', implode("\n", $table)."\n");
}

/**
 * A copy of deploy/openhdid in a repository of its own, a fake docker and curl, and the version feeds.
 *
 * @return array{root: string, here: string, pins: array<string, string>, before: array<string, string>}
 */
function platformScriptSandbox(): array
{
    if (! is_file(base_path('deploy/openhdid/platform.sh'))) {
        test()->markTestSkipped('platform.sh lives in the main repository only.');
    }

    $root = realpath(sys_get_temp_dir()).'/openhdid-platform-'.bin2hex(random_bytes(6));
    platformScriptFolders($root);
    $here = $root.'/deploy/openhdid';
    File::copyDirectory(base_path('deploy/openhdid'), $here);
    foreach (['bin', 'feeds'] as $folder) {
        mkdir($root.'/'.$folder, 0700, true);
    }
    Process::path($root)->run(['git', 'init', '-q']);

    $pins = platformScriptPins($here);
    platformScriptRegistry(['root' => $root, 'pins' => $pins], []);

    $dockerfile = (string) file_get_contents($here.'/repo/Dockerfile');
    $arg = function (string $name) use ($dockerfile): string {
        preg_match('/^ARG '.$name.'=(\S+)$/m', $dockerfile, $match);

        return $match[1] ?? '';
    };
    $tagOf = fn (string $image): string => substr(platformScriptTag($pins, $image), strlen($image) + 1);
    preg_match('/^php:([0-9.]+)-fpm-alpine([0-9.]+)@/', $arg('PHP_IMAGE'), $php);
    preg_match('/^node:([0-9.]+)-alpine/', $arg('NODE_IMAGE'), $node);
    preg_match('/^composer:([0-9.]+)@/', $arg('COMPOSER_IMAGE'), $composer);
    [$phpVersion, $alpine] = [$php[1] ?? '', $php[2] ?? ''];
    $maria = $tagOf('mariadb');
    $valkey = $tagOf('valkey/valkey');
    $branch = fn (string $version): string => implode('.', array_slice(explode('.', $version), 0, 2));
    $release = fn (string $tag): string => json_encode(['tag_name' => 'v'.ltrim($tag, 'v')]);

    $feeds = [
        'https://endoflife.date/api/php.json' => json_encode([['cycle' => $branch($phpVersion), 'latest' => $phpVersion]]),
        'https://endoflife.date/api/alpine-linux.json' => json_encode([['cycle' => $alpine, 'eol' => '2999-12-31']]),
        'https://endoflife.date/api/nodejs.json' => json_encode([['cycle' => explode('.', $node[1] ?? '')[0], 'lts' => '2000-01-01', 'eol' => '2999-12-31', 'latest' => $node[1] ?? '']]),
        'https://api.github.com/repos/composer/composer/releases/latest' => json_encode(['tag_name' => $composer[1] ?? '']),
        'https://nginx.org/en/download.html' => '<a href="/download/nginx-'.$arg('NGINX_VERSION').'.tar.gz">nginx-'.$arg('NGINX_VERSION').'</a>',
        'https://api.github.com/repos/openresty/headers-more-nginx-module/tags?per_page=20' => json_encode([['name' => 'v'.$arg('HEADERS_MORE_VERSION')]]),
        'https://api.github.com/repos/phpredis/phpredis/releases/latest' => json_encode(['tag_name' => $arg('PHPREDIS_VERSION')]),
        'https://curl.se/info' => 'Version: '.$arg('CURL_VERSION')."\n",
        'https://endoflife.date/api/mariadb.json' => json_encode([['cycle' => $branch($maria), 'latest' => $maria, 'lts' => true, 'releaseDate' => '2000-01-01']]),
        "https://hub.docker.com/v2/repositories/valkey/valkey/tags?page_size=100&name=-alpine{$alpine}" => json_encode(['results' => [['name' => $valkey]]]),
        'https://api.github.com/repos/gitleaks/gitleaks/releases/latest' => $release($tagOf('ghcr.io/gitleaks/gitleaks')),
        'https://api.github.com/repos/aquasecurity/trivy/releases/latest' => $release($tagOf('ghcr.io/aquasecurity/trivy')),
        'https://api.github.com/repos/hadolint/hadolint/releases/latest' => $release($tagOf('ghcr.io/hadolint/hadolint')),
        'https://api.github.com/repos/yannh/kubeconform/releases/latest' => $release($tagOf('ghcr.io/yannh/kubeconform')),
        'https://api.github.com/repos/koalaman/shellcheck/releases/latest' => $release($tagOf('koalaman/shellcheck')),
        'https://api.github.com/repos/kubernetes-sigs/kustomize/releases?per_page=30' => json_encode([['tag_name' => 'kustomize/'.$tagOf('registry.k8s.io/kustomize/kustomize')]]),
    ];
    $index = [];
    foreach (array_values(array_keys($feeds)) as $number => $url) {
        file_put_contents($root.'/feeds/'.$number, $feeds[$url]);
        $index[] = $url."\t".$number;
    }
    file_put_contents($root.'/feeds/index', implode("\n", $index)."\n");

    file_put_contents($root.'/bin/docker', <<<'BASH'
#!/usr/bin/env bash
# The registry of the platform.sh tests: "buildx imagetools inspect <ref>" answers from the table.
dir=$(cd "$(dirname "$0")/.." && pwd)
printf 'docker %s\n' "$*" >> "$dir/calls"
if [[ ${1-} == buildx && ${2-} == imagetools && ${3-} == inspect ]]; then
    while read -r ref digest; do
        [[ $ref == "${4-}" ]] || continue
        case $digest in
            unreachable) echo "ERROR: failed to do request: dial tcp: i/o timeout" >&2; exit 1 ;;
            empty) echo '""'; exit 0 ;;
            *) printf '"%s"\n' "$digest"; exit 0 ;;
        esac
    done < "$dir/registry"
    echo "ERROR: ${4-}: not found" >&2
    exit 1
fi
# The signature check in the pinned Alpine: the verdict is the "signature" file of the test.
if [[ ${1-} == run && -f $dir/signature ]]; then
    if [[ $(cat "$dir/signature") == good ]]; then
        echo 'gpgv: Good signature from "Test Signer <signer@example.invalid>"'
        exit 0
    fi
    echo "gpgv: Can't check signature: No public key" >&2
    exit 2
fi
echo "unexpected docker call: $*" >&2
exit 1
BASH);
    file_put_contents($root.'/bin/curl', <<<'BASH'
#!/usr/bin/env bash
# The feeds of the platform.sh tests: a known URL is served from feeds/, anything else is a 404.
dir=$(cd "$(dirname "$0")/.." && pwd)
target="" url=""
while [[ $# -gt 0 ]]; do
    case $1 in
        -o) target=$2; shift 2 ;;
        --retry) shift 2 ;;
        -*) shift ;;
        *) url=$1; shift ;;
    esac
done
printf 'curl %s\n' "$url" >> "$dir/calls"
while IFS=$'\t' read -r known file; do
    [[ $known == "$url" ]] || continue
    if [[ -n $target ]]; then cp "$dir/feeds/$file" "$target"; else cat "$dir/feeds/$file"; fi
    exit 0
done < "$dir/feeds/index"
echo "curl: (22) The requested URL returned error: 404" >&2
exit 22
BASH);
    chmod($root.'/bin/docker', 0755);
    chmod($root.'/bin/curl', 0755);

    return ['root' => $root, 'here' => $here, 'pins' => $pins, 'before' => platformScriptHashes($here)];
}

/**
 * Serve other answers for some feed URLs (or new ones: a download, a signature, a key file).
 *
 * @param  array{root: string}  $sandbox
 * @param  array<string, string>  $feeds  URL => body
 */
function platformScriptFeeds(array $sandbox, array $feeds): void
{
    $index = array_filter(explode("\n", (string) file_get_contents($sandbox['root'].'/feeds/index')));
    foreach ($feeds as $url => $body) {
        $index = array_filter($index, fn (string $line): bool => ! str_starts_with($line, $url."\t"));
        $file = 'extra-'.bin2hex(random_bytes(6));
        file_put_contents($sandbox['root'].'/feeds/'.$file, $body);
        $index[] = $url."\t".$file;
    }
    file_put_contents($sandbox['root'].'/feeds/index', implode("\n", $index)."\n");
}

/**
 * @param  array{root: string, here: string}  $sandbox
 */
function platformScriptRun(array $sandbox, string $mode): ProcessResult
{
    // The script's temporary folders (downloads) stay inside the sandbox, also when it stops.
    File::ensureDirectoryExists($sandbox['root'].'/tmp');

    return Process::path($sandbox['root'])
        ->env([
            'PATH' => $sandbox['root'].'/bin:'.getenv('PATH'),
            'HOME' => $sandbox['root'],
            'TMPDIR' => $sandbox['root'].'/tmp',
            'LC_ALL' => 'C',
            'GIT_CONFIG_NOSYSTEM' => '1',
        ])
        ->timeout(60)
        ->run(['bash', $sandbox['here'].'/platform.sh', $mode]);
}

/**
 * The digest a test registry gives for a rebuilt image of the same tag.
 */
function platformScriptRebuilt(string $tag): string
{
    return 'sha256:'.hash('sha256', 'rebuilt '.$tag);
}

it('reports a rebuilt image of the same version as a change and repins it on its own', function (): void {
    $sandbox = platformScriptSandbox();
    $maria = platformScriptTag($sandbox['pins'], 'mariadb');
    $old = $sandbox['pins'][$maria];
    $new = platformScriptRebuilt($maria);
    platformScriptRegistry($sandbox, [$maria => $new]);

    $check = platformScriptRun($sandbox, 'check');

    expect($check->exitCode())->toBe(0, $check->output().$check->errorOutput())
        ->and($check->output())->toContain($maria)
        ->and($check->output())->toContain('új digest')
        ->and($check->output())->toContain('1 frissíthető összetevő')
        ->and($check->output())->not->toContain('Minden rögzítés naprakész');

    $apply = platformScriptRun($sandbox, 'apply');
    $compose = (string) file_get_contents($sandbox['here'].'/repo/compose.yaml');
    $infra = (string) file_get_contents($sandbox['here'].'/repo/profiles/10k/infra.yaml');

    expect($apply->exitCode())->toBe(0, $apply->output().$apply->errorOutput())
        ->and($compose)->toContain("{$maria}@{$new}")->not->toContain($old)
        ->and($infra)->toContain("{$maria}@{$new}")->not->toContain($old);

    // Only the files that pin MariaDB changed, and nothing was downloaded or run.
    $changed = array_keys(array_diff_assoc(platformScriptHashes($sandbox['here']), $sandbox['before']));
    expect($changed)->toEqualCanonicalizing(['repo/compose.yaml', 'repo/profiles/10k/infra.yaml'])
        ->and((string) file_get_contents($sandbox['root'].'/calls'))->not->toContain('docker run');
});

it('repins every image in every file that pins it when the registry has rebuilt them all', function (): void {
    $sandbox = platformScriptSandbox();
    $tags = array_keys($sandbox['pins']);
    $rebuilt = array_combine($tags, array_map('platformScriptRebuilt', $tags));
    platformScriptRegistry($sandbox, $rebuilt);

    $check = platformScriptRun($sandbox, 'check');

    expect($check->exitCode())->toBe(0, $check->output().$check->errorOutput())
        ->and($check->output())->toContain(count($sandbox['pins']).' frissíthető összetevő');

    $apply = platformScriptRun($sandbox, 'apply');

    expect($apply->exitCode())->toBe(0, $apply->output().$apply->errorOutput());

    // Any file still naming an old digest pins an image that platform.sh does not manage there.
    $left = [];
    foreach (File::allFiles($sandbox['here'], true) as $file) {
        foreach ($sandbox['pins'] as $tag => $digest) {
            if ($file->getExtension() !== 'md' && str_contains($file->getContents(), $digest)) {
                $left[] = $file->getRelativePathname().": {$tag}";
            }
        }
    }
    expect($left)->toBe([])
        ->and(platformScriptPins($sandbox['here']))->toBe($rebuilt);
});

it('never writes an empty digest into a pin when the registry gives none', function (?string $answer): void {
    $sandbox = platformScriptSandbox();
    $maria = platformScriptTag($sandbox['pins'], 'mariadb');
    $valkey = platformScriptTag($sandbox['pins'], 'valkey/valkey');
    // MariaDB cannot be looked up, while Valkey was rebuilt: nothing may be written, not even Valkey.
    platformScriptRegistry($sandbox, [$maria => $answer, $valkey => platformScriptRebuilt($valkey)]);

    $check = platformScriptRun($sandbox, 'check');

    expect($check->exitCode())->not->toBe(0)
        ->and($check->output().$check->errorOutput())->toContain($maria)
        ->and($check->output())->not->toContain('Minden rögzítés naprakész');

    $apply = platformScriptRun($sandbox, 'apply');

    expect($apply->exitCode())->not->toBe(0)
        ->and($apply->output().$apply->errorOutput())->toContain($maria)
        ->and($apply->output().$apply->errorOutput())->toContain('semmi nem íródott át')
        ->and(platformScriptHashes($sandbox['here']))->toBe($sandbox['before']);
})->with([
    'the lookup fails' => [null],
    'the registry answers with an empty digest' => [''],
]);

it('pins a new nginx source only after a pinned key signed it, and writes nothing at all when none did', function (bool $signed): void {
    $sandbox = platformScriptSandbox();
    $maria = platformScriptTag($sandbox['pins'], 'mariadb');
    $rebuilt = platformScriptRebuilt($maria);
    // A rebuilt MariaDB waits to be repinned while a newer nginx mainline release comes out.
    platformScriptRegistry($sandbox, [$maria => $rebuilt]);
    preg_match('/^ARG NGINX_VERSION=(\d+)\.(\d+)\.(\d+)$/m', (string) file_get_contents($sandbox['here'].'/repo/Dockerfile'), $current);
    $version = ($current[1] ?? '1').'.'.($current[2] ?? '0').'.'.(((int) ($current[3] ?? 0)) + 1);
    $tarball = "nginx {$version} source\n";
    platformScriptFeeds($sandbox, [
        'https://nginx.org/en/download.html' => "<a href=\"/download/nginx-{$version}.tar.gz\">nginx-{$version}</a>",
        "https://nginx.org/download/nginx-{$version}.tar.gz" => $tarball,
        "https://nginx.org/download/nginx-{$version}.tar.gz.asc" => "signature\n",
        'https://nginx.org/en/pgp_keys.html' => '<a href="/keys/pluknet.key">key</a> <a href="/keys/added.key">key</a>',
        'https://nginx.org/keys/pluknet.key' => "pinned key\n",
        'https://nginx.org/keys/added.key' => "added key\n",
    ]);
    file_put_contents($sandbox['root'].'/signature', $signed ? 'good' : 'refused');
    preg_match('/^NGINX_PGP_FINGERPRINTS=\(([^)]*)\)$/m', (string) file_get_contents($sandbox['here'].'/platform.sh'), $pinned);

    $apply = platformScriptRun($sandbox, 'apply');

    if ($signed) {
        $dockerfile = (string) file_get_contents($sandbox['here'].'/repo/Dockerfile');
        expect($apply->exitCode())->toBe(0, $apply->output().$apply->errorOutput())
            ->and($apply->output())->toContain('aláíró: "Test Signer')
            ->and($dockerfile)->toContain("ARG NGINX_VERSION={$version}\n")
            ->and($dockerfile)->toContain('ARG NGINX_SHA256='.hash('sha256', $tarball)."\n")
            ->and((string) file_get_contents($sandbox['here'].'/repo/compose.yaml'))->toContain("{$maria}@{$rebuilt}");
    } else {
        // Not even the rebuilt MariaDB, which needs no signature, is written.
        expect($apply->exitCode())->not->toBe(0)
            ->and(platformScriptHashes($sandbox['here']))->toBe($sandbox['before'])
            ->and($apply->errorOutput())->toContain('NGINX_PGP_FINGERPRINTS');
    }

    // The check runs over the downloaded key files with the pinned fingerprints, nothing else.
    expect((string) file_get_contents($sandbox['root'].'/calls'))->toContain('sh nginx.tar.gz '.trim($pinned[1] ?? ''));
})->with([
    'a pinned key signed it' => [true],
    'no pinned key signed it' => [false],
]);

it('verifies the curl source against the pinned signing key alone at build time', function (): void {
    $dockerfile = platformScriptRelease('Dockerfile');
    preg_match('/^FROM \S+ AS libcurl$(.*?)^FROM /ms', $dockerfile, $stage);
    $libcurl = $stage[1] ?? '';

    expect($libcurl)->toContain('gpg --batch --export "${CURL_PGP_FINGERPRINT}" > pinned.gpg')
        ->and($libcurl)->toContain('gpgv --keyring ./pinned.gpg curl.tar.xz.asc curl.tar.xz')
        // A short key id or an empty value would export other keys too (or every key).
        ->and($libcurl)->toContain('${#CURL_PGP_FINGERPRINT}" -eq 40')
        ->and($libcurl)->not->toMatch('/gpg\s+(?:--\S+\s+)*--verify\b/');

    preg_match('/^ARG CURL_PGP_FINGERPRINT=(\S+)$/m', $dockerfile, $fingerprint);
    expect($fingerprint[1] ?? '')->toMatch('/^[0-9A-F]{40}$/');
});

it('keeps pipes into an early-exiting grep or head out of every pipefail stage of the Dockerfile', function (): void {
    $dockerfile = (string) preg_replace('/\\\\\n/', ' ', platformScriptRelease('Dockerfile'));
    $offending = array_values(array_filter(
        explode("\n", $dockerfile),
        fn (string $line): bool => str_starts_with($line, 'RUN ') && preg_match('/\|\s*(?:grep\s+(?:-[a-zA-Z]*q|--quiet)|head\b)/', $line) === 1,
    ));

    expect($offending)->toBe([]);
});

it('keeps pipes into an early-exiting grep or head out of platform.sh', function (): void {
    $platform = platformScriptMain('deploy/openhdid/platform.sh');

    expect(preg_match_all('/\|\s*(?:grep\s+(?:-[a-zA-Z]*q|--quiet)|head\b)/', $platform))->toBe(0);
});

it('lets platform.sh pin a new curl or nginx source only when a pinned key signed it', function (): void {
    $platform = platformScriptMain('deploy/openhdid/platform.sh');
    preg_match('/^NGINX_PGP_FINGERPRINTS=\(([^)]*)\)$/m', $platform, $nginx);
    $fingerprints = preg_split('/\s+/', trim($nginx[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    expect($fingerprints)->not->toBeEmpty();
    foreach ($fingerprints as $fingerprint) {
        expect($fingerprint)->toMatch('/^[0-9A-F]{40}$/');
    }

    expect($platform)->toContain('pgp_verify "$work" nginx.tar.gz "${NGINX_PGP_FINGERPRINTS[@]}"')
        ->and($platform)->toContain('pgp_verify "$work" curl.tar.xz "$(arg CURL_PGP_FINGERPRINT)"')
        ->and($platform)->toContain('gpg --batch --export "$fingerprint"')
        ->and($platform)->toContain('gpgv --keyring "$GNUPGHOME/pinned.gpg" "$file.asc" "$file"')
        // The whole downloaded key file never decides: no gpg --verify anywhere.
        ->and($platform)->not->toMatch('/gpg\s+(?:--\S+\s+)*--verify\b/');
});

it('refuses a signature by a key that the downloaded key file adds beside the pinned one', function (): void {
    $script = base_path('deploy/openhdid/platform.sh');
    if (! is_file($script)) {
        test()->markTestSkipped('platform.sh lives in the main repository only.');
    }
    if (! Process::run(['sh', '-c', 'command -v gpg && command -v gpgv && command -v gpgconf'])->successful()) {
        test()->markTestSkipped('GnuPG (gpg, gpgv) is not installed here; the preflight runs the same probe in the pinned Alpine.');
    }

    $probe = Process::timeout(120)->env(['LC_ALL' => 'C'])->run(['bash', $script, 'probe', '--local']);

    expect($probe->exitCode())->toBe(0, $probe->output().$probe->errorOutput())
        ->and($probe->output())->toContain('elutasítva');
});

it('runs the signature probe in the pinned Alpine before every release', function (): void {
    $preflight = platformScriptMain('deploy/openhdid/preflight.sh');

    expect($preflight)->toContain('"$HERE/platform.sh" probe </dev/null');
});

it('scans the bundled MariaDB and Valkey images in the preflight, without stopping the release', function (): void {
    $preflight = platformScriptMain('deploy/openhdid/preflight.sh');
    preg_match('/^step "7\..*?^step "8\./ms', $preflight, $step);
    $seven = $step[0] ?? '';

    expect($seven)->toContain('compose.yaml profiles/*/*.yaml')
        ->and($seven)->toContain('for ref in $helper_images; do')
        ->and($seven)->toContain('"$TRIVY_IMAGE" image --scanners vuln --severity HIGH,CRITICAL --ignore-unfixed')
        ->and($seven)->toContain('figyelem:');
});

it('scans the bundled MariaDB and Valkey images of the latest release every week', function (): void {
    $scan = platformScriptRelease('.github/workflows/security-scan.yml');
    $root = is_dir(base_path('deploy/openhdid/repo')) ? base_path('deploy/openhdid/repo') : base_path();

    // Every release file that pins a helper image is read under the name the release attaches it.
    $assets = [];
    foreach (['compose.yaml', ...array_map(fn (string $file): string => substr($file, strlen($root) + 1), glob($root.'/profiles/*/*.yaml') ?: [])] as $file) {
        if (preg_match('/^\s*image:\s*\S+@sha256:[0-9a-f]{64}\s*$/m', (string) file_get_contents($root.'/'.$file))) {
            [, $folder, $name] = array_pad(explode('/', $file), 3, '');
            $assets[] = $file === 'compose.yaml' ? 'compose.yaml' : ($name === 'compose.yaml' ? "profile-{$folder}.yaml" : "profile-{$folder}-".basename($name, '.yaml').'.yaml');
        }
    }

    expect($assets)->toContain('compose.yaml', 'profile-10k-infra.yaml');
    foreach ($assets as $asset) {
        expect($scan)->toContain('"$base/'.$asset.'"');
    }
    expect($scan)->toContain('releases/latest/download')
        ->and($scan)->toContain('for ref in $refs; do')
        // One database download for every image of the job: a failed second download would be a false alert.
        ->and($scan)->toContain('docker run --rm -v trivy-cache:/root/.cache "$TRIVY_IMAGE" image --platform "$PLATFORM" --scanners vuln --severity HIGH,CRITICAL --ignore-unfixed --exit-code 1');
});
