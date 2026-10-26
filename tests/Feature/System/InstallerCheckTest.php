<?php

use Tests\Support\InstallerSandbox;

/*
 * install.sh check: the findings of a finished installation, read through a
 * fake docker (InstallerSandbox::installed() answers like a healthy 5k stack;
 * each test breaks one thing). The check only reads: it never writes .env and
 * never passes a secret on a command line.
 */

afterEach(fn () => InstallerSandbox::cleanUpAll());

/**
 * The check as JSON: the decoded report, and the findings keyed by id (a
 * repeated id keeps every entry).
 *
 * @param  list<string>  $arguments
 * @return array{report: array<string, mixed>, findings: array<string, list<array<string, mixed>>>, exit: int, output: string}
 */
function checkReport(InstallerSandbox $sandbox, array $arguments = []): array
{
    $result = $sandbox->run(['check', '--json', '--lang', 'en', ...$arguments]);
    $report = json_decode($result->output(), true);
    expect($report)->toBeArray($result->output().$result->errorOutput());

    $findings = [];
    foreach ($report['findings'] as $finding) {
        $findings[$finding['id']][] = $finding;
    }

    return ['report' => $report, 'findings' => $findings, 'exit' => $result->exitCode(), 'output' => $result->output().$result->errorOutput()];
}

/**
 * @param  array{findings: array<string, list<array<string, mixed>>>}  $check
 * @return array<string, mixed>
 */
function checkFinding(array $check, string $id): array
{
    expect($check['findings'])->toHaveKey($id);

    return $check['findings'][$id][0];
}

it('prints valid JSON with the documented fields and exit codes 0, 1 and 2', function (): void {
    $healthy = (new InstallerSandbox)->installed();
    $check = checkReport($healthy);

    expect($check['exit'])->toBe(0, $check['output'])
        ->and($check['report'])->toHaveKeys(['schema', 'installer', 'openhdid_version', 'profile', 'role', 'lang', 'generated_at', 'exit_code', 'summary', 'findings'])
        ->and($check['report']['schema'])->toBe('openhdid-check/1')
        ->and($check['report']['profile'])->toBe('5k')
        ->and($check['report']['role'])->toBe('single')
        ->and($check['report']['summary']['error'])->toBe(0)
        ->and($check['report']['summary']['ok'])->toBeGreaterThan(40)
        ->and($check['findings'])->toBe([]);

    // A warning only: 1.
    $warning = (new InstallerSandbox)->installed(['OPENHDID_NGINX_WORKERS' => 'auto']);
    $warning->env['FAKE_NPROC'] = '16';
    $check = checkReport($warning);
    $finding = checkFinding($check, 'ENV-13');
    expect($check['exit'])->toBe(1)
        ->and($check['report']['exit_code'])->toBe(1)
        ->and(array_keys($finding))->toBe(['id', 'area', 'severity', 'title', 'detail', 'advice', 'commands', 'doc', 'fix'])
        ->and($finding['severity'])->toBe('warning')
        ->and($finding['fix'])->toBe('FIX-NGINX-WORKERS')
        ->and($finding['commands'])->toContain('./install.sh fix --only ENV-13');

    // An error: 2; the person's view says the same.
    $error = (new InstallerSandbox)->installed(['APP_KEY' => '']);
    expect(checkReport($error)['exit'])->toBe(2);
    $human = $error->run(['check', '--lang', 'hu']);
    expect($human->exitCode())->toBe(2)
        ->and($human->output())->toContain('Ellenőrzés: ', 'OpenHDID 1.15.0, 5k profil, egy gép', '[HIBA]', 'ENV-04', 'Teendő:', './install.sh fix --only ENV-04', 'Összefoglaló: 1 hiba');
});

it('recognises an old installation with database defaults while Valkey runs', function (): void {
    $sandbox = (new InstallerSandbox)->installed(['SESSION_DRIVER' => 'database', 'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'database', 'REDIS_PASSWORD' => '']);
    $sandbox->rule('docker compose exec -T web php -r *"REDIS_PASSWORD"*', 0, '');

    $check = checkReport($sandbox);
    $finding = checkFinding($check, 'ENV-07');

    expect($check['exit'])->toBe(1, $check['output'])
        ->and($finding['severity'])->toBe('warning')
        ->and($finding['fix'])->toBe('FIX-DB-TO-REDIS')
        ->and($finding['detail'])->toContain('SESSION_DRIVER, CACHE_STORE, QUEUE_CONNECTION = database', 'REDIS_PASSWORD is empty')
        ->and($finding['doc'])->toContain('TROUBLESHOOTING.md 21.')
        ->and($check['findings'])->not->toHaveKey('ENV-08');

    // A Valkey the application uses without a password is an error of its own.
    $redis = (new InstallerSandbox)->installed(['REDIS_PASSWORD' => '']);
    $check = checkReport($redis);
    expect(checkFinding($check, 'ENV-08')['severity'])->toBe('error')
        ->and($check['findings'])->not->toHaveKey('ENV-07');
});

it('finds a scheduled task stuck behind its overlap lock', function (): void {
    $lock = "LOCK\x1fhdid:emd-sync-token\x1fhdid:emd-sync-token\x1f1\x1f10\n";
    $stuck = (new InstallerSandbox)->installed();
    $stuck->rule('docker compose exec -T scheduler php artisan tinker*', 0, $lock);

    $check = checkReport($stuck);
    $finding = checkFinding($check, 'SCHED-01');

    expect($finding['severity'])->toBe('warning')
        ->and($finding['title'])->toContain('hdid:emd-sync-token')
        ->and($finding['detail'])->toContain('at most 10 minutes', 'before 1.15.0: 24 hours', 'sync_auth tile is red')
        ->and($finding['fix'])->toBe('FIX-SCHED-LOCK')
        ->and($finding['commands'])->toContain('docker compose exec scheduler php artisan schedule:clear-cache')
        ->and($finding['doc'])->toBe('TROUBLESHOOTING.md 22.');
    // Sampled twice, 5 s apart, so a lock released meanwhile is not reported.
    expect(collect($stuck->callLines('docker'))->filter(fn (string $line): bool => str_contains($line, 'tinker'))->filter(fn (string $line): bool => str_contains($line, 'scheduler'))->count())->toBe(2)
        ->and($stuck->callLines('sleep'))->toContain('sleep 5');

    // A process runs the task: its lock is right.
    $running = (new InstallerSandbox)->installed();
    $running->rule('docker compose exec -T scheduler php artisan tinker*', 0, $lock)
        ->rule('docker compose exec -T scheduler ps -o args*', 0, "PID COMMAND\nphp artisan schedule:work\nsh -c (/usr/local/bin/php artisan hdid:emd-sync-token > /dev/null 2>&1 ; php artisan schedule:finish x) &\n");
    expect(checkReport($running)['findings'])->not->toHaveKey('SCHED-01');

    // A task of the scheduler process itself: no schedule:run in three samples means stuck.
    $closure = (new InstallerSandbox)->installed();
    $closure->rule('docker compose exec -T scheduler php artisan tinker*', 0, "LOCK\x1fhdid:requeue-messages\x1f\x1f0\x1f5\n")
        ->rule('docker compose exec -T scheduler ps -o args*', 0, ["php artisan schedule:run\n", "php artisan schedule:work\n"]);
    expect(checkFinding(checkReport($closure), 'SCHED-01')['title'])->toContain('hdid:requeue-messages');
});

it('reports REDIS_PASSWORD_FILE beside the bundled Valkey as an error', function (): void {
    $sandbox = (new InstallerSandbox)->installed(['REDIS_PASSWORD' => '', 'REDIS_PASSWORD_FILE' => '/run/secrets/valkey']);

    $check = checkReport($sandbox);
    $finding = checkFinding($check, 'ENV-09');

    expect($check['exit'])->toBe(2)
        ->and($finding['severity'])->toBe('error')
        ->and($finding['fix'])->toBe('FIX-REDIS-PASSWORD-FILE')
        ->and($finding['detail'])->toContain('requirepass')
        ->and($check['findings'])->not->toHaveKey('ENV-08');

    // With an external Valkey the secret file is the right way.
    $external = (new InstallerSandbox)->installed(['REDIS_PASSWORD' => '', 'REDIS_PASSWORD_FILE' => '/run/secrets/valkey', 'REDIS_HOST' => 'valkey.example.org', 'COMPOSE_PROFILES' => 'db', 'REDIS_PREFIX' => 'openhdid-x-']);
    expect(checkReport($external)['findings'])->not->toHaveKey('ENV-09');
});

it('reports a Valkey password that differs from the application one', function (): void {
    $sandbox = (new InstallerSandbox)->installed();
    $sandbox->rule('docker compose exec * valkey valkey-cli*PING*', 0, "WRONGPASS invalid username-password pair or user is disabled.\n");

    $check = checkReport($sandbox);
    $finding = checkFinding($check, 'ENV-10');

    expect($finding['severity'])->toBe('error')
        ->and($finding['fix'])->toBe('FIX-VALKEY-RECREATE')
        ->and($finding['detail'])->toContain('valkey:', strlen(InstallerSandbox::REDIS_PASSWORD).' characters');
    // The password travels in the environment of the docker client only, never as an argument.
    $ping = collect($sandbox->calls())->first(fn (array $call): bool => str_contains($call['line'], 'valkey-cli') && str_contains($call['line'], 'PING'));
    expect($ping['env'])->toBe(['REDISCLI_AUTH' => strlen(InstallerSandbox::REDIS_PASSWORD)])
        ->and(implode("\n", $sandbox->callLines()))->not->toContain(InstallerSandbox::REDIS_PASSWORD)
        ->and($check['output'])->not->toContain(InstallerSandbox::REDIS_PASSWORD);
});

it('warns about auto nginx workers on a many-core host', function (): void {
    $sandbox = (new InstallerSandbox)->installed(['OPENHDID_NGINX_WORKERS' => 'auto']);
    $sandbox->env['FAKE_NPROC'] = '16';

    $finding = checkFinding(checkReport($sandbox), 'ENV-13');

    expect($finding['detail'])->toContain('16 CPUs', "the profile's 4", '960 MB')
        ->and($finding['doc'])->toBe('TROUBLESHOOTING.md 18.');

    // As many CPUs as the profile's workers: auto changes nothing.
    $small = (new InstallerSandbox)->installed(['OPENHDID_NGINX_WORKERS' => 'auto']);
    $small->env['FAKE_NPROC'] = '4';
    expect(checkReport($small)['findings'])->not->toHaveKey('ENV-13');
});

it('reports a missing profile file and an override left out of COMPOSE_FILE', function (): void {
    $missing = (new InstallerSandbox)->installed();
    unlink($missing->dir.'/profiles/5k/compose.yaml');

    $check = checkReport($missing);
    $finding = checkFinding($check, 'FILES-04');

    expect($finding['severity'])->toBe('error')
        ->and($finding['detail'])->toContain('profiles/5k/compose.yaml')
        ->and($finding['fix'])->toBe('FIX-PROFILE-FILE')
        // Every docker compose command would fail: the dependent checks say why they did not run.
        ->and(checkFinding($check, 'FILES-06')['severity'])->toBe('info')
        ->and(checkFinding($check, 'FILES-06')['title'])->toContain('FILES-04')
        ->and($missing->callLines('docker'))->not->toContain('docker compose config --quiet');

    $override = (new InstallerSandbox)->installed();
    file_put_contents($override->dir.'/compose.override.yaml', "services:\n  web:\n    extra_hosts: ['emd.example.org:192.0.2.5']\n");

    $finding = checkFinding(checkReport($override), 'FILES-05');
    expect($finding['severity'])->toBe('warning')
        ->and($finding['fix'])->toBe('FIX-OVERRIDE')
        ->and($finding['detail'])->toContain('COMPOSE_FILE=compose.yaml:profiles/5k/compose.yaml');
});

it('maps the container start check to findings', function (): void {
    $sandbox = (new InstallerSandbox)->installed();
    $sandbox->rule('docker compose run --rm --no-deps -T web check-env*', 64, "2026-10-26T10:00:00Z openhdid: warning: max_execution_time is 200 s; 300 s is recommended for large EMD imports.\n2026-10-26T10:00:00Z openhdid: ERROR: '10.0.0.0/7' in HDID_RATE_LIMIT_EXEMPT_NETWORKS is too wide: a network may be at most /8 (IPv4) or /32 (IPv6); list the company's own addresses.\n");

    $check = checkReport($sandbox);
    $exempt = checkFinding($check, 'ENV-11');

    expect($exempt['severity'])->toBe('error')
        ->and($exempt['detail'])->toStartWith("'10.0.0.0/7' in HDID_RATE_LIMIT_EXEMPT_NETWORKS is too wide")
        ->and($exempt['fix'])->toBe('FIX-EXEMPT')
        ->and(checkFinding($check, 'ENV-17')['severity'])->toBe('warning')
        ->and(checkFinding($check, 'ENV-17')['detail'])->toContain('max_execution_time is 200 s');

    // An image older than check-env: the same rules through artisan --version.
    $old = (new InstallerSandbox)->installed();
    $old->rule('docker compose run --rm --no-deps -T web check-env*', 64, "2026-10-26T10:00:00Z openhdid: ERROR: unknown command 'check-env' (web, worker, scheduler)\n")
        ->rule('docker compose run --rm --no-deps -T web artisan --version*', 64, "2026-10-26T10:00:00Z openhdid: ERROR: APP_URL must be the public https:// address of the site\n");

    $check = checkReport($old);
    expect($old->callLines('docker'))->toContain('docker compose run --rm --no-deps -T web artisan --version')
        ->and(checkFinding($check, 'ENV-05')['detail'])->toContain('APP_URL must be the public https:// address');
});

it('checks file modes, certificate expiry and a CA folder changed after the start', function (): void {
    $sandbox = (new InstallerSandbox)->installed(['OPENHDID_TLS' => 'on']);
    chmod($sandbox->dir.'/.env', 0644);
    file_put_contents($sandbox->dir.'/ca/internal-ca.crt', "-----BEGIN CERTIFICATE-----\nfake\n-----END CERTIFICATE-----\n");
    $sandbox->rule('docker compose run --rm --no-deps -T web tls-check*', 0, "subject: hdid.example.org; issuer: Example CA\nvalid until 2026-11-07 00:00 UTC (12 days)\nwarning: the certificate expires in 12 days (2026-11-07); renew it, then run \"openhdid reload\".\nTLS files are usable.\n");

    $check = checkReport($sandbox);

    expect(checkFinding($check, 'FILES-03')['detail'])->toContain('.env 644')
        ->and(checkFinding($check, 'FILES-03')['fix'])->toBe('FIX-PERMS')
        ->and(checkFinding($check, 'TLS-02')['severity'])->toBe('warning')
        ->and(checkFinding($check, 'TLS-02')['detail'])->toContain('expires in 12 days')
        ->and(checkFinding($check, 'TLS-02')['fix'])->toBe('FIX-TLS-INSTALL')
        ->and(checkFinding($check, 'TLS-04')['detail'])->toContain('ca/internal-ca.crt')
        ->and(checkFinding($check, 'TLS-04')['fix'])->toBe('FIX-CA-RESTART');

    // An expired certificate and a key of another one are errors.
    $broken = (new InstallerSandbox)->installed(['OPENHDID_TLS' => 'on']);
    $broken->rule('docker compose run --rm --no-deps -T web tls-check*', 1, "ERROR: the private key does not belong to the certificate.\nERROR: the certificate expired on 2026-10-01.\nTLS files are NOT usable.\n");
    $check = checkReport($broken);
    expect(checkFinding($check, 'TLS-03')['severity'])->toBe('error')
        ->and(checkFinding($check, 'TLS-02')['severity'])->toBe('error');
});

it('gives a host B that has just started the same grace for the heartbeats from host A, and points it to host A afterwards', function (): void {
    $tiles = "HEALTH\x1fdatabase\x1fok\x1fDatabase\x1fok\x1f\nHEALTH\x1fscheduler\x1ffail\x1fScheduler\x1fNo run recorded yet\x1fRed: the scheduler does not run\nHEALTH\x1fqueue\x1ffail\x1fQueue\x1fdefault: no worker yet\x1fRed: no heartbeat from the worker\n";
    $justStarted = gmdate('Y-m-d\\TH:i:s', time() - 30).'.000000000Z';

    // Host B a few seconds after its start: host A's heartbeats have not arrived yet.
    $fresh = (new InstallerSandbox)->installed([], '10k-ha', 'b');
    $fresh->rule('docker compose exec -T web sh -c *hdid:health*', 0, $tiles)
        ->rule('docker inspect *', 0, "migrate|{$justStarted}|false|0\nweb|{$justStarted}|false|0\nworker|{$justStarted}|false|0\n");
    $check = checkReport($fresh);

    expect(checkFinding($check, 'HEALTH-scheduler')['severity'])->toBe('info')
        ->and(checkFinding($check, 'HEALTH-queue')['severity'])->toBe('info');

    // An hour later the missing scheduler is an error, and the advice sends the operator to host A.
    $later = (new InstallerSandbox)->installed([], '10k-ha', 'b');
    $later->rule('docker compose exec -T web sh -c *hdid:health*', 0, $tiles);
    $scheduler = checkFinding(checkReport($later), 'HEALTH-scheduler');

    expect($scheduler['severity'])->toBe('error')
        ->and($scheduler['advice'])->toContain('host B', 'host A');
});

it('turns unconfigured integration tiles into warnings and keeps real failures as errors', function (): void {
    $tiles = "HEALTH\x1fdatabase\x1fok\x1fDatabase\x1fok\x1f\nHEALTH\x1fmail\x1ffail\x1fMail\x1fEWS_URL is missing\x1fRed: the EWS URL or credentials are missing\nHEALTH\x1fpackage_overrides\x1finfo\x1fPackage overrides\x1f1 client\x1f\n";
    $fresh = (new InstallerSandbox)->installed(['MAIL_MAILER' => 'log']);
    $fresh->rule('docker compose exec -T web sh -c *hdid:health*', 0, $tiles);

    $check = checkReport($fresh);
    $mail = checkFinding($check, 'HEALTH-mail');

    expect($mail['severity'])->toBe('warning')
        ->and($mail['title'])->toContain('not connected yet')
        ->and($mail['advice'])->toContain('chapter 11', 'Red: the EWS URL or credentials are missing')
        ->and(checkFinding($check, 'HEALTH-package_overrides')['severity'])->toBe('info');

    $connected = (new InstallerSandbox)->installed(['MAIL_MAILER' => 'ews']);
    $connected->rule('docker compose exec -T web sh -c *hdid:health*', 0, $tiles);
    expect(checkFinding(checkReport($connected), 'HEALTH-mail')['severity'])->toBe('error');

    // The database tile is DB-01.
    $database = (new InstallerSandbox)->installed();
    $database->rule('docker compose exec -T web sh -c *hdid:health*', 0, "HEALTH\x1fdatabase\x1ffail\x1fDatabase\x1fSQLSTATE[HY000] [1045] Access denied\x1fCheck the DB_* values\n");
    expect(checkFinding(checkReport($database), 'DB-01')['severity'])->toBe('error');
});

it('flags a scheduler running on host B and an answers file left behind', function (): void {
    $sandbox = (new InstallerSandbox)->installed([
        'COMPOSE_PROFILES' => '', 'DB_HOST' => '10.0.0.5', 'REDIS_HOST' => '10.0.0.5', 'REDIS_SESSION_HOST' => '10.0.0.5',
        'REDIS_PREFIX' => 'openhdid-a1b2c3-', 'OPENHDID_NFS_SERVER' => '10.0.0.5', 'OPENHDID_NFS_PATH' => '/export/openhdid-storage',
    ], '10k-ha', 'b');
    $sandbox->answers(['PROFILE' => '10k-ha'], 'openhdid-10k-answers.txt', 0600, $sandbox->dir);
    $sandbox->rule('docker compose ps -a --format*', 0, "web|running|healthy|0|ghcr.io/adamfhun/openhdid:1.15.0\nworker|running||0|ghcr.io/adamfhun/openhdid:1.15.0\nscheduler|running||0|ghcr.io/adamfhun/openhdid:1.15.0\n");

    $check = checkReport($sandbox);

    expect($check['report']['role'])->toBe('b')
        ->and(checkFinding($check, 'SVC-05')['severity'])->toBe('error')
        ->and(checkFinding($check, 'SVC-05')['detail'])->toContain('scheduler')
        ->and(checkFinding($check, 'FILES-08')['commands'])->toContain('rm openhdid-10k-answers.txt')
        ->and($check['findings'])->not->toHaveKey('ENV-16')
        // Host B runs no scheduler of its own: no lock check there.
        ->and($check['findings'])->not->toHaveKey('SCHED-01');
});

it('finds the contradictions of the two-host roles and the services that do not run', function (): void {
    $sandbox = (new InstallerSandbox)->installed(['COMPOSE_PROFILES' => 'db,redis', 'OPENHDID_TLS' => 'on'], '10k-ha', 'a');
    $sandbox->rule('docker compose ps -a --format*', 0, "migrate|exited||1|ghcr.io/adamfhun/openhdid:1.15.0\nweb|exited||64|ghcr.io/adamfhun/openhdid:1.15.0\n")
        ->rule('docker compose logs --no-color --no-log-prefix --tail 20 web*', 0, "2026-10-26T10:00:00Z openhdid: ERROR: DB_PASSWORD is empty: the database account would have no password.\n")
        ->rule('docker inspect *', 0, "web|2026-10-26T08:00:00Z|true|3\n");

    $check = checkReport($sandbox);
    $roles = checkFinding($check, 'ENV-16');
    $web = collect($check['findings']['SVC-01'])->first(fn (array $finding): bool => str_contains($finding['title'], 'web'));

    expect($roles['detail'])->toContain('COMPOSE_PROFILES lacks primary', 'the db or the redis profile is on', 'OPENHDID_TLS=on', 'OPENHDID_NFS_SERVER')
        ->and($web['detail'])->toContain('exited (exit code 64)', 'DB_PASSWORD is empty')
        ->and($web['fix'])->toBe('FIX-UP')
        ->and(checkFinding($check, 'SVC-02')['severity'])->toBe('error')
        ->and(checkFinding($check, 'SVC-04')['title'])->toContain('OOMKilled')
        ->and($check['findings'])->toHaveKey('HEALTH-all');
});

it('compares the keys of .env with the release and names the removed and the old ones', function (): void {
    $sandbox = (new InstallerSandbox)->installed(['USER_ENTRA_MOBILE_CLIENT_ID' => 'abc', 'SYNC_API_URL' => 'https://emd.example.org/api', 'MY_OWN_KEY' => '1']);
    $env = (string) file_get_contents($sandbox->dir.'/.env');
    file_put_contents($sandbox->dir.'/.env', preg_replace('/^(HDID_PRIORITY_QUEUE|EMD_SYNC_QUEUE)=.*\n/m', '', $env));

    $check = checkReport($sandbox);

    expect(checkFinding($check, 'ENV-01')['detail'])->toContain('HDID_PRIORITY_QUEUE', 'EMD_SYNC_QUEUE')
        ->and(checkFinding($check, 'ENV-01')['fix'])->toBe('FIX-ENV-KEYS')
        ->and($check['findings']['ENV-02'][0]['detail'])->toContain('USER_ENTRA_MOBILE_CLIENT_ID')
        ->and($check['findings']['ENV-02'][0]['fix'])->toBe('FIX-ENV-OBSOLETE')
        ->and($check['findings']['ENV-02'][1]['detail'])->toContain('SYNC_API_URL', 'MY_OWN_KEY')
        ->and($check['findings']['ENV-02'][1]['fix'])->toBe('');
});

it('changes nothing and passes no secret on a command line while it checks', function (): void {
    $sandbox = (new InstallerSandbox)->installed();
    $before = [(string) file_get_contents($sandbox->dir.'/.env'), (string) file_get_contents($sandbox->dir.'/db.env')];

    $result = $sandbox->run(['check', '--lang', 'en']);

    $calls = implode("\n", $sandbox->callLines());
    expect([(string) file_get_contents($sandbox->dir.'/.env'), (string) file_get_contents($sandbox->dir.'/db.env')])->toBe($before)
        ->and(glob($sandbox->dir.'/.env.bak-*'))->toBe([]);
    foreach ([InstallerSandbox::DB_PASSWORD, InstallerSandbox::REDIS_PASSWORD, InstallerSandbox::MIGRATE_PASSWORD, InstallerSandbox::ROOT_PASSWORD, InstallerSandbox::FAKE_APP_KEY] as $secret) {
        expect($calls)->not->toContain($secret)
            ->and($result->output())->not->toContain($secret);
    }
    // Only reading commands reach the stack.
    foreach ($sandbox->callLines('docker') as $line) {
        expect($line)->not->toMatch('/compose (up|down|stop|restart|rm|pull)\b|schedule:clear-cache|volume rm/');
    }
    foreach ($sandbox->calls() as $call) {
        expect($call['stdin'])->toBe(0, $call['line']);
    }
});

it('checks the infra host of the two-host profile', function (): void {
    $sandbox = new InstallerSandbox;
    copy($sandbox->release.'/profiles/10k/infra.yaml', $sandbox->dir.'/infra.yaml');
    mkdir($sandbox->dir.'/profiles/10k', 0755, true);
    rename($sandbox->dir.'/infra.yaml', $sandbox->dir.'/profiles/10k/infra.yaml');
    $sandbox->writeEnv('.env', "COMPOSE_PROJECT_NAME=openhdid-infra\nCOMPOSE_FILE=profiles/10k/infra.yaml\n", [
        'OPENHDID_INFRA_BIND_IP' => '0.0.0.0', 'DB_DATABASE' => 'openhdid', 'DB_USERNAME' => 'openhdid', 'DB_PASSWORD' => InstallerSandbox::DB_PASSWORD,
        'REDIS_PASSWORD' => InstallerSandbox::REDIS_PASSWORD, 'REDIS_SESSION_PASSWORD' => '',
    ]);
    $sandbox->writeEnv('db.env', '', ['MARIADB_ROOT_PASSWORD' => InstallerSandbox::ROOT_PASSWORD, 'DB_USERNAME' => 'openhdid_migrate', 'DB_PASSWORD' => InstallerSandbox::MIGRATE_PASSWORD]);
    $sandbox->rule('docker compose config --services*', 0, "mariadb\nvalkey\nvalkey-sessions\n")
        ->rule('docker compose ps -a --format*', 0, "mariadb|running|healthy|0|mariadb\nvalkey|running|healthy|0|valkey\nvalkey-sessions|exited|unhealthy|1|valkey\n")
        ->rule('docker compose exec * valkey valkey-cli*maxmemory-policy*', 0, "maxmemory-policy\nvolatile-lru\n")
        ->rule('docker compose exec * valkey valkey-cli*maxmemory*', 0, "maxmemory\n268435456\n")
        ->rule('docker compose exec * mariadb sh -c*SHOW GRANTS*', 0, "GRANT ALL PRIVILEGES ON `openhdid`.* TO `openhdid`@`%`\nGRANT ALL PRIVILEGES ON `openhdid`.* TO `openhdid_migrate`@`%`\n");

    $check = checkReport($sandbox);

    expect($check['report']['role'])->toBe('infra')
        ->and(checkFinding($check, 'INFRA-03')['severity'])->toBe('warning')
        ->and(checkFinding($check, 'ENV-08')['detail'])->toContain('REDIS_SESSION_PASSWORD')
        ->and(checkFinding($check, 'INFRA-01')['title'])->toContain('valkey-sessions')
        ->and(checkFinding($check, 'INFRA-02')['detail'])->toContain('volatile-lru', 'noeviction')
        ->and(checkFinding($check, 'INFRA-04')['title'])->toContain('openhdid')
        // The root password stays in the container's own environment.
        ->and(implode("\n", $sandbox->callLines()))->not->toContain(InstallerSandbox::ROOT_PASSWORD)
        ->and(implode("\n", $sandbox->callLines()))->toContain('MYSQL_PWD="$MARIADB_ROOT_PASSWORD"');
});

it('checks the Kubernetes files without touching the cluster', function (): void {
    $sandbox = new InstallerSandbox;
    mkdir($sandbox->dir.'/k8s', 0700);
    file_put_contents($sandbox->dir.'/k8s/kustomization.yaml', "resources:\n  - kubernetes/overlays/20k\n");
    file_put_contents($sandbox->dir.'/k8s/openhdid-env.env', "OPENHDID_VERSION=1.15.0\nAPP_KEY=".InstallerSandbox::FAKE_APP_KEY."\nAPP_URL=https://hdid.example.org\nDB_PASSWORD=\nREDIS_PASSWORD=".InstallerSandbox::REDIS_PASSWORD."\n");
    file_put_contents($sandbox->dir.'/k8s/openhdid-db-env.env', "DB_USERNAME=openhdid_migrate\nDB_PASSWORD=".InstallerSandbox::MIGRATE_PASSWORD."\n");
    chmod($sandbox->dir.'/k8s/openhdid-env.env', 0644);
    chmod($sandbox->dir.'/k8s/openhdid-db-env.env', 0600);
    $sandbox->rule('kubectl kustomize*', 1, '', "error: accumulating resources: 'kubernetes/overlays/20k': no such file or directory\n");

    $check = checkReport($sandbox);

    expect($check['report']['profile'])->toBe('20k')
        ->and(checkFinding($check, 'K8S-01')['detail'])->toContain('k8s/openhdid-env.env 644')
        ->and(checkFinding($check, 'K8S-01')['fix'])->toBe('FIX-PERMS')
        ->and(checkFinding($check, 'K8S-02')['severity'])->toBe('error')
        ->and(checkFinding($check, 'K8S-02')['detail'])->toContain('no such file or directory')
        ->and(checkFinding($check, 'ENV-21')['severity'])->toBe('error')
        ->and($sandbox->callLines('kubectl'))->toBe(['kubectl kustomize k8s'])
        ->and($sandbox->callLines('docker'))->toBe([]);
});

it('checks only the HTTP port behind a TLS-terminating load balancer', function (): void {
    // TLS off (the default of installed()): an unpublished HTTPS port is fine, as in a two-host 10k on one machine.
    $sandbox = (new InstallerSandbox)->installed();
    $sandbox->rule('docker compose port web 8443*', 1, '');
    expect(checkReport($sandbox)['findings'])->not->toHaveKey('NET-03');

    // The HTTP port still has to be the configured one.
    $sandbox->rule('docker compose port web 8080*', 0, "0.0.0.0:8081\n");
    expect(checkFinding(checkReport($sandbox), 'NET-03')['detail'])->toContain('8080 → 0.0.0.0:8081');

    // With the container's own TLS the HTTPS port is the one that serves.
    $tls = (new InstallerSandbox)->installed(['OPENHDID_TLS' => 'on']);
    $tls->rule('docker compose port web 8443*', 1, '');
    expect(checkFinding(checkReport($tls), 'NET-03')['detail'])->toContain('8443 → -');
});

it('names a ca folder or CA file the container user cannot read', function (): void {
    $sandbox = (new InstallerSandbox)->installed();
    file_put_contents($sandbox->dir.'/ca/internal.pem', "-----BEGIN CERTIFICATE-----\nca\n-----END CERTIFICATE-----\n");
    chmod($sandbox->dir.'/ca/internal.pem', 0644);
    chmod($sandbox->dir.'/ca', 0755);
    expect(checkReport($sandbox)['findings'])->not->toHaveKey('TLS-05');

    // A CA copied in with umask 077: no CA is taken at start, silently.
    chmod($sandbox->dir.'/ca/internal.pem', 0600);
    $finding = checkFinding(checkReport($sandbox), 'TLS-05');
    expect($finding['severity'])->toBe('warning')
        ->and($finding['detail'])->toContain('ca/internal.pem (600)')
        ->and($finding['doc'])->toBe('TROUBLESHOOTING.md 10.')
        ->and(implode("\n", $finding['commands']))->toContain('chmod 755 ca && chmod 644 ca/*.pem');

    chmod($sandbox->dir.'/ca', 0700);
    expect(checkFinding(checkReport($sandbox), 'TLS-05')['detail'])->toContain('ca/ (700)');
});

it('compares the configuration of the running containers with the current files by their Compose hash', function (): void {
    $hashes = fn (string $web): string => "migrate 1111111111111111\nweb {$web}\nworker 2222222222222222\nsync-worker 3333333333333333\nscheduler 4444444444444444\nmariadb 5555555555555555\nvalkey 6666666666666666\nvalkey-sessions 7777777777777777\n";
    $inspect = function (InstallerSandbox $sandbox): void {
        $started = gmdate('Y-m-d\TH:i:s', time() - 3600).'.123456789Z';
        $lines = [];
        foreach (['migrate' => '1111111111111111', 'web' => 'aaaaaaaaaaaaaaaa', 'worker' => '2222222222222222', 'sync-worker' => '3333333333333333', 'scheduler' => '4444444444444444', 'mariadb' => '5555555555555555', 'valkey' => '6666666666666666', 'valkey-sessions' => '7777777777777777'] as $service => $hash) {
            $lines[] = "{$service}|{$started}|false|0|{$hash}";
        }
        $sandbox->rule('docker inspect *', 0, implode("\n", $lines)."\n");
    };

    // .env was touched after the start (changed and changed back): the same configuration, no finding.
    $same = (new InstallerSandbox)->installed();
    $inspect($same);
    $same->rule('docker compose config --hash*', 0, $hashes('aaaaaaaaaaaaaaaa'));
    touch($same->dir.'/.env');
    expect(checkReport($same)['findings'])->not->toHaveKey('SVC-03');

    // A configuration the web container was not made from: named, with the fix.
    $changed = (new InstallerSandbox)->installed();
    $inspect($changed);
    $changed->rule('docker compose config --hash*', 0, $hashes('bbbbbbbbbbbbbbbb'));
    $finding = checkFinding(checkReport($changed), 'SVC-03');
    expect($finding['detail'])->toContain('another configuration than the current files', 'web')
        ->and($finding['detail'])->not->toContain('worker')
        ->and($finding['fix'])->toBe('FIX-UP');

    // Without the hashes (an older Compose) the file times decide, as before.
    $old = (new InstallerSandbox)->installed();
    touch($old->dir.'/.env');
    expect(checkFinding(checkReport($old), 'SVC-03')['detail'])->toContain('.env');
});
