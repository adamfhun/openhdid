<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * The public commits and tags of a release carry the project's release identity (the GitHub account's
 * noreply address), never the local git user's, and no denylisted term (owner decision D5, F66).
 *
 * Each test builds a throwaway release setup: a main repository with release.sh, a test denylist, a
 * CHANGELOG with a [9.9.9] section and a fake preflight that writes a minimal export; a local bare
 * repository as origin with an already published v9.9.8 made under a personal identity; a clone of
 * it; a fake docker in front of PATH (the secret scan); a HOME whose git user is that personal identity.
 * release.sh lives in the main repository only, so the tests skip in an exported tree.
 */
const RELEASE_TEST_PERSON = 'Example Person';

const RELEASE_TEST_ADDRESS = 'person@example.net';

/**
 * @return array{base: string, main: string, origin: string, clone: string, bin: string, home: string, name: string, email: string}
 */
function releaseSandbox(): array
{
    $script = base_path('deploy/openhdid/release.sh');

    if (! is_file($script)) {
        test()->markTestSkipped('release.sh lives in the main repository only.');
    }

    $source = (string) file_get_contents($script);
    preg_match('/^RELEASE_NAME=(\S+)$/m', $source, $name);
    preg_match('/^RELEASE_EMAIL=(\S+)$/m', $source, $email);

    $base = realpath(sys_get_temp_dir()).'/openhdid-release-'.bin2hex(random_bytes(6));
    $sandbox = [
        'base' => $base,
        'main' => $base.'/hdid',
        'origin' => $base.'/origin.git',
        'clone' => $base.'/openhdid',
        'bin' => $base.'/bin',
        'home' => $base.'/home',
        'name' => $name[1] ?? '',
        'email' => $email[1] ?? '',
    ];
    foreach (['main', 'bin', 'home'] as $folder) {
        mkdir($sandbox[$folder], 0700, true);
    }
    releaseSandboxFolders($base);

    file_put_contents($sandbox['home'].'/.gitconfig', implode("\n", [
        '[user]', '    name = '.RELEASE_TEST_PERSON, '    email = '.RELEASE_TEST_ADDRESS,
        '[init]', '    defaultBranch = main',
        '[commit]', '    gpgSign = false',
        '[tag]', '    gpgSign = false',
    ])."\n");
    file_put_contents($sandbox['bin'].'/docker', "#!/bin/sh\nexit 0\n");
    chmod($sandbox['bin'].'/docker', 0755);

    $here = $sandbox['main'].'/deploy/openhdid';
    mkdir($here.'/repo', 0700, true);
    copy($script, $here.'/release.sh');
    chmod($here.'/release.sh', 0755);
    file_put_contents($here.'/denylist.txt', "# Test terms\n".RELEASE_TEST_PERSON."\n".RELEASE_TEST_ADDRESS."\n");
    file_put_contents($here.'/gitleaks.toml', "\n");
    file_put_contents($here.'/repo/CHANGELOG.md', "# Changelog\n\n## [9.9.9] – 2026-11-02\n\n### Magyar\n\n- Próbakiadás.\n\n### English\n\n- Trial release.\n");
    file_put_contents($here.'/preflight.sh', <<<'SH'
        #!/usr/bin/env bash
        # Writes the smallest export release.sh accepts.
        set -euo pipefail
        while [[ $# -gt 0 ]]; do
            case "$1" in
                --version) version=$2; shift 2 ;;
                --out) out=$2; shift 2 ;;
                *) shift ;;
            esac
        done
        mkdir -p "$out"
        printf 'OPENHDID_VERSION=%s\n' "$version" > "$out/.env.example"
        printf '#!/usr/bin/env bash\nOPENHDID_RELEASE=%s\n' "$version" > "$out/install.sh"
        printf '#!/usr/bin/env bash\n' > "$out/backup.sh"
        chmod 755 "$out/install.sh" "$out/backup.sh"
        printf '# OpenHDID %s\n' "$version" > "$out/README.md"
        SH);
    chmod($here.'/preflight.sh', 0755);

    releaseGit($sandbox, $sandbox['main'], ['init', '--quiet', '-b', 'main']);
    releaseGit($sandbox, $sandbox['main'], ['add', '-A']);
    releaseGit($sandbox, $sandbox['main'], ['commit', '--quiet', '-m', 'Main repository']);

    // The published history: v9.9.8 under the personal identity, as releases before D5 were made.
    releaseGit($sandbox, $base, ['init', '--quiet', '--bare', '-b', 'main', $sandbox['origin']]);
    releaseGit($sandbox, $base, ['clone', '--quiet', $sandbox['origin'], $sandbox['clone']]);
    file_put_contents($sandbox['clone'].'/README.md', "# OpenHDID 9.9.8\n");
    releaseGit($sandbox, $sandbox['clone'], ['add', '-A']);
    releaseGit($sandbox, $sandbox['clone'], ['commit', '--quiet', '-m', 'OpenHDID 9.9.8']);
    releaseGit($sandbox, $sandbox['clone'], ['tag', '-a', 'v9.9.8', '-m', 'OpenHDID 9.9.8']);
    releaseGit($sandbox, $sandbox['clone'], ['push', '--quiet', 'origin', 'main', 'v9.9.8']);

    return $sandbox;
}

/**
 * Runs git in the sandbox, with its HOME (the personal identity) unless $env names another.
 *
 * @param  array<string, string>  $sandbox
 * @param  list<string>  $arguments
 * @param  array<string, string|false>  $env
 */
function releaseGit(array $sandbox, string $folder, array $arguments, array $env = []): string
{
    return Process::path($folder)
        ->env([...releaseEnv($sandbox), ...$env])
        ->run(['git', ...$arguments])
        ->throw()
        ->output();
}

/**
 * @param  array<string, string>  $sandbox
 * @return array<string, string|false>
 */
function releaseEnv(array $sandbox): array
{
    return [
        'PATH' => $sandbox['bin'].':'.getenv('PATH'),
        'HOME' => $sandbox['home'],
        'XDG_CONFIG_HOME' => $sandbox['home'].'/.config',
        'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_CONFIG_GLOBAL' => $sandbox['home'].'/.gitconfig',
        'LANG' => 'C',
        'LC_ALL' => 'C',
        'TMPDIR' => $sandbox['base'],
        'GIT_AUTHOR_NAME' => false,
        'GIT_AUTHOR_EMAIL' => false,
        'GIT_COMMITTER_NAME' => false,
        'GIT_COMMITTER_EMAIL' => false,
    ];
}

/**
 * The prepared, not yet pushed v9.9.9: one commit and its annotated tag in the clone, made under the
 * given identity (the release identity, or the personal one when $name is null).
 *
 * @param  array<string, string>  $sandbox
 */
function releasePrepared(array $sandbox, ?string $name, ?string $email, string $tagMessage = 'OpenHDID 9.9.9'): void
{
    $identity = $name === null ? [] : [
        'GIT_AUTHOR_NAME' => $name, 'GIT_AUTHOR_EMAIL' => $email,
        'GIT_COMMITTER_NAME' => $name, 'GIT_COMMITTER_EMAIL' => $email,
    ];
    file_put_contents($sandbox['clone'].'/README.md', "# OpenHDID 9.9.9\n");
    releaseGit($sandbox, $sandbox['clone'], ['add', '-A']);
    releaseGit($sandbox, $sandbox['clone'], ['commit', '--quiet', '-m', "OpenHDID 9.9.9\n\n- Próbakiadás."], $identity);
    releaseGit($sandbox, $sandbox['clone'], ['tag', '-a', 'v9.9.9', '-m', $tagMessage], $identity);
}

/**
 * @param  array<string, string>  $sandbox
 */
function releaseRun(array $sandbox): ProcessResult
{
    return Process::path($sandbox['base'])
        ->env(releaseEnv($sandbox))
        ->input('')
        ->timeout(60)
        ->run(['bash', $sandbox['main'].'/deploy/openhdid/release.sh', '9.9.9', '--repo', $sandbox['clone']]);
}

/**
 * @param  array<string, string>  $sandbox
 */
function releaseMainTagged(array $sandbox): bool
{
    return Process::path($sandbox['main'])->env(releaseEnv($sandbox))
        ->run(['git', 'rev-parse', '--verify', '--quiet', 'refs/tags/openhdid-v9.9.9'])
        ->successful();
}

/**
 * The sandboxes of the running test, removed after it.
 *
 * @return list<string>
 */
function releaseSandboxFolders(?string $add = null, bool $forget = false): array
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
    foreach (releaseSandboxFolders(forget: true) as $folder) {
        File::deleteDirectory($folder);
    }
});

it('names the GitHub noreply address as the release identity', function (): void {
    $sandbox = releaseSandbox();

    expect($sandbox['name'])->toBe('adamfhun')
        ->and($sandbox['email'])->toMatch('/^\d+\+adamfhun@users\.noreply\.github\.com$/');
});

it('refuses to tag or push a public release whose new commit or tag carries a denylisted name or address', function (string $case): void {
    $sandbox = releaseSandbox();

    match ($case) {
        // Prepared with the local git user, as before the release identity.
        'personal identity' => releasePrepared($sandbox, null, null),
        // The right identity, but a denylisted address in the tag message.
        'denylisted tag message' => releasePrepared($sandbox, $sandbox['name'], $sandbox['email'], 'OpenHDID 9.9.9, ask '.RELEASE_TEST_ADDRESS),
    };

    $result = releaseRun($sandbox);
    $output = $result->output().$result->errorOutput();

    expect($result->exitCode())->not->toBe(0)
        ->and($output)->toContain('metaadat')
        ->and($output)->not->toContain(RELEASE_TEST_ADDRESS)
        ->and($output)->not->toContain(RELEASE_TEST_PERSON)
        ->and(releaseMainTagged($sandbox))->toBeFalse();
})->with(['personal identity', 'denylisted tag message']);

it('commits and tags the public release under the fixed noreply release identity', function (): void {
    $sandbox = releaseSandbox();
    // A clone that would sign with the local key: the release objects stay unsigned all the same.
    foreach ([['commit.gpgSign', 'true'], ['tag.gpgSign', 'true'], ['tag.forceSignAnnotated', 'true'], ['gpg.program', 'false']] as [$key, $value]) {
        releaseGit($sandbox, $sandbox['clone'], ['config', $key, $value]);
    }

    $result = releaseRun($sandbox);
    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());

    $identity = "{$sandbox['name']} <{$sandbox['email']}>";
    $commit = releaseGit($sandbox, $sandbox['clone'], ['cat-file', 'commit', 'HEAD']);
    $tag = releaseGit($sandbox, $sandbox['clone'], ['cat-file', 'tag', 'v9.9.9']);

    expect(releaseGit($sandbox, $sandbox['clone'], ['log', '-1', '--format=%an <%ae>%n%cn <%ce>%n%s']))
        ->toBe("{$identity}\n{$identity}\nOpenHDID 9.9.9\n")
        ->and($commit)->toMatch('/^author .+ [+-]0000$/m')->not->toContain('gpgsig')
        ->and($tag)->toMatch('/^tagger '.preg_quote($identity, '/').' \d+ \+0000$/m')->not->toContain('SIGNATURE')
        ->and(releaseMainTagged($sandbox))->toBeTrue();
});

it('does not stop a release on commits that are already published', function (): void {
    $sandbox = releaseSandbox();
    releasePrepared($sandbox, $sandbox['name'], $sandbox['email']);

    $result = releaseRun($sandbox);

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and(releaseMainTagged($sandbox))->toBeTrue();
});

it('sets the release identity in the public clone so manual changelog fixes carry it too', function (): void {
    $sandbox = releaseSandbox();
    releasePrepared($sandbox, $sandbox['name'], $sandbox['email']);

    releaseRun($sandbox)->throw();

    expect(trim(releaseGit($sandbox, $sandbox['clone'], ['config', '--local', 'user.name'])))->toBe($sandbox['name'])
        ->and(trim(releaseGit($sandbox, $sandbox['clone'], ['config', '--local', 'user.email'])))->toBe($sandbox['email']);
});
