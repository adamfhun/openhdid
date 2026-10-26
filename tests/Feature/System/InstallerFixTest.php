<?php

use Tests\Support\InstallerSandbox;

/*
 * install.sh fix: guided repairs on a finished installation with a fake
 * docker. Each fix says what it will do, asks, saves .env once (600) before
 * it writes it, keeps the comments, and changes nothing on a no.
 */

afterEach(fn () => InstallerSandbox::cleanUpAll());

/**
 * The docker calls in order, without the read-only survey around them.
 *
 * @return list<string>
 */
function fixActions(InstallerSandbox $sandbox): array
{
    return array_values(array_filter($sandbox->callLines('docker'), fn (string $line): bool => (bool) preg_match('/compose (up|stop|restart|exec -T worker php artisan queue:monitor|exec -T scheduler php artisan schedule:clear-cache|exec -T web openhdid reload)\b/', $line)));
}

function fixEnv(InstallerSandbox $sandbox): string
{
    return (string) file_get_contents($sandbox->dir.'/.env');
}

function fixEnvValue(InstallerSandbox $sandbox, string $key): ?string
{
    return preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', fixEnv($sandbox), $match) ? trim($match[1], "'") : null;
}

it('moves an old installation to Valkey after draining the database queue', function (): void {
    $sandbox = (new InstallerSandbox)->installed(['SESSION_DRIVER' => 'database', 'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'database', 'REDIS_PASSWORD' => '']);
    $sandbox->rule('docker compose exec -T web php -r *"REDIS_PASSWORD"*', 0, '')
        ->rule('docker compose exec -T worker php artisan queue:monitor*', 0, [
            "  database:default .......... [3] OK\n  database:priority .......... [1] OK\n  database:sync .......... [0] OK\n",
            "  database:default .......... [0] OK\n  database:priority .......... [0] OK\n  database:sync .......... [0] OK\n",
        ]);
    $before = fixEnv($sandbox);

    $result = $sandbox->run(['fix', '--only', 'ENV-07', '--lang', 'en'], "yes\n");

    expect($result->exitCode())->toBeIn([0, 1], $result->output().$result->errorOutput())
        ->and($result->output())->toContain('everyone signs out once', 'failed_jobs table', 'docker compose stop web scheduler', 'the old queue (database:default,database:priority,database:sync): 4 waiting jobs', 'Fixed: 1');
    $backups = glob($sandbox->dir.'/.env.bak-*');
    expect($backups)->toHaveCount(1)
        ->and($sandbox->mode($backups[0]))->toBe('600')
        ->and((string) file_get_contents($backups[0]))->toBe($before)
        ->and($sandbox->mode($sandbox->dir.'/.env'))->toBe('600');
    foreach (['SESSION_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION'] as $key) {
        expect(fixEnvValue($sandbox, $key))->toBe('redis', $key);
    }
    $password = (string) fixEnvValue($sandbox, 'REDIS_PASSWORD');
    expect($password)->toBe(InstallerSandbox::fakeSecret('REDIS_PASSWORD'))
        ->and(implode("\n", $sandbox->callLines()))->not->toContain($password)
        ->and($result->output())->not->toContain($password)
        ->and(fixActions($sandbox))->toBe([
            'docker compose stop web scheduler',
            'docker compose exec -T worker php artisan queue:monitor database:default,database:priority,database:sync',
            'docker compose exec -T worker php artisan queue:monitor database:default,database:priority,database:sync',
            'docker compose up -d --force-recreate --no-deps valkey valkey-sessions',
            'docker compose up -d --wait --wait-timeout 600',
        ])
        ->and($sandbox->callLines('sleep'))->toContain('sleep 10');
    // Every comment of the old file is still there.
    preg_match_all('/^#.*$/m', $before, $old);
    preg_match_all('/^#.*$/m', fixEnv($sandbox), $new);
    expect(array_values(array_intersect($new[0], $old[0])))->toBe($old[0]);
});

it('starts web and the scheduler again and keeps .env when the queue does not drain', function (): void {
    $sandbox = (new InstallerSandbox)->installed(['QUEUE_CONNECTION' => 'database']);
    $sandbox->rule('docker compose exec -T worker php artisan queue:monitor*', 0, "  database:default ...... [7] OK\n");
    $before = fixEnv($sandbox);

    // yes to the fix, then 2 (cancel) when ten minutes did not empty the queue.
    $result = $sandbox->run(['fix', '--only', 'ENV-07', '--lang', 'en'], "yes\n2\n");

    expect($result->output())->toContain('the old queue is not empty (7 jobs)', 'cancelled: web and scheduler started again, .env did not change')
        ->and(fixEnv($sandbox))->toBe($before)
        ->and(fixActions($sandbox)[0])->toBe('docker compose stop web scheduler')
        ->and(fixActions($sandbox))->toContain('docker compose up -d web scheduler')
        ->and(fixActions($sandbox))->not->toContain('docker compose up -d --wait --wait-timeout 600')
        ->and(count(array_filter(fixActions($sandbox), fn (string $line): bool => str_contains($line, 'queue:monitor'))))->toBe(60);
});

it('changes nothing when the operator does not confirm a fix', function (): void {
    $sandbox = (new InstallerSandbox)->installed(['SESSION_DRIVER' => 'database', 'QUEUE_CONNECTION' => 'database', 'OPENHDID_NGINX_WORKERS' => 'auto']);
    $sandbox->env['FAKE_NPROC'] = '16';
    chmod($sandbox->dir.'/.env', 0644);
    $before = fixEnv($sandbox);

    $result = $sandbox->run(['fix', '--lang', 'en'], "no\nno\nno\n");

    expect($result->output())->toContain('skipped, nothing was changed.', 'Fixed: 0, skipped: 3')
        ->and(fixEnv($sandbox))->toBe($before)
        ->and($sandbox->mode($sandbox->dir.'/.env'))->toBe('644')
        ->and(glob($sandbox->dir.'/.env.bak-*'))->toBe([])
        ->and(fixActions($sandbox))->toBe([]);

    // The end of the input is a cancel, not a yes.
    $result = $sandbox->run(['fix', '--only', 'ENV-07', '--lang', 'en']);
    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain('Cancelled, nothing was changed.')
        ->and(fixEnv($sandbox))->toBe($before);
});

it('clears a stuck scheduler lock in the scheduler container', function (): void {
    $lock = "LOCK\x1fhdid:emd-sync-token\x1fhdid:emd-sync-token\x1f1\x1f10\n";
    $sandbox = (new InstallerSandbox)->installed();
    $sandbox->rule('docker compose exec -T scheduler php artisan tinker*', 0, $lock);

    $result = $sandbox->run(['fix', '--only', 'SCHED-01', '--lang', 'en'], "yes\n");

    expect($result->output())->toContain('a stuck lock without a process: hdid:emd-sync-token', 'Fixed: 1')
        ->and(fixActions($sandbox))->toBe(['docker compose exec -T scheduler php artisan schedule:clear-cache'])
        ->and($sandbox->callLines('sleep'))->toContain('sleep 65')
        ->and(glob($sandbox->dir.'/.env.bak-*'))->toBe([]);

    // A process runs the task by the time of the fix: the lock stays.
    $running = (new InstallerSandbox)->installed();
    $running->rule('docker compose exec -T scheduler php artisan tinker*', 0, $lock)
        ->rule('docker compose exec -T scheduler ps -o args*', 0, ["php artisan schedule:work\n", "php artisan schedule:work\n", "php artisan hdid:emd-sync-token\n"]);

    $result = $running->run(['fix', '--only', 'SCHED-01', '--lang', 'en'], "yes\n");

    expect($result->output())->toContain('the task runs now')
        ->and(fixActions($running))->toBe([]);
});

it('adds missing keys with their comments and comments out removed ones', function (): void {
    $sandbox = (new InstallerSandbox)->installed(['USER_ENTRA_MOBILE_CLIENT_ID' => 'old-client-id']);
    file_put_contents($sandbox->dir.'/.env', preg_replace('/^(HDID_PRIORITY_QUEUE|EMD_SYNC_QUEUE)=.*\n/m', '', fixEnv($sandbox)));
    $before = fixEnv($sandbox);
    $example = (string) file_get_contents($sandbox->release.'/.env.example');

    $result = $sandbox->run(['fix', '--only', 'ENV-01,ENV-02', '--lang', 'hu'], "igen\nigen\n");

    expect($result->output())->toContain('HDID_PRIORITY_QUEUE EMD_SYNC_QUEUE', 'Javítva: 2');
    $env = fixEnv($sandbox);
    $added = substr($env, strpos($env, '# --- install.sh fix '));
    // The comment block right above the key in env.example comes with it, the default too.
    preg_match('/((?:^#[^\n]*\n)+)HDID_PRIORITY_QUEUE=priority\n/m', $example, $block);
    expect($added)->toContain('az OpenHDID 1.15.0 új kulcsai ---', $block[0], "EMD_SYNC_QUEUE=sync\n")
        // Nothing before the block changed but the removed key, which is commented out, not deleted.
        ->and(str_replace("USER_ENTRA_MOBILE_CLIENT_ID=old-client-id\n", "# megszűnt (1.15.0): USER_ENTRA_MOBILE_CLIENT_ID=old-client-id\n", $before))->toBe(substr($env, 0, strlen($env) - strlen($added) - 1))
        ->and(glob($sandbox->dir.'/.env.bak-*'))->toHaveCount(1)
        ->and($sandbox->mode($sandbox->dir.'/.env'))->toBe('600');
});

it('refuses to generate an APP_KEY next to existing data', function (): void {
    $sandbox = (new InstallerSandbox)->installed(['APP_KEY' => '']);
    $sandbox->rule('docker volume ls*', 0, "openhdid_db\nopenhdid_storage\n");
    $before = fixEnv($sandbox);

    $result = $sandbox->run(['fix', '--only', 'ENV-04', '--lang', 'en'], "yes\n");

    expect($result->output())->toContain('so NO key is generated', 'openhdid_db openhdid_storage', './backup.sh list', 'to do by hand: 1')
        ->and(fixEnv($sandbox))->toBe($before)
        ->and(implode("\n", $sandbox->callLines('docker')))->not->toContain('--entrypoint php');

    // A new installation without data or containers gets one, made by the image.
    $new = (new InstallerSandbox)->installed(['APP_KEY' => '']);
    $new->rule('docker compose ps -a -q*', 0, '');

    $result = $new->run(['fix', '--only', 'ENV-04', '--lang', 'en'], "yes\n");

    expect(fixEnvValue($new, 'APP_KEY'))->toBe(InstallerSandbox::FAKE_APP_KEY)
        ->and($result->output())->toContain('APP_KEY written (51 characters)')
        ->and($result->output())->not->toContain(InstallerSandbox::FAKE_APP_KEY);
});

it('reinstalls the certificate through the container user and reloads nginx', function (): void {
    $sandbox = (new InstallerSandbox)->installed(['OPENHDID_TLS' => 'on']);
    file_put_contents($sandbox->dir.'/tls/fullchain.pem', 'the expiring certificate');
    file_put_contents($sandbox->root.'/new-fullchain.pem', "-----BEGIN CERTIFICATE-----\nnew\n-----END CERTIFICATE-----\n");
    file_put_contents($sandbox->root.'/new-privkey.pem', "-----BEGIN PRIVATE KEY-----\nnew-key-material\n-----END PRIVATE KEY-----\n");
    $sandbox->rule('docker compose run --rm --no-deps -T web tls-check*', 0, "warning: the certificate expires in 5 days (2026-10-31); renew it, then run \"openhdid reload\".\n");

    $result = $sandbox->run(['fix', '--only', 'TLS-02', '--lang', 'en'], $sandbox->root."/new-fullchain.pem\n".$sandbox->root."/new-privkey.pem\nyes\n");

    expect($result->output())->toContain('Fixed: 1', 'neither sudo nor --user 0');
    $copy = collect($sandbox->calls())->first(fn (array $call): bool => str_contains($call['line'], 'tar xf - -C /t'));
    $probe = collect($sandbox->calls())->first(fn (array $call): bool => str_contains($call['line'], 'OPENHDID_TLS_CERT=/etc/openhdid/tls-new/fullchain.pem'));
    expect($copy['stdin'])->toBeGreaterThan(0)
        ->and($copy['line'])->toContain('--entrypoint sh web -c umask 077 && tar xf - -C /t')
        ->and($probe['line'])->toContain('web tls-check')
        ->and(fixActions($sandbox))->toBe(['docker compose exec -T web openhdid reload'])
        ->and(implode("\n", $sandbox->callLines()))->not->toContain('--user')
        ->and($sandbox->callLines('sudo'))->toBe([])
        ->and((string) file_get_contents($sandbox->dir.'/tls/privkey.pem'))->toContain('new-key-material')
        ->and(glob($sandbox->dir.'/.install/tls-elozo-*/fullchain.pem'))->toHaveCount(1)
        ->and(glob($sandbox->dir.'/.install/.tmp-tls*'))->toBe([])
        ->and($result->output())->not->toContain('new-key-material');
});

it('fixes the Valkey password, the file modes, the exempt list and the nginx workers, each with its own call', function (): void {
    $sandbox = (new InstallerSandbox)->installed(['REDIS_PASSWORD' => '', 'REDIS_PASSWORD_FILE' => '', 'HDID_RATE_LIMIT_EXEMPT_NETWORKS' => '192.0.2.0/24 10.0.0.0/7', 'OPENHDID_NGINX_WORKERS' => 'auto']);
    $sandbox->env['FAKE_NPROC'] = '16';
    $secretFile = $sandbox->root.'/valkey-secret';
    file_put_contents($secretFile, "secret-from-the-file-0123456789\n");
    $sandbox->writeEnv('.env', fixEnv($sandbox), ['REDIS_PASSWORD_FILE' => $secretFile]);
    chmod($sandbox->dir.'/.env', 0640);
    $sandbox->age($sandbox->dir.'/.env', time() - 7200);

    // FILES-03, then ENV-09 (use the file: default yes), ENV-11, ENV-13.
    $result = $sandbox->run(['fix', '--lang', 'en'], "yes\n\nyes\nyes\nyes\n");

    expect($result->output())->toContain('Fixed: 4')
        ->and($sandbox->mode($sandbox->dir.'/.env'))->toBe('600')
        ->and(fixEnvValue($sandbox, 'REDIS_PASSWORD'))->toBe('secret-from-the-file-0123456789')
        ->and(fixEnv($sandbox))->toContain('# REDIS_PASSWORD_FILE='.$secretFile)
        ->and(fixEnvValue($sandbox, 'HDID_RATE_LIMIT_EXEMPT_NETWORKS'))->toBe('192.0.2.0/24')
        ->and(fixEnvValue($sandbox, 'OPENHDID_NGINX_WORKERS'))->toBe('')
        ->and((string) file_get_contents($secretFile))->toBe("secret-from-the-file-0123456789\n")
        ->and(implode("\n", $sandbox->callLines()))->not->toContain('secret-from-the-file')
        ->and($result->output())->not->toContain('secret-from-the-file')
        ->and(fixActions($sandbox))->toBe([
            'docker compose up -d --force-recreate --no-deps valkey valkey-sessions',
            'docker compose up -d',
            'docker compose up -d',
            'docker compose up -d web',
        ])
        ->and($sandbox->callLines('docker'))->toContain('docker compose run --rm --no-deps -T web check-env web');
});

it('downloads a missing profile file with its checksum and adds the override to COMPOSE_FILE', function (): void {
    $sandbox = (new InstallerSandbox)->installed();
    $assets = $sandbox->releaseAssets('assets');
    unlink($sandbox->dir.'/profiles/5k/compose.yaml');
    file_put_contents($sandbox->dir.'/compose.override.yaml', "services: {}\n");

    $result = $sandbox->run(['fix', '--from', $assets, '--lang', 'en'], "yes\nyes\n");

    expect($result->output())->toContain('profile-5k.yaml: checked', 'Fixed: 2')
        ->and(hash_file('sha256', $sandbox->dir.'/profiles/5k/compose.yaml'))->toBe(hash_file('sha256', $assets.'/profile-5k.yaml'))
        ->and(fixEnvValue($sandbox, 'COMPOSE_FILE'))->toBe('compose.yaml:profiles/5k/compose.yaml:compose.override.yaml')
        ->and(fixActions($sandbox))->toBe(['docker compose up -d']);

    // A tampered release file is not put in place.
    $tampered = (new InstallerSandbox)->installed();
    $assets = $tampered->releaseAssets('assets');
    file_put_contents($assets.'/profile-5k.yaml', "# changed\n", FILE_APPEND);
    unlink($tampered->dir.'/profiles/5k/compose.yaml');

    $result = $tampered->run(['fix', '--only', 'FILES-04', '--from', $assets, '--lang', 'en'], "yes\n");

    expect($result->output().$result->errorOutput())->toContain('does not match the release')
        ->and(is_file($tampered->dir.'/profiles/5k/compose.yaml'))->toBeFalse();
});

it('recreates the Valkey, restarts after a CA change and brings the containers up to date', function (): void {
    $sandbox = (new InstallerSandbox)->installed();
    $sandbox->rule('docker compose exec * valkey valkey-cli*PING*', 0, "NOAUTH Authentication required.\n")
        ->rule('docker compose ps -a --format*', 0, "migrate|exited||0|ghcr.io/adamfhun/openhdid:1.15.0\nweb|running|healthy|0|ghcr.io/adamfhun/openhdid:1.14.0\nworker|running||0|ghcr.io/adamfhun/openhdid:1.15.0\nsync-worker|running||0|ghcr.io/adamfhun/openhdid:1.15.0\nscheduler|running||0|ghcr.io/adamfhun/openhdid:1.15.0\nmariadb|running|healthy|0|mariadb\nvalkey|running|healthy|0|valkey\nvalkey-sessions|running|healthy|0|valkey\n");
    file_put_contents($sandbox->dir.'/ca/internal.pem', "-----BEGIN CERTIFICATE-----\nfake\n-----END CERTIFICATE-----\n");

    $result = $sandbox->run(['fix', '--lang', 'en'], "yes\nyes\nyes\n");

    // The Valkey fix brought every service up with the current files already: FIX-UP of the
    // outdated web container does not run a second time in the same run.
    expect($result->output())->toContain('Fixed: 2', 'fix FIX-UP already ran in this run')
        ->and(fixActions($sandbox))->toBe([
            'docker compose up -d --force-recreate --no-deps valkey valkey-sessions',
            'docker compose up -d',
            'docker compose restart web worker sync-worker scheduler',
        ]);

    // On its own the outdated container gets the up with --wait.
    $outdated = (new InstallerSandbox)->installed();
    $outdated->rule('docker compose ps -a --format*', 0, "migrate|exited||0|ghcr.io/adamfhun/openhdid:1.15.0\nweb|running|healthy|0|ghcr.io/adamfhun/openhdid:1.14.0\nworker|running||0|ghcr.io/adamfhun/openhdid:1.15.0\nsync-worker|running||0|ghcr.io/adamfhun/openhdid:1.15.0\nscheduler|running||0|ghcr.io/adamfhun/openhdid:1.15.0\nmariadb|running|healthy|0|mariadb\nvalkey|running|healthy|0|valkey\nvalkey-sessions|running|healthy|0|valkey\n");
    $result = $outdated->run(['fix', '--lang', 'en'], "yes\n");
    expect($result->output())->toContain('Fixed: 1')
        ->and(fixActions($outdated))->toBe(['docker compose up -d --wait --wait-timeout 600']);
});

it('refuses to run as root', function (): void {
    $sandbox = (new InstallerSandbox)->installed();
    $sandbox->env['FAKE_UID'] = '0';

    $result = $sandbox->run(['fix', '--lang', 'en'], "yes\n");

    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain('does not run as root');
});
