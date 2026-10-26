<?php

use Illuminate\Support\Facades\Process;
use Tests\Support\InstallerSandbox;

/*
 * The OpenHDID installer (install.sh at the root of the release): every
 * profile is installed into a throwaway folder with a fake docker in front of
 * PATH, so what the script writes and every command it runs can be checked
 * without Docker. The release files come from this tree (--from), or from a
 * release-like folder with SHA256SUMS that the fake curl downloads from.
 */

afterEach(fn () => InstallerSandbox::cleanUpAll());

/**
 * The answers of a trial installation that needs no question.
 *
 * @param  array<string, string>  $extra
 * @return array<string, string>
 */
function trialAnswers(array $extra = []): array
{
    return ['PROFILE' => 'trial', 'APP_URL' => 'https://hdid.example.org', 'HTTPS_PORT' => '443', 'HTTP_PORT' => '80', 'BIND_IP' => '0.0.0.0', 'DEMO_DATA' => 'true', ...$extra];
}

/**
 * The answers of a 5k installation behind a TLS-terminating load balancer.
 *
 * @param  array<string, string>  $extra
 * @return array<string, string>
 */
function fiveKAnswers(array $extra = []): array
{
    return ['PROFILE' => '5k', 'APP_URL' => 'https://hdid.example.org', 'TLS_MODE' => 'off', 'LB_ADDRESSES' => '10.0.0.2', ...$extra];
}

/**
 * The shared answers file of a two-host 10k installation without an infra host.
 *
 * @return array<string, string>
 */
function tenKSharedAnswers(): array
{
    return [
        'ANSWERS_FORMAT' => '1', 'PROFILE' => '10k-ha', 'OPENHDID_VERSION' => '1.15.0', 'APP_KEY' => InstallerSandbox::FAKE_APP_KEY,
        'APP_URL' => 'https://hdid.example.org', 'LB_ADDRESSES' => '10.0.0.2', 'APP_HOSTS' => '10.0.0.11,10.0.0.12',
        'NFS_SERVER' => '10.0.0.5', 'NFS_PATH' => '/export/openhdid-storage', 'DB_HOST' => '10.0.0.5', 'DB_PORT' => '3306',
        'DB_DATABASE' => 'openhdid', 'DB_USERNAME' => 'openhdid', 'DB_PASSWORD' => str_repeat('d', 48),
        'DB_MIGRATE_USERNAME' => 'openhdid_migrate', 'DB_MIGRATE_PASSWORD' => str_repeat('m', 48),
        'REDIS_HOST' => '10.0.0.5', 'REDIS_PORT' => '6379', 'REDIS_PASSWORD' => str_repeat('r', 48),
        'REDIS_SESSION_HOST' => '10.0.0.5', 'REDIS_SESSION_PORT' => '6380', 'REDIS_SESSION_PASSWORD' => str_repeat('s', 48),
        'REDIS_PREFIX' => 'openhdid-a1b2c3-',
    ];
}

function installerFile(InstallerSandbox $sandbox, string $file = '.env'): string
{
    return (string) file_get_contents($sandbox->dir.'/'.$file);
}

/**
 * The value of a key in an env file the installer wrote.
 */
function installerEnvValue(string $env, string $key): ?string
{
    return preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $env, $match) ? trim($match[1], "'") : null;
}

/**
 * The comment lines of an env file that are prose, not a commented-out sample KEY=value.
 *
 * @return list<string>
 */
function installerProseComments(string $env): array
{
    return array_values(array_filter(preg_split('/\R/', $env), fn (string $line): bool => str_starts_with($line, '#') && ! preg_match('/^# ?[A-Z][A-Z0-9_]*=/', $line)));
}

/**
 * Whether $needles appear in $haystack in this order (other lines may come between).
 *
 * @param  list<string>  $needles
 * @param  list<string>  $haystack
 */
function installerInOrder(array $needles, array $haystack): bool
{
    $position = 0;
    foreach ($needles as $needle) {
        while ($position < count($haystack) && $haystack[$position] !== $needle) {
            $position++;
        }
        if ($position === count($haystack)) {
            return false;
        }
        $position++;
    }

    return true;
}

it('installs the trial profile from an answers file without questions', function (): void {
    $sandbox = new InstallerSandbox;
    $answers = $sandbox->answers(trialAnswers());

    $result = $sandbox->run(['--answers', $answers, '--from', $sandbox->release, '--version', '1.15.0', '--lang', 'en', '--yes']);

    expect($result->exitCode())->toBeIn([0, 1], $result->output().$result->errorOutput());
    $env = installerFile($sandbox);
    expect($sandbox->mode($sandbox->dir.'/.env'))->toBe('600')
        ->and($sandbox->mode($sandbox->dir.'/db.env'))->toBe('600')
        ->and(installerEnvValue($env, 'OPENHDID_TLS'))->toBe('selfsigned')
        ->and(installerEnvValue($env, 'APP_ENV'))->toBe('demo')
        ->and(installerEnvValue($env, 'OPENHDID_DEMO_DATA'))->toBe('true')
        ->and(installerEnvValue($env, 'OPENHDID_VERSION'))->toBe('1.15.0')
        ->and(installerEnvValue($env, 'COMPOSE_PROFILES'))->toBe('db,redis')
        // No usage profile: the sample line stays, commented out.
        ->and(installerEnvValue($env, 'COMPOSE_FILE'))->toBeNull()
        ->and($env)->toMatch('/^# COMPOSE_FILE=compose\.yaml:profiles\/5k\/compose\.yaml$/m')
        ->and(installerEnvValue($env, 'APP_KEY'))->toBe(InstallerSandbox::FAKE_APP_KEY)
        ->and(installerEnvValue($env, 'REDIS_PASSWORD'))->toBe(InstallerSandbox::fakeSecret('REDIS_PASSWORD'))
        ->and(installerEnvValue($env, 'SESSION_DRIVER'))->toBe('redis')
        ->and(installerEnvValue(installerFile($sandbox, 'db.env'), 'MARIADB_ROOT_PASSWORD'))->toBe(InstallerSandbox::fakeSecret('MARIADB_ROOT_PASSWORD'))
        ->and(installerEnvValue(installerFile($sandbox, 'db.env'), 'DB_PASSWORD'))->toBe(InstallerSandbox::fakeSecret('DB_MIGRATE_PASSWORD'))
        ->and($sandbox->callLines('docker'))->toContain('docker compose config --quiet', 'docker compose up -d --wait --wait-timeout 600')
        ->and(is_file($sandbox->dir.'/compose.yaml'))->toBeTrue()
        ->and(is_executable($sandbox->dir.'/backup.sh'))->toBeTrue()
        ->and(is_dir($sandbox->dir.'/tls') && is_dir($sandbox->dir.'/ca'))->toBeTrue()
        ->and(installerFile($sandbox, '.install/state'))->toContain('profile=trial', 'openhdid_version=1.15.0')
        ->and($result->output())->toContain('[12/12]', 'docker compose down -v');
});

it('keeps every comment line of env.example in the generated .env', function (): void {
    $sandbox = new InstallerSandbox;
    $answers = $sandbox->answers(fiveKAnswers(['EXEMPT_NETWORKS' => '192.0.2.0/24 198.51.100.7']));

    $result = $sandbox->run(['--answers', $answers, '--from', $sandbox->release, '--version', '1.15.0', '--lang', 'hu', '--yes']);

    expect($result->exitCode())->toBeIn([0, 1], $result->output().$result->errorOutput());
    $example = (string) file_get_contents($sandbox->release.'/.env.example');
    $env = installerFile($sandbox);
    $exampleComments = installerProseComments($example);
    $envComments = installerProseComments($env);

    // Every prose comment, in order; the installer adds only its closing block header.
    expect(installerInOrder($exampleComments, $envComments))->toBeTrue()
        ->and(count($envComments))->toBeLessThanOrEqual(count($exampleComments) + 1);

    // Only values change: every key of env.example is still there, in the same order.
    preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $example, $exampleKeys);
    preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $env, $envKeys);
    expect(array_values(array_intersect($envKeys[1], $exampleKeys[1])))->toBe($exampleKeys[1])
        ->and(installerEnvValue($env, 'HDID_RATE_LIMIT_EXEMPT_NETWORKS'))->toBe('192.0.2.0/24,198.51.100.7')
        ->and(installerEnvValue($env, 'TRUSTED_PROXIES'))->toBe('10.0.0.2')
        ->and(installerEnvValue($env, 'OPENHDID_MONITORING_ALLOW'))->toBe('10.0.0.2')
        ->and(installerEnvValue($env, 'MAIL_FROM_NAME'))->toBe('"${APP_NAME}"')
        ->and(installerEnvValue($env, 'EMD_SYNC_CRON'))->toBe('"0 */3 * * *"');
});

it('writes the 5k and the 10k single-host profiles with their profile file', function (string $profile, string $composeFile, string $asset): void {
    $sandbox = new InstallerSandbox;
    // The release folder the fake curl downloads from, with its SHA256SUMS.
    $assets = $sandbox->releaseAssets();
    $answers = $sandbox->answers(fiveKAnswers(['PROFILE' => $profile]));

    $result = $sandbox->run(['--answers', $answers, '--version', '1.15.0', '--lang', 'en', '--yes']);

    // 1: the check of step 10 warns (no backup yet, mail and SMS still write to the log).
    expect($result->exitCode())->toBeIn([0, 1], $result->output().$result->errorOutput());
    $env = installerFile($sandbox);
    $file = explode(':', $composeFile)[1];
    expect(installerEnvValue($env, 'COMPOSE_FILE'))->toBe($composeFile)
        ->and(hash_file('sha256', $sandbox->dir.'/'.$file))->toBe(hash_file('sha256', $assets.'/'.$asset))
        ->and(implode("\n", $sandbox->callLines('curl')))->toContain('/releases/download/v1.15.0/'.$asset, '/releases/download/v1.15.0/SHA256SUMS')
        ->and($result->output())->toContain($asset.': checked')
        ->and(installerFile($sandbox, '.install/SHA256SUMS-1.15.0'))->toContain($asset);
    foreach ($sandbox->callLines('curl') as $line) {
        expect($line)->toContain('https://github.com/adamfhun/openhdid/releases/download/v1.15.0/');
    }
})->with([
    '5k' => ['5k', 'compose.yaml:profiles/5k/compose.yaml', 'profile-5k.yaml'],
    '10k on one host' => ['10k-single', 'compose.yaml:profiles/10k/single.yaml', 'profile-10k-single.yaml'],
]);

it('refuses a release file whose checksum does not match', function (): void {
    $sandbox = new InstallerSandbox;
    $assets = $sandbox->releaseAssets('assets');
    file_put_contents($assets.'/profile-5k.yaml', "\n# changed\n", FILE_APPEND);
    $answers = $sandbox->answers(fiveKAnswers());

    $result = $sandbox->run(['--answers', $answers, '--from', $assets, '--version', '1.15.0', '--lang', 'en', '--yes']);

    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain('profile-5k.yaml', 'does not match')
        ->and(is_file($sandbox->dir.'/compose.yaml'))->toBeFalse()
        ->and(is_file($sandbox->dir.'/profiles/5k/compose.yaml'))->toBeFalse()
        ->and(is_file($sandbox->dir.'/.env'))->toBeFalse()
        ->and($sandbox->callLines('docker'))->not->toContain('docker compose up -d --wait --wait-timeout 600');
});

it('never passes a secret on a command line or prints it', function (): void {
    $sandbox = new InstallerSandbox;
    $answers = $sandbox->answers(fiveKAnswers());

    $result = $sandbox->run(['--answers', $answers, '--from', $sandbox->release, '--version', '1.15.0', '--lang', 'hu', '--yes']);

    expect($result->exitCode())->toBeIn([0, 1], $result->output().$result->errorOutput());
    $env = installerFile($sandbox);
    $secrets = [
        installerEnvValue($env, 'APP_KEY'), installerEnvValue($env, 'DB_PASSWORD'), installerEnvValue($env, 'REDIS_PASSWORD'),
        installerEnvValue(installerFile($sandbox, 'db.env'), 'DB_PASSWORD'), installerEnvValue(installerFile($sandbox, 'db.env'), 'MARIADB_ROOT_PASSWORD'),
    ];
    $calls = implode("\n", $sandbox->callLines());
    $seen = $result->output().$result->errorOutput().$sandbox->installLogs();
    foreach ($secrets as $secret) {
        expect($secret)->not->toBeEmpty()
            ->and($calls)->not->toContain((string) $secret)
            ->and($seen)->not->toContain((string) $secret);
    }
    // Only the names reach the image that makes them.
    expect($calls)->toContain('-- APP_KEY DB_PASSWORD DB_MIGRATE_PASSWORD MARIADB_ROOT_PASSWORD REDIS_PASSWORD')
        ->and($result->output())->toContain('APP_KEY: generált (51 karakter)', 'REDIS_PASSWORD: generált (48 karakter)');
});

it('feeds every container call from /dev/null so piped answers reach the prompts', function (): void {
    $sandbox = new InstallerSandbox;
    // No answers file, no terminal: the menu and every question read the pipe.
    $input = implode("\n", [
        '2',                          // 5k on one host
        '', '', '',                   // HTTPS port, HTTP port, listening address: defaults
        'https://hdid.example.org',   // APP_URL
        '',                           // APP_ENV: production
        'off', '10.0.0.2',            // behind a load balancer
        '',                           // no exempt networks
        '',                           // no administrator now
        'yes',                        // the summary
    ])."\n";

    $result = $sandbox->run(['--from', $sandbox->release, '--version', '1.15.0'], $input);

    expect($result->exitCode())->toBeIn([0, 1], $result->output().$result->errorOutput())
        ->and($result->output())->toContain('[12/12]');
    foreach ($sandbox->calls() as $call) {
        expect($call['stdin'])->toBe(0, 'read the operator input: '.$call['line']);
    }
    expect(installerEnvValue(installerFile($sandbox), 'APP_URL'))->toBe('https://hdid.example.org')
        ->and(installerEnvValue(installerFile($sandbox), 'APP_ENV'))->toBe('production');
});

it('stops on an existing installation without changing anything', function (): void {
    $sandbox = new InstallerSandbox;
    file_put_contents($sandbox->dir.'/.env', "APP_KEY=base64:old\nOPENHDID_VERSION=1.14.0\n");
    chmod($sandbox->dir.'/.env', 0600);
    $before = installerFile($sandbox);
    $sandbox->rule('docker volume ls*', 0, "openhdid_db\nopenhdid_storage\nother_db\n");
    $answers = $sandbox->answers(fiveKAnswers());

    $result = $sandbox->run(['--answers', $answers, '--from', $sandbox->release, '--version', '1.15.0', '--lang', 'en', '--yes']);

    expect($result->exitCode())->toBe(2)
        ->and($result->output())->toContain('already an installation', './install.sh check', './install.sh fix', 'UPGRADE.md')
        ->and(installerFile($sandbox))->toBe($before)
        ->and(glob($sandbox->dir.'/.env.bak-*'))->toBe([])
        ->and(is_file($sandbox->dir.'/compose.yaml'))->toBeFalse();
});

it('backs up an existing .env with mode 600 before it writes a new one', function (): void {
    $sandbox = new InstallerSandbox;
    $oldKey = 'base64:'.base64_encode(str_repeat('o', 32));
    file_put_contents($sandbox->dir.'/.env', "APP_KEY={$oldKey}\nDB_PASSWORD=".str_repeat('p', 40)."\n");
    chmod($sandbox->dir.'/.env', 0644);
    $answers = $sandbox->answers(fiveKAnswers());

    $result = $sandbox->run(['--answers', $answers, '--from', $sandbox->release, '--version', '1.15.0', '--lang', 'en', '--yes']);

    expect($result->exitCode())->toBeIn([0, 1], $result->output().$result->errorOutput());
    $backups = glob($sandbox->dir.'/.env.bak-*');
    expect($backups)->toHaveCount(1)
        ->and($sandbox->mode($backups[0]))->toBe('600')
        ->and((string) file_get_contents($backups[0]))->toContain($oldKey)
        ->and(installerEnvValue(installerFile($sandbox), 'APP_KEY'))->toBe($oldKey)
        ->and(installerEnvValue(installerFile($sandbox), 'DB_PASSWORD'))->toBe(str_repeat('p', 40))
        ->and($sandbox->mode($sandbox->dir.'/.env'))->toBe('600')
        ->and($result->output())->toContain('Interrupted installation', 'APP_KEY: the existing value stays');
});

it('never generates an APP_KEY while the database or storage volume exists', function (): void {
    $sandbox = new InstallerSandbox;
    $sandbox->rule('docker volume ls*', 0, "openhdid_storage\n");
    $answers = $sandbox->answers(fiveKAnswers());

    $result = $sandbox->run(['--answers', $answers, '--from', $sandbox->release, '--version', '1.15.0', '--lang', 'en', '--yes']);

    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain('openhdid_storage', 'config.tar.gz', 'no new key is generated')
        ->and(is_file($sandbox->dir.'/.env'))->toBeFalse()
        ->and(implode("\n", $sandbox->callLines('docker')))->not->toContain('--entrypoint php');
});

it('writes the shared answers file on the infra host and installs host B from it', function (): void {
    $infra = new InstallerSandbox;
    $infraAnswers = $infra->answers([
        'PROFILE' => '10k-ha', 'OPENHDID_VERSION' => '1.15.0', 'INFRA_BIND_IP' => '10.0.0.5', 'INFRA_ADDRESS' => '10.0.0.5',
        'APP_HOSTS' => '10.0.0.11,10.0.0.12', 'APP_URL' => 'https://hdid.example.org', 'LB_ADDRESSES' => '10.0.0.2',
        'NFS_SERVER' => '10.0.0.5', 'NFS_PATH' => '/export/openhdid-storage', 'REDIS_PREFIX' => 'openhdid-a1b2c3-',
    ], 'infra.txt');

    $result = $infra->run(['--answers', $infraAnswers, '--role', 'infra', '--from', $infra->release, '--lang', 'en', '--yes']);

    expect($result->exitCode())->toBeIn([0, 1], $result->output().$result->errorOutput());
    $shared = $infra->dir.'/openhdid-10k-answers.txt';
    $infraEnv = installerFile($infra);
    expect($infra->mode($shared))->toBe('600')
        ->and($infra->mode($infra->dir.'/.env'))->toBe('600')
        ->and(installerEnvValue($infraEnv, 'COMPOSE_FILE'))->toBe('profiles/10k/infra.yaml')
        ->and(installerEnvValue($infraEnv, 'OPENHDID_INFRA_BIND_IP'))->toBe('10.0.0.5')
        ->and(installerEnvValue($infraEnv, 'REDIS_SESSION_PASSWORD'))->toMatch('/^[0-9a-f]{48}$/')
        ->and(installerEnvValue($infraEnv, 'REDIS_SESSION_PASSWORD'))->not->toBe(installerEnvValue($infraEnv, 'REDIS_PASSWORD'))
        ->and(is_file($infra->dir.'/profiles/10k/infra.yaml'))->toBeTrue()
        ->and($result->output())->toContain('rm openhdid-10k-answers.txt', 'sudo exportfs -ra', 'CHANGE MASTER TO', 'mariadb-dump')
        // The image is not needed for the secrets of the infra host.
        ->and(implode("\n", $infra->callLines('docker')))->not->toContain('--entrypoint php');
    $sharedText = (string) file_get_contents($shared);
    expect($sharedText)->toStartWith("# openhdid-answers 1\n")
        ->and($sharedText)->not->toMatch('/^ROLE=/m');
    foreach (['APP_KEY', 'DB_HOST', 'DB_PASSWORD', 'DB_MIGRATE_PASSWORD', 'REDIS_HOST', 'REDIS_PASSWORD', 'REDIS_SESSION_PASSWORD', 'NFS_SERVER', 'APP_URL', 'LB_ADDRESSES'] as $key) {
        expect(installerEnvValue($sharedText, $key))->not->toBeEmpty($key);
    }
    expect(installerEnvValue($sharedText, 'DB_PASSWORD'))->toBe(installerEnvValue($infraEnv, 'DB_PASSWORD'))
        ->and(installerEnvValue($sharedText, 'DB_HOST'))->toBe('10.0.0.5')
        ->and(installerEnvValue($sharedText, 'REDIS_SESSION_PORT'))->toBe('6380');

    // Host B: only the role is asked (then its port and address, the summary, and the deletion).
    $hostB = new InstallerSandbox;
    copy($shared, $hostB->dir.'/openhdid-10k-answers.txt');
    chmod($hostB->dir.'/openhdid-10k-answers.txt', 0600);
    $result = $hostB->run(['--answers', $hostB->dir.'/openhdid-10k-answers.txt', '--from', $hostB->release, '--lang', 'en'], "b\n\n\nyes\n\n");

    expect($result->exitCode())->toBeIn([0, 1], $result->output().$result->errorOutput());
    $env = installerFile($hostB);
    expect(installerEnvValue($env, 'COMPOSE_PROFILES'))->toBe('')
        ->and(installerEnvValue($env, 'COMPOSE_FILE'))->toBe('compose.yaml:profiles/10k/compose.yaml')
        ->and(installerEnvValue($env, 'APP_KEY'))->toBe(installerEnvValue($sharedText, 'APP_KEY'))
        ->and(installerEnvValue($env, 'DB_HOST'))->toBe('10.0.0.5')
        ->and(installerEnvValue($env, 'REDIS_SESSION_PASSWORD'))->toBe(installerEnvValue($sharedText, 'REDIS_SESSION_PASSWORD'))
        ->and(installerEnvValue($env, 'OPENHDID_TLS'))->toBe('off')
        ->and(installerEnvValue($env, 'TRUSTED_PROXIES'))->toBe('10.0.0.2')
        ->and(installerEnvValue($env, 'OPENHDID_NFS_SERVER'))->toBe('10.0.0.5')
        ->and(installerEnvValue($env, 'CACHE_PREFIX'))->toBe('openhdid-a1b2c3-cache-')
        ->and(installerEnvValue(installerFile($hostB, 'db.env'), 'DB_PASSWORD'))->toBe(installerEnvValue($sharedText, 'DB_MIGRATE_PASSWORD'))
        ->and($result->output())->not->toContain('Database host (DB_HOST)')
        ->and($result->output())->toContain('Role (a, b, infra)', 'deleted: ')
        ->and(is_file($hostB->dir.'/openhdid-10k-answers.txt'))->toBeFalse();
});

it('rejects an answers file readable by others, with unknown keys or with a shell expression', function (array $lines, int $mode, string $message): void {
    $sandbox = new InstallerSandbox;
    $answers = $sandbox->answers($lines, 'answers.txt', $mode);

    $result = $sandbox->run(['--answers', $answers, '--from', $sandbox->release, '--lang', 'en', '--yes']);

    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain($message)
        ->and($sandbox->callLines('docker'))->toBe([])
        ->and(is_file($sandbox->dir.'/.env'))->toBeFalse()
        ->and(is_file($sandbox->root.'/pwned'))->toBeFalse();
})->with([
    'readable by others' => [['PROFILE' => 'trial'], 0644, 'chmod 600'],
    'unknown key' => [['PROFILE' => 'trial', 'DB_ROOT' => 'x'], 0600, 'line 3: unknown key: DB_ROOT'],
    'shell expression' => [['PROFILE' => 'trial', 'APP_URL' => 'https://$(touch ../../pwned).example.org'], 0600, 'line 3: the value of APP_URL is invalid'],
    'repeated key' => [['PROFILE' => 'trial', 'PROFILE ' => 'x'], 0600, 'line 3'],
]);

it('prints the load balancer, NFS and replica steps for host A without running them', function (): void {
    $sandbox = new InstallerSandbox;
    $answers = $sandbox->answers(tenKSharedAnswers(), 'openhdid-10k-answers.txt', 0600, $sandbox->dir);

    $result = $sandbox->run(['--answers', $answers, '--role', 'a', '--from', $sandbox->release, '--lang', 'en', '--yes']);

    expect($result->exitCode())->toBeIn([0, 1], $result->output().$result->errorOutput());
    expect($result->output())->toContain(
        'http-check send meth GET uri /up ver HTTP/1.1 hdr Host hdid.example.org',
        'server a 10.0.0.11:80 check',
        'server b 10.0.0.12:80 check',
        'ssl-min-ver TLSv1.3',
        'timeout server 330s',
        'TRUSTED_PROXIES and OPENHDID_MONITORING_ALLOW',
        'showmount -e 10.0.0.5',
        "sudo firewall-cmd --permanent --add-rich-rule='rule family=ipv4 source address=10.0.0.2 port port=80 protocol=tcp accept'",
        './install.sh --answers openhdid-10k-answers.txt --role b',
    );
    $env = installerFile($sandbox);
    expect(installerEnvValue($env, 'COMPOSE_PROFILES'))->toBe('primary')
        ->and(installerEnvValue($env, 'OPENHDID_TLS'))->toBe('off');
    foreach (['sudo', 'exportfs', 'firewall-cmd', 'ufw', 'showmount', 'systemctl', 'sysctl', 'usermod'] as $command) {
        expect($sandbox->callLines($command))->toBe([], "{$command} was run");
    }
});

it('prepares the Kubernetes files and changes nothing on the cluster', function (): void {
    $sandbox = new InstallerSandbox;
    $answers = $sandbox->answers([
        'PROFILE' => '20k', 'OPENHDID_VERSION' => '1.15.0', 'K8S_NAMESPACE' => 'hdid', 'APP_URL' => 'https://hdid.example.org',
        'K8S_INGRESS_NAMESPACE' => 'traefik', 'K8S_POD_CIDR' => '10.244.0.0/16', 'K8S_STORAGE_CLASS' => 'nfs-rwx',
        'DB_HOST' => 'db.example.org', 'DB_PASSWORD' => str_repeat('d', 40), 'DB_MIGRATE_PASSWORD' => str_repeat('m', 40),
        'REDIS_HOST' => 'valkey.example.org', 'REDIS_PASSWORD' => str_repeat('r', 40), 'REDIS_SESSION_HOST' => 'sessions.example.org',
        'REDIS_PREFIX' => 'openhdid-k8s-',
    ]);

    $result = $sandbox->run(['--answers', $answers, '--from', $sandbox->release, '--lang', 'en', '--yes']);

    expect($result->exitCode())->toBeIn([0, 1], $result->output().$result->errorOutput());
    $kustomization = installerFile($sandbox, 'k8s/kustomization.yaml');
    $env = installerFile($sandbox, 'k8s/openhdid-env.env');
    expect($kustomization)->toContain('namespace: hdid', 'newTag: "1.15.0"', '  - kubernetes/overlays/20k', 'path: /spec/rules/0/host', 'value: hdid.example.org',
        'kubernetes.io~1metadata.name', 'value: traefik', 'storageClassName', 'value: nfs-rwx')
        ->and($sandbox->mode($sandbox->dir.'/k8s/openhdid-env.env'))->toBe('600')
        ->and($sandbox->mode($sandbox->dir.'/k8s/openhdid-db-env.env'))->toBe('600')
        ->and($env)->not->toContain('#')
        ->and($env)->not->toContain("'")
        ->and($env)->not->toMatch('/^COMPOSE_/m')
        ->and($env)->not->toMatch('/^OPENHDID_NFS_/m')
        ->and(installerEnvValue($env, 'MAIL_FROM_NAME'))->toBe('OpenHDID')
        ->and($env)->toContain("EMD_SYNC_CRON=0 */3 * * *\n", "OPENHDID_TLS=off\n", "TRUSTED_PROXIES=10.244.0.0/16\n", "REDIS_SESSION_HOST=sessions.example.org\n")
        ->and(installerEnvValue($env, 'APP_KEY'))->toMatch('/^base64:[A-Za-z0-9+\/]{43}=$/')
        ->and(installerFile($sandbox, 'k8s/openhdid-db-env.env'))->not->toContain('MARIADB_ROOT_PASSWORD')
        ->and(is_dir($sandbox->dir.'/k8s/kubernetes/overlays/20k'))->toBeTrue()
        ->and($result->output())->toContain('kubectl -n hdid create secret generic openhdid-env --from-env-file=k8s/openhdid-env.env', 'kubectl apply -k k8s')
        ->and($sandbox->callLines('kubectl'))->toBe(['kubectl kustomize '.$sandbox->dir.'/k8s'])
        ->and($sandbox->callLines('docker'))->toBe([]);
});

it('installs the certificate through the container user without root', function (): void {
    $sandbox = new InstallerSandbox;
    file_put_contents($sandbox->root.'/fullchain.pem', "-----BEGIN CERTIFICATE-----\nfake\n-----END CERTIFICATE-----\n");
    file_put_contents($sandbox->root.'/privkey.pem', "-----BEGIN PRIVATE KEY-----\nfake-key-material\n-----END PRIVATE KEY-----\n");
    mkdir($sandbox->dir.'/tls');
    file_put_contents($sandbox->dir.'/tls/fullchain.pem', 'the previous certificate');
    $answers = $sandbox->answers(fiveKAnswers(['TLS_MODE' => 'on', 'LB_ADDRESSES' => '', 'TLS_CERT' => $sandbox->root.'/fullchain.pem', 'TLS_KEY' => $sandbox->root.'/privkey.pem']));

    $result = $sandbox->run(['--answers', $answers, '--from', $sandbox->release, '--version', '1.15.0', '--lang', 'en', '--yes']);

    expect($result->exitCode())->toBeIn([0, 1], $result->output().$result->errorOutput());
    $install = collect($sandbox->calls())->first(fn (array $call): bool => str_contains($call['line'], 'tar xf - -C /t'));
    expect($install)->not->toBeNull()
        ->and($install['line'])->toStartWith('docker compose run --rm --no-deps -T -v '.$sandbox->dir.'/.install/.tmp-tls-')
        ->and($install['line'])->toContain('--entrypoint sh web -c umask 077 && tar xf - -C /t && chmod 444 /t/fullchain.pem && chmod 400 /t/privkey.pem')
        ->and($install['stdin'])->toBeGreaterThan(0)
        ->and(implode("\n", $sandbox->callLines()))->not->toContain('--user')
        ->and($sandbox->callLines('sudo'))->toBe([])
        ->and((string) file_get_contents($sandbox->dir.'/tls/privkey.pem'))->toContain('fake-key-material')
        ->and(glob($sandbox->dir.'/.install/tls-elozo-*/fullchain.pem'))->toHaveCount(1)
        ->and(glob($sandbox->dir.'/.install/.tmp-tls*'))->toBe([])
        ->and($sandbox->callLines('docker'))->toContain('docker compose run --rm --no-deps -T web tls-check')
        ->and($result->output())->toContain('valid until 2027-01-01');
});

it('reports host facts below the machine table as warnings and Podman as an error', function (): void {
    $sandbox = new InstallerSandbox;
    $sandbox->env = ['FAKE_NPROC' => '2', 'FAKE_MEM_MB' => '4096', 'FAKE_NTP' => 'no'];
    $answers = $sandbox->answers(fiveKAnswers());

    $result = $sandbox->run(['--answers', $answers, '--from', $sandbox->release, '--version', '1.15.0', '--lang', 'en', '--yes']);

    expect($result->exitCode())->toBe(1, $result->output().$result->errorOutput())
        ->and($result->output())->toContain('HOST-01', '2 vCPUs present', 'HOST-02', '4096 MB present', 'HOST-04', 'sudo timedatectl set-ntp true');
    expect($sandbox->callLines('timedatectl'))->toBe(['timedatectl show -p NTPSynchronized --value']);

    $podman = new InstallerSandbox;
    $podman->rule('docker version', 0, "Client: Podman Engine\nVersion: 5.2.0\n");

    $result = $podman->run(['--answers', $podman->answers(fiveKAnswers()), '--from', $podman->release, '--lang', 'en', '--yes']);

    expect($result->exitCode())->toBe(2)
        ->and($result->output())->toContain('DOCKER-04', 'Podman answers the docker command')
        ->and(is_file($podman->dir.'/.env'))->toBeFalse();
});

it('refuses to run as root and to take --yes without an answers file', function (): void {
    $sandbox = new InstallerSandbox;
    $sandbox->env = ['FAKE_UID' => '0'];

    $result = $sandbox->run(['--answers', $sandbox->answers(trialAnswers()), '--lang', 'en', '--yes']);

    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain('does not run as root')
        ->and(is_dir($sandbox->dir.'/.install'))->toBeFalse();

    $sandbox->env = [];
    $result = $sandbox->run(['--profile', 'trial', '--lang', 'en', '--yes']);

    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain('--yes works only with an answers file');
});

/**
 * The installer's own code: the message catalogue and the help header aside.
 */
function installerCode(): string
{
    $script = (string) file_get_contents(InstallerSandbox::releaseRoot().'/install.sh');
    $code = preg_replace('/^# ===== messages =+$.*?^# ===== main =+$/ms', '', $script);

    return implode("\n", array_filter(preg_split('/\R/', (string) $code), fn (string $line): bool => ! preg_match('/^\s*#/', $line)));
}

it('uses no bash 4 features and no early-exit pipes under pipefail', function (): void {
    $script = (string) file_get_contents(InstallerSandbox::releaseRoot().'/install.sh');
    $code = installerCode();

    foreach ([
        'declare -A' => '/declare\s+-[a-zA-Z]*A/',
        'lower/upper case expansion' => '/\$\{[A-Za-z_][A-Za-z0-9_]*(,,|\^\^|,|\^)\}/',
        'mapfile' => '/\bmapfile\b/',
        'readarray' => '/\breadarray\b/',
        'local -n' => '/\blocal\s+-n\b/',
        '[[ -v' => '/\[\[\s+-v\s/',
        'negative index' => '/\[\s*-1\s*\]\}/',
        '|&' => '/\|&/',
        '&>>' => '/&>>/',
        'wait -n' => '/\bwait\s+-n\b/',
        'EPOCHSECONDS' => '/EPOCHSECONDS|EPOCHREALTIME/',
        'printf %(…)T' => '/%\([^)]*\)T/',
        'globstar' => '/globstar/',
        '| grep -q' => '/\|\s*grep\s+-[a-zA-Z]*q/',
        '| head' => '/\|\s*head\b/',
        'sed -i' => '/\bsed\s+-i\b/',
    ] as $what => $pattern) {
        expect(preg_match($pattern, $code))->toBe(0, "install.sh uses {$what}");
    }

    expect($script)->toContain("set -euo pipefail\n", "unset COMPOSE_FILE COMPOSE_PROJECT_NAME COMPOSE_PROFILES COMPOSE_ENV_FILES\n");

    // Every docker call goes through dk/dc (both read /dev/null) or run_web with its input given;
    // the hdid:make-admin exec hands the terminal over on purpose.
    foreach (preg_split('/\R/', $code) as $line) {
        $trimmed = trim($line);
        // Printed advice (say, info, finding and its continuation lines) is text, not a call.
        if (! preg_match('/(^|[\s;(|&])docker\s/', $trimmed) || preg_match('/^(say|info|warn|die|finding|printf|")|command -v docker/', $trimmed)) {
            continue;
        }
        expect($trimmed)->toMatch('/^dk\(\) \{ docker "\$@" <\/dev\/null; \}$|^dc\(\) \{ docker compose "\$@" <\/dev\/null; \}$|^docker compose run --rm --no-deps -T "\$@" 2>"\$err"|hdid:make-admin/', "a docker call without /dev/null: {$trimmed}");
    }
    foreach (preg_split('/\R/', $code) as $line) {
        if (preg_match('/(^|[\s(])run_web\s/', $line) && ! str_contains($line, 'run_web()')) {
            expect($line)->toMatch('/<\/dev\/null|\|\s*run_web/', "run_web without its input: {$line}");
        }
        if (preg_match('/\b(kubectl|curl|wget)\s+-/', $line) && ! preg_match('/^\s*(say|info)\b/', $line) && ! str_contains($line, 'command -v')) {
            expect($line)->toContain('</dev/null');
        }
    }
});

it('has an English message for every Hungarian one', function (): void {
    $script = (string) file_get_contents(InstallerSandbox::releaseRoot().'/install.sh');
    preg_match_all('/^M_([a-z0-9_]+)_(hu|en)=/m', $script, $matches, PREG_SET_ORDER);
    $languages = [];
    foreach ($matches as [, $key, $language]) {
        $languages[$key][] = $language;
    }

    expect($languages)->not->toBeEmpty();
    foreach ($languages as $key => $found) {
        sort($found);
        expect($found)->toBe(['en', 'hu'], "message {$key}");
    }

    // Every message the code asks for exists.
    // Every message the code asks for by a fixed name exists (names built from a value end in _).
    preg_match_all('/\bmsg\s+"?([a-z][a-z0-9_]*[a-z0-9])\b(?!\$)/', installerCode(), $used);
    foreach (array_unique($used[1]) as $key) {
        expect(array_key_exists($key, $languages))->toBeTrue("msg {$key} has no text");
    }
});

it('is clean for shellcheck when the tool is installed', function (string $script): void {
    $shellcheck = trim((string) Process::run(['sh', '-c', 'command -v shellcheck'])->output());
    if ($shellcheck === '') {
        $this->markTestSkipped('shellcheck is not installed here; the preflight runs the pinned image.');
    }
    // The verdict belongs to the version the preflight pins (an older runner's copy knows other checks).
    preg_match('/^version: (\d+)\.(\d+)\.(\d+)/m', (string) Process::run([$shellcheck, '--version'])->output(), $version);
    if ($version === [] || version_compare("{$version[1]}.{$version[2]}.{$version[3]}", '0.11.0', '<')) {
        $this->markTestSkipped('shellcheck older than the pinned 0.11.0; the preflight runs the pinned image.');
    }

    $result = Process::path(InstallerSandbox::releaseRoot())->run([$shellcheck, '-s', 'bash', $script]);

    expect($result->exitCode())->toBe(0, $result->output());
})->with(['install.sh', 'backup.sh']);

it('writes a password with dollar, space, quote, hash and backslash literally and refuses them for the bundled Valkey', function (): void {
    $sandbox = new InstallerSandbox;
    $password = 'pa$HOME word"#\\x$(id)';
    $answers = $sandbox->answers(trialAnswers(['DB_PASSWORD' => $password]));

    $result = $sandbox->run(['--answers', $answers, '--from', $sandbox->release, '--version', '1.15.0', '--lang', 'en', '--yes']);

    expect($result->exitCode())->toBeIn([0, 1], $result->output().$result->errorOutput());
    // Between apostrophes Compose and phpdotenv take it as it is: no expansion, no comment, no escape.
    expect(installerFile($sandbox))->toContain("\nDB_PASSWORD='".$password."'\n")
        ->and($result->output().$result->errorOutput().$sandbox->installLogs())->not->toContain($password)
        ->and(implode("\n", $sandbox->callLines()))->not->toContain($password);

    // The bundled Valkey reads its password between double quotes: a quote or a backslash is refused
    // before anything is written.
    foreach (['quote"in-the-middle', 'back\\slash-in-the-middle'] as $redis) {
        $refused = new InstallerSandbox;
        $answers = $refused->answers(trialAnswers(['REDIS_PASSWORD' => $redis]));
        $result = $refused->run(['--answers', $answers, '--from', $refused->release, '--version', '1.15.0', '--lang', 'en', '--yes']);

        expect($result->exitCode())->toBe(2)
            ->and($result->errorOutput())->toContain('REDIS_PASSWORD must not contain a double quote')
            ->and(is_file($refused->dir.'/.env'))->toBeFalse();
    }
});

it('checks a new certificate beside the live one and keeps the old one when it is refused', function (): void {
    $sandbox = new InstallerSandbox;
    file_put_contents($sandbox->root.'/fullchain.pem', "-----BEGIN CERTIFICATE-----\nfake\n-----END CERTIFICATE-----\n");
    file_put_contents($sandbox->root.'/privkey.pem', "-----BEGIN PRIVATE KEY-----\nother-key\n-----END PRIVATE KEY-----\n");
    mkdir($sandbox->dir.'/tls');
    file_put_contents($sandbox->dir.'/tls/fullchain.pem', 'the previous certificate');
    $sandbox->rule('docker compose run --rm --no-deps -T -v *:/etc/openhdid/tls-new:ro *tls-check*', 1, "ERROR: the private key does not belong to the certificate.\nTLS files are NOT usable.\n");
    $answers = $sandbox->answers(fiveKAnswers(['TLS_MODE' => 'on', 'LB_ADDRESSES' => '', 'TLS_CERT' => $sandbox->root.'/fullchain.pem', 'TLS_KEY' => $sandbox->root.'/privkey.pem']));

    $result = $sandbox->run(['--answers', $answers, '--from', $sandbox->release, '--version', '1.15.0', '--lang', 'en', '--yes']);

    $check = collect($sandbox->calls())->first(fn (array $call): bool => str_contains($call['line'], 'tls-new'));
    expect($result->exitCode())->toBe(2)
        ->and($result->output())->toContain('the private key does not belong to the certificate')
        ->and($check['line'])->toContain('-e OPENHDID_TLS_CERT=/etc/openhdid/tls-new/fullchain.pem -e OPENHDID_TLS_KEY=/etc/openhdid/tls-new/privkey.pem web tls-check')
        // The live folder is untouched, nothing of the attempt is left.
        ->and((string) file_get_contents($sandbox->dir.'/tls/fullchain.pem'))->toBe('the previous certificate')
        ->and(glob($sandbox->dir.'/.install/tls-elozo-*'))->toBe([])
        ->and(glob($sandbox->dir.'/.install/.tmp-tls*'))->toBe([])
        ->and($sandbox->callLines('docker'))->not->toContain('docker compose up -d --wait --wait-timeout 600');
});

it('creates the folders the container mounts open to its user although the umask is 077', function (): void {
    $sandbox = new InstallerSandbox;
    $answers = $sandbox->answers(fiveKAnswers());

    $result = $sandbox->run(['--answers', $answers, '--from', $sandbox->release, '--version', '1.15.0', '--lang', 'en', '--yes']);

    expect($result->exitCode())->toBeIn([0, 1], $result->output().$result->errorOutput())
        ->and($sandbox->mode($sandbox->dir.'/tls'))->toBe('755')
        ->and($sandbox->mode($sandbox->dir.'/ca'))->toBe('755')
        ->and($sandbox->mode($sandbox->dir.'/.env'))->toBe('600');
});

it('resumes an installation that stopped before the start although its one-off containers made the volumes', function (): void {
    // First run: stops at the certificate check, after .env was written; its docker compose run calls
    // created the project's volumes (Compose does that), no container exists.
    $sandbox = new InstallerSandbox;
    $sandbox->rule('docker compose run --rm --no-deps -T web check-env*', 1, "openhdid: ERROR: APP_URL is not https.\n");
    $answers = $sandbox->answers(fiveKAnswers());
    $first = $sandbox->run(['--answers', $answers, '--from', $sandbox->release, '--version', '1.15.0', '--lang', 'en', '--yes']);
    expect($first->exitCode())->toBe(2)
        ->and(is_file($sandbox->dir.'/.install/progress'))->toBeTrue()
        ->and(is_file($sandbox->dir.'/.install/state'))->toBeFalse();
    $key = installerEnvValue(installerFile($sandbox), 'APP_KEY');

    // Second run with the volumes there: it goes on, keeps the key and backs .env up.
    $sandbox->resetRules()->rule('docker volume ls*', 0, "openhdid_db\nopenhdid_storage\n");
    $second = $sandbox->run(['--answers', $answers, '--from', $sandbox->release, '--version', '1.15.0', '--lang', 'en', '--yes']);
    expect($second->exitCode())->toBeIn([0, 1], $second->output().$second->errorOutput())
        ->and($second->output())->toContain('Interrupted installation: the installer already wrote .env here', 'openhdid_db openhdid_storage')
        ->and(installerEnvValue(installerFile($sandbox), 'APP_KEY'))->toBe($key)
        ->and(glob($sandbox->dir.'/.env.bak-*'))->toHaveCount(1)
        ->and(is_file($sandbox->dir.'/.install/state'))->toBeTrue();

    // Once started (state written), the same volumes mean an installation: nothing changes.
    $before = installerFile($sandbox);
    $third = $sandbox->run(['--answers', $answers, '--from', $sandbox->release, '--version', '1.15.0', '--lang', 'en', '--yes']);
    expect($third->exitCode())->toBe(2)
        ->and($third->output())->toContain('already an installation')
        ->and(installerFile($sandbox))->toBe($before);
});
