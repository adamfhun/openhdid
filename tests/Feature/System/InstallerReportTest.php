<?php

use Illuminate\Support\Facades\Process;
use Tests\Support\InstallerSandbox;

/*
 * install.sh report: the diagnostic package stays in report/ of the
 * installation (700, the archive 600), and every secret of .env, db.env and
 * the answers file is masked in every file of it, wherever it shows up.
 */

afterEach(fn () => InstallerSandbox::cleanUpAll());

/**
 * The files of the only package in report/, unpacked.
 *
 * @return array<string, string> relative path => content
 */
function reportFiles(InstallerSandbox $sandbox): array
{
    $packages = glob($sandbox->dir.'/report/openhdid-report-*.tar.gz');
    expect($packages)->toHaveCount(1);
    $target = $sandbox->root.'/unpacked';
    mkdir($target, 0700, true);
    Process::run(['tar', 'xzf', $packages[0], '-C', $target])->throw();

    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS)) as $file) {
        $files[substr($file->getPathname(), strlen($target) + 1)] = (string) file_get_contents($file->getPathname());
    }
    ksort($files);

    return $files;
}

it('writes a diagnostic package with mode 600 that holds no secret', function (): void {
    $secrets = [
        'EMD_SYNC_PASSWORD' => 'emd-password-0123456789', 'OZEKI_PASSWORD' => 'ozeki-password-0123456789',
        'EWS_PASSWORD' => 'ews-password-0123456789', 'EMD_SYNC_API_TOKEN' => 'emd-token-0123456789abcdef',
        'HDID_HTTP_PROXY' => 'http://proxyuser:proxy-secret-0123@proxy.example.org:3128',
    ];
    $sandbox = (new InstallerSandbox)->installed($secrets);
    $sandbox->answers(['PROFILE' => '10k-ha', 'REDIS_SESSION_PASSWORD' => 'session-password-0123456789'], 'openhdid-10k-answers.txt', 0600, $sandbox->dir);
    $sandbox->rule('docker compose config', 0, implode("\n", [
        'services:', '  web:', '    environment:', '      DB_PASSWORD: '.InstallerSandbox::DB_PASSWORD, '      EMD_SYNC_API_TOKEN: emd-token-0123456789abcdef',
        '      APP_KEY: '.InstallerSandbox::FAKE_APP_KEY, '      HDID_HTTP_PROXY: http://proxyuser:proxy-secret-0123@proxy.example.org:3128',
        'configs:', '  valkey:', '    content: |', '      requirepass "'.InstallerSandbox::REDIS_PASSWORD.'"', '      requirepass "session-password-0123456789"',
    ])."\n")
        ->rule('docker compose logs --no-color --tail 300 web*', 0, implode("\n", [
            '{"remote_addr":"203.0.113.45","request":"GET /admin","status":200}',
            'production.ERROR: login failed for anna.kovacs@example.org from 2001:db8:1234:5678::1 phone +36301234567',
            'Authorization: Bearer abcdefghijklmnopqrstuvwxyz0123456789',
            'token eyJhbGciOiJIUzI1NiJ9'.'.eyJzdWIiOiIxMjM0NTY3ODkwIn0.c2lnbmF0dXJlLXNpZ25hdHVyZQ',
            'POST https://emd.example.org/login password=emd-password-0123456789&user=svc',
            '{"password":"some-password-typed-in-0123"}',
        ])."\n");

    $result = $sandbox->run(['report', '--lang', 'en']);

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and($result->output())->toContain('The package stayed on this host:', 'Nothing was uploaded')
        ->and($sandbox->mode($sandbox->dir.'/report'))->toBe('700');
    $package = glob($sandbox->dir.'/report/openhdid-report-*.tar.gz')[0];
    expect($sandbox->mode($package))->toBe('600')
        ->and(glob($sandbox->dir.'/report/.tmp-*'))->toBe([]);

    $files = reportFiles($sandbox);
    expect(array_keys($files))->toContain('versions.txt', 'files.txt', 'env.txt', 'db-env.txt', 'compose-config.yaml', 'ps.txt', 'check.json', 'health.txt', 'health.json', 'logs/web.log')
        ->and(json_decode($files['check.json'], true)['schema'])->toBe('openhdid-check/1');
    $everything = implode("\n", $files);
    foreach ([...array_values($secrets), 'proxy-secret-0123', InstallerSandbox::DB_PASSWORD, InstallerSandbox::REDIS_PASSWORD, InstallerSandbox::MIGRATE_PASSWORD,
        InstallerSandbox::ROOT_PASSWORD, InstallerSandbox::FAKE_APP_KEY, 'session-password-0123456789', 'abcdefghijklmnopqrstuvwxyz0123456789',
        'c2lnbmF0dXJlLXNpZ25hdHVyZQ', 'some-password-typed-in-0123'] as $secret) {
        expect($everything)->not->toContain($secret);
    }
    // Secrets are masked by key with their length, the rest stays readable.
    expect($files['env.txt'])->toContain('DB_PASSWORD=***('.strlen(InstallerSandbox::DB_PASSWORD).' characters)', 'APP_URL=https://hdid.example.org', 'OPENHDID_TLS_KEY=/etc/openhdid/tls/privkey.pem')
        ->and($files['db-env.txt'])->toContain('MARIADB_ROOT_PASSWORD=***(', 'DB_USERNAME=openhdid_migrate')
        ->and($files['compose-config.yaml'])->toContain('requirepass "***"', 'DB_PASSWORD: ***(')
        ->and($files['versions.txt'])->toContain('install.sh: 1.15.0', 'OPENHDID_VERSION: 1.15.0');
});

it('masks addresses and e-mail addresses in the logs of the package', function (): void {
    $sandbox = (new InstallerSandbox)->installed();
    $sandbox->rule('docker compose logs --no-color --tail 300 worker*', 0, implode("\n", [
        '{"remote_addr":"203.0.113.45","status":429}',
        'mail to anna.kovacs@example.org failed',
        'client 2001:db8:1234:5678:9abc::1 signed in',
        'SMS to +36301234567 queued',
        'Bearer abcdefghijklmnopqrstuvwxyz0123456789',
    ])."\n");

    $sandbox->run(['report', '--lang', 'hu'])->throw();

    $log = reportFiles($sandbox)['logs/worker.log'];
    expect($log)->toContain('203.0.113.x', 'a***@example.org', '2001:db8:1234:...', '+36301*****', 'Bearer ***')
        ->and($log)->not->toContain('203.0.113.45', 'anna.kovacs', '5678:9abc', '1234567', 'abcdefghijklmnop');
});

it('makes the package even when Docker does not answer', function (): void {
    $sandbox = (new InstallerSandbox)->installed();
    $sandbox->rule('docker info*', 1, '', "permission denied while trying to connect to the Docker daemon socket\n");

    $result = $sandbox->run(['report', '--lang', 'en']);

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
    $files = reportFiles($sandbox);
    expect($files)->not->toHaveKey('compose-config.yaml')
        ->and(collect(json_decode($files['check.json'], true)['findings'])->pluck('id')->all())->toContain('DOCKER-01')
        ->and($files['env.txt'])->not->toContain(InstallerSandbox::REDIS_PASSWORD);
});
