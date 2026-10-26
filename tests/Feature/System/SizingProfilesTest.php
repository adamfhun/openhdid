<?php

/*
 * The usage profiles of the container release (compose.yaml, profiles/5k and
 * 10k, the Kubernetes base and overlays) size every service for twice the
 * normal peak. These tests check that the limits add up: a container's memory
 * ceiling holds the hard limits of what runs in it (PHP memory_limit, the
 * opcache, nginx, the memory-backed /tmp, the buffers of MariaDB, the fork of
 * Valkey), every possible database connection fits max_connections, and the
 * ceilings of a host (or of the pods at the HPA maximum, one node lost) fit
 * the machine its profile names. No YAML parser ships with the release, so the
 * files are read with the same few patterns everywhere.
 */

/** Memory of a PHP process outside its memory_limit (code, libraries, buffers), MB. */
const SIZING_PHP_OVERHEAD = 22;
/** opcache.memory_consumption + interned strings of docker/php.ini, MB. */
const SIZING_OPCACHE = 208;
/** One nginx worker with full connection buffers, MB. */
const SIZING_NGINX_WORKER = 80;
/** memory_limit of the CLI (worker, scheduler, migrate) in docker/php.ini, MB. */
const SIZING_CLI_MEMORY_LIMIT = 256;
/** MariaDB memory besides the buffer pool and the connections, MB. */
const SIZING_DB_GLOBAL = 192;
/** MariaDB memory per connection (sort, join and read buffers, an in-memory temporary table), MB. */
const SIZING_DB_PER_CONNECTION = 20;
/** Memory a Docker host keeps for its operating system and the daemon, MB. */
const SIZING_HOST_OS = 1024;
/** Memory a Kubernetes node keeps for itself (kubelet, system pods), MB. */
const SIZING_NODE_RESERVED = 1536;
/** Database connections besides PHP-FPM that are not counted per service: a migration, backup, artisan. */
const SIZING_DB_SPARE = 6;

function sizingReleaseFile(string $path): string
{
    foreach ([base_path('deploy/openhdid/repo/'.$path), base_path($path)] as $candidate) {
        if (is_file($candidate)) {
            return (string) file_get_contents($candidate);
        }
    }

    test()->markTestSkipped('The container release files are not part of this tree.');
}

/**
 * A size as the files write it (1920m, 1g, 2Gi, 384Mi, 256mb, 128M) in MB.
 */
function sizingMb(string $size): int
{
    preg_match('/^(\d+)\s*([kmg])i?b?$/i', trim($size), $match);
    expect($match)->not->toBeEmpty("not a size: {$size}");

    return match (strtolower($match[2])) {
        'g' => (int) $match[1] * 1024,
        'm' => (int) $match[1],
        default => intdiv((int) $match[1], 1024),
    };
}

/**
 * The value of "${NAME:-default}", or the plain value.
 */
function sizingDefault(string $value): string
{
    return preg_match('/^\$\{[A-Z0-9_]+:-([^}]*)\}$/', trim($value, " \"'"), $match) ? $match[1] : trim($value, " \"'");
}

/**
 * The indented block of a service or config in a Compose file.
 */
function sizingComposeBlock(string $compose, string $section, string $name): string
{
    preg_match('/^'.$section.':\n(.*?)(?=^\S|\z)/ms', $compose, $sectionMatch);
    preg_match('/^  '.preg_quote($name, '/').':\n((?:(?:    .*)?\n)+)/m', $sectionMatch[1] ?? '', $blockMatch);

    return $blockMatch[1] ?? '';
}

/**
 * A setting of a service: the profile's value when it sets one, else the base's.
 */
function sizingComposeValue(array $files, string $section, string $service, string $pattern): ?string
{
    foreach (array_reverse($files) as $file) {
        if (preg_match($pattern, sizingComposeBlock($file, $section, $service), $match)) {
            return sizingDefault($match[1]);
        }
    }

    return null;
}

/**
 * The Compose services of a profile with their memory ceilings and what has to fit in them.
 *
 * @param  list<string>  $files  compose.yaml, then the profile file
 * @param  list<string>  $services
 * @return array<string, int> service => mem_limit in MB
 */
function sizingComposeCeilings(array $files, array $services): array
{
    $ceilings = [];

    foreach ($services as $service) {
        $limit = sizingComposeValue($files, 'services', $service, '/^    mem_limit: (\S+)$/m');
        expect($limit)->not->toBeNull("{$service} has no mem_limit");
        $ceilings[$service] = sizingMb((string) $limit);
    }

    return $ceilings;
}

function sizingComposeTmp(array $files, string $service): int
{
    $tmp = sizingComposeValue($files, 'services', $service, '/^      - \/tmp:size=(\w+),/m')
        ?? sizingComposeValue($files, 'services', 'x-app', '/^      - \/tmp:size=(\w+),/m');

    if ($tmp === null) {
        preg_match('/^x-app: &app\n(.*?)(?=^\S)/ms', $files[0], $anchor);
        preg_match('/\/tmp:size=(\w+),/', $anchor[1] ?? '', $match);
        $tmp = $match[1] ?? null;
    }

    expect($tmp)->not->toBeNull("{$service} has no /tmp size");

    return sizingMb((string) $tmp);
}

/**
 * A profile's own words about its machine: "<n> vCPU, <m> GB".
 *
 * @return array{cpus: int, memory: int}
 */
function sizingMachine(string $file, string $phrase): array
{
    $text = preg_replace('/\n#\s*/', ' ', $file);
    expect($text)->toContain($phrase);
    preg_match('/(\d+) vCPU,? (?:és )?(\d+) GB/', $phrase, $match);

    return ['cpus' => (int) $match[1], 'memory' => (int) $match[2] * 1024];
}

/**
 * The checks every Compose profile passes; returns the host's total of ceilings.
 *
 * @param  list<string>  $files
 * @param  array<string, string>  $webEnvironment  the PHP-FPM values of the web service
 * @param  list<string>  $services  the services that run on the host
 * @param  int  $workers  queue workers of the whole installation, $hostWorkers of them on this host
 */
function sizingCheckComposeHost(array $files, array $webEnvironment, int $cpus, array $services, int $workers, int $webServers, ?int $maxConnections = null, int $hostWorkers = 1): int
{
    $ceilings = sizingComposeCeilings($files, $services);

    // Web: every PHP-FPM process at its memory_limit, the opcache, the nginx workers and /tmp.
    $children = (int) $webEnvironment['OPENHDID_FPM_MAX_CHILDREN'];
    $perRequest = sizingMb($webEnvironment['OPENHDID_FPM_MEMORY_LIMIT']);
    // The nginx workers are pinned (not auto, which would start one per CPU of a larger host).
    $nginx = sizingComposeValue($files, 'services', 'web', '/OPENHDID_NGINX_WORKERS: (\S+)$/m');
    expect($nginx)->toMatch('/^\d+$/', 'web: OPENHDID_NGINX_WORKERS has a numeric default')
        ->and((int) $nginx)->toBeLessThanOrEqual($cpus, 'web: no more nginx workers than the host has CPUs');
    $web = $children * ($perRequest + SIZING_PHP_OVERHEAD) + SIZING_OPCACHE + (int) $nginx * SIZING_NGINX_WORKER + sizingComposeTmp($files, 'web');
    expect($ceilings['web'])->toBeGreaterThanOrEqual($web, "web: {$children} × ({$perRequest} + ".SIZING_PHP_OVERHEAD.") + opcache + {$nginx} nginx workers + /tmp");

    // One CLI process each, the scheduler with two background tasks.
    $cli = SIZING_CLI_MEMORY_LIMIT + SIZING_PHP_OVERHEAD;
    foreach (['worker' => 1, 'sync-worker' => 1, 'migrate' => 1, 'scheduler' => 3] as $service => $processes) {
        if (isset($ceilings[$service])) {
            expect($ceilings[$service])->toBeGreaterThanOrEqual($processes * $cli + sizingComposeTmp($files, $service), $service);
        }
    }

    // Every connection the installation may open, from every web server.
    $connections = $children * $webServers + $workers + 1 + 3 + SIZING_DB_SPARE;
    if (isset($ceilings['mariadb'])) {
        $pool = sizingMb((string) sizingComposeValue($files, 'configs', 'mariadb-server', '/innodb_buffer_pool_size = (\S+)$/m'));
        $maxConnections = (int) sizingComposeValue($files, 'configs', 'mariadb-server', '/max_connections = (\S+)$/m');
        expect($ceilings['mariadb'])->toBeGreaterThanOrEqual($pool + SIZING_DB_GLOBAL + $maxConnections * SIZING_DB_PER_CONNECTION, 'mariadb');
    }
    if ($maxConnections !== null) {
        expect($maxConnections)->toBeGreaterThanOrEqual($connections, "max_connections for {$connections} possible connections");
    }

    // Valkey forks for the AOF rewrite or the RDB snapshot while the store is full.
    foreach (['valkey', 'valkey-sessions'] as $store) {
        if (isset($ceilings[$store])) {
            $maxmemory = sizingMb((string) sizingComposeValue($files, 'configs', $store, '/^\s+maxmemory (\S+)$/m'));
            expect($ceilings[$store])->toBeGreaterThanOrEqual(2 * $maxmemory + 128, $store);
        }
    }

    return array_sum($ceilings) + ($hostWorkers - 1) * ($ceilings['worker'] ?? 0);
}

it('fits every ceiling of the trial installation without a profile on its 2 vCPU, 8 GB host', function (): void {
    $compose = sizingReleaseFile('compose.yaml');
    $dockerfile = sizingReleaseFile('Dockerfile');
    preg_match('/OPENHDID_FPM_MAX_CHILDREN=(\d+)/', $dockerfile, $children);
    preg_match('/OPENHDID_FPM_MEMORY_LIMIT=(\w+)/', $dockerfile, $limit);
    $machine = sizingMachine($compose, 'a gép 2 vCPU és 8 GB');

    $total = sizingCheckComposeHost([$compose], ['OPENHDID_FPM_MAX_CHILDREN' => $children[1], 'OPENHDID_FPM_MEMORY_LIMIT' => $limit[1]], $machine['cpus'],
        ['web', 'worker', 'sync-worker', 'scheduler', 'migrate', 'mariadb', 'valkey', 'valkey-sessions'], workers: 1, webServers: 1);

    expect($total + SIZING_HOST_OS)->toBeLessThanOrEqual($machine['memory']);
});

it('fits every ceiling of the 5k profile on its 4 vCPU, 10 GB host, with room for twice the normal peak', function (): void {
    $files = [sizingReleaseFile('compose.yaml'), sizingReleaseFile('profiles/5k/compose.yaml')];
    $machine = sizingMachine($files[1], 'Gazdagép: 4 vCPU, 10 GB RAM');
    $env = [
        'OPENHDID_FPM_MAX_CHILDREN' => (string) sizingComposeValue($files, 'services', 'web', '/OPENHDID_FPM_MAX_CHILDREN: (\S+)$/m'),
        'OPENHDID_FPM_MEMORY_LIMIT' => '128M',
    ];
    preg_match('/replicas: (\d+)/', sizingComposeBlock($files[1], 'services', 'worker'), $workers);

    $total = sizingCheckComposeHost($files, $env, $machine['cpus'],
        ['web', 'worker', 'sync-worker', 'scheduler', 'migrate', 'mariadb', 'valkey', 'valkey-sessions'], workers: (int) $workers[1], webServers: 1, hostWorkers: (int) $workers[1]);

    expect($total + SIZING_HOST_OS)->toBeLessThanOrEqual($machine['memory'])
        // Twice the normal peak (about 100 requests/s) with requests of up to 80 ms leaves a process free.
        ->and((int) $env['OPENHDID_FPM_MAX_CHILDREN'])->toBeGreaterThanOrEqual(intdiv(100 * 80, 1000));
});

it('fits every ceiling of a 10k application host, and both hosts into the external database', function (): void {
    $files = [sizingReleaseFile('compose.yaml'), sizingReleaseFile('profiles/10k/compose.yaml')];
    $machine = sizingMachine($files[1], '2 × (4 vCPU, 6 GB RAM, 40 GB lemez)');
    $text = preg_replace('/\n#\s*/', ' ', $files[1]);
    preg_match('/max_connections (\d+)/', $text, $maxConnections);
    $env = [
        'OPENHDID_FPM_MAX_CHILDREN' => (string) sizingComposeValue($files, 'services', 'web', '/OPENHDID_FPM_MAX_CHILDREN: (\S+)$/m'),
        'OPENHDID_FPM_MEMORY_LIMIT' => '128M',
    ];

    // The primary host runs the EMD worker and the scheduler too; the bundled stores do not start.
    $total = sizingCheckComposeHost($files, $env, $machine['cpus'], ['web', 'worker', 'sync-worker', 'scheduler', 'migrate'],
        workers: 2, webServers: 2, maxConnections: (int) $maxConnections[1]);

    expect($total + SIZING_HOST_OS)->toBeLessThanOrEqual($machine['memory'])
        ->and($text)->toContain('maxmemory-policy noeviction', 'maxmemory-policy allkeys-lru', 'REDIS_SESSION_HOST');
});

/**
 * The resources of a Deployment (or Job) of the Kubernetes manifests: the
 * overlay's patch where it sets them, else the base's.
 *
 * @return array{replicas: int, cpu: float, memory: int, limit: int}
 */
function sizingPod(string $name, string $file, ?string $overlay): array
{
    $find = function (string $manifest) use ($name): ?string {
        foreach (preg_split('/^---$/m', $manifest) as $document) {
            if (preg_match('/^  name: '.preg_quote($name, '/').'$/m', $document)) {
                return $document;
            }
        }

        return null;
    };
    $base = (string) $find(sizingReleaseFile("kubernetes/base/{$file}"));
    $patch = $overlay === null ? null : $find(sizingReleaseFile("kubernetes/overlays/{$overlay}/web.yaml"));

    $pick = fn (string $pattern): ?array => $patch !== null && preg_match($pattern, $patch, $match) ? $match : (preg_match($pattern, $base, $match) ? $match : null);
    $requests = $pick('/requests: \{cpu: ("?[\d.]+m?"?), memory: (\w+)\}/');
    $limits = $pick('/limits: \{memory: (\w+)\}/');
    $replicas = $pick('/^  replicas: (\d+)$/m');
    $cpu = trim($requests[1], '"');

    return [
        'replicas' => (int) ($replicas[1] ?? 1),
        'cpu' => str_ends_with($cpu, 'm') ? (int) $cpu / 1000 : (float) $cpu,
        'memory' => sizingMb($requests[2]),
        'limit' => sizingMb($limits[1]),
    ];
}

it('sizes the web pod for its PHP-FPM processes, and every pod with its memory request equal to its limit', function (): void {
    $web = sizingReleaseFile('kubernetes/base/web.yaml');
    preg_match('/OPENHDID_FPM_MAX_CHILDREN, value: "(\d+)"/', $web, $children);
    preg_match('/OPENHDID_FPM_MEMORY_LIMIT, value: (\w+)/', $web, $limit);
    preg_match('/OPENHDID_NGINX_WORKERS, value: "(\d+)"/', $web, $nginx);
    preg_match('/sizeLimit: (\w+)\}/', $web, $tmp);

    $need = (int) $children[1] * (sizingMb($limit[1]) + SIZING_PHP_OVERHEAD) + SIZING_OPCACHE + (int) $nginx[1] * SIZING_NGINX_WORKER + sizingMb($tmp[1]);

    foreach ([null, '10k', '20k'] as $overlay) {
        expect(sizingPod('openhdid-web', 'web.yaml', $overlay)['limit'])->toBeGreaterThanOrEqual($need, 'web '.($overlay ?? 'base'));

        foreach ([['openhdid-web', 'web.yaml'], ['openhdid-worker', 'worker.yaml'], ['openhdid-sync-worker', 'sync-worker.yaml'], ['openhdid-scheduler', 'scheduler.yaml'], ['openhdid-migrate', 'migrate.yaml']] as [$name, $file]) {
            $pod = sizingPod($name, $file, $overlay);
            expect($pod['memory'])->toBe($pod['limit'], "{$name} ".($overlay ?? 'base'));
        }
    }

    $cli = SIZING_CLI_MEMORY_LIMIT + SIZING_PHP_OVERHEAD;
    expect(sizingPod('openhdid-worker', 'worker.yaml', null)['limit'])->toBeGreaterThanOrEqual($cli + 64)
        ->and(sizingPod('openhdid-sync-worker', 'sync-worker.yaml', '20k')['limit'])->toBeGreaterThanOrEqual($cli + 128)
        ->and(sizingPod('openhdid-scheduler', 'scheduler.yaml', null)['limit'])->toBeGreaterThanOrEqual(3 * $cli + 128)
        ->and(sizingPod('openhdid-migrate', 'migrate.yaml', null)['limit'])->toBeGreaterThanOrEqual($cli + 64);
});

it('fits the pods of the 10k and 20k overlays at the HPA maximum onto their node pool with one node lost, and every connection into the database', function (string $overlay, string $nodes, int $hpaMax, int $maxConnections): void {
    $kustomization = preg_replace('/\n#\s*/', ' ', sizingReleaseFile("kubernetes/overlays/{$overlay}/kustomization.yaml"));
    preg_match('/(\d+) × \((\d+) vCPU, (\d+) GB\)/', $kustomization, $pool);
    $web = sizingReleaseFile('kubernetes/base/web.yaml');
    preg_match('/OPENHDID_FPM_MAX_CHILDREN, value: "(\d+)"/', $web, $children);

    expect($pool[0])->toBe($nodes)
        ->and($kustomization)->toContain("max_connections {$maxConnections}", 'noeviction', 'allkeys-lru')
        ->and(sizingReleaseFile("kubernetes/overlays/{$overlay}/web.yaml"))->toContain("maxReplicas: {$hpaMax}");

    $webPod = sizingPod('openhdid-web', 'web.yaml', $overlay);
    $others = array_map(fn (array $pod): array => sizingPod(...$pod), [
        ['openhdid-worker', 'worker.yaml', $overlay], ['openhdid-sync-worker', 'sync-worker.yaml', $overlay], ['openhdid-scheduler', 'scheduler.yaml', $overlay],
    ]);
    $migrate = sizingPod('openhdid-migrate', 'migrate.yaml', null);

    // Memory: the HPA maximum, one extra pod of a rolling update and the migration.
    $memory = ($hpaMax + 1) * $webPod['limit'] + $migrate['limit'] + array_sum(array_map(fn (array $pod): int => $pod['replicas'] * $pod['limit'], $others));
    $usable = ((int) $pool[1] - 1) * ((int) $pool[3] * 1024 - SIZING_NODE_RESERVED);
    expect($memory)->toBeLessThanOrEqual($usable, "memory {$memory} MB of {$usable} MB");

    // CPU requests: the HPA maximum, without a rolling update meanwhile.
    $cpu = $hpaMax * $webPod['cpu'] + array_sum(array_map(fn (array $pod): float => $pod['replicas'] * $pod['cpu'], $others));
    expect($cpu)->toBeLessThanOrEqual(((int) $pool[1] - 1) * ((int) $pool[2] - 0.2));

    $workers = $others[0]['replicas'];
    expect($maxConnections)->toBeGreaterThanOrEqual(($hpaMax + 1) * (int) $children[1] + $workers + 1 + 3 + SIZING_DB_SPARE);
})->with([
    '10k' => ['10k', '3 × (4 vCPU, 8 GB)', 4, 80],
    '20k' => ['20k', '3 × (4 vCPU, 12 GB)', 6, 120],
]);
