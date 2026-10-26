<?php

use App\Support\RateLimitExemptions;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Symfony\Component\HttpFoundation\IpUtils;

/*
 * The OpenHDID container renders its nginx.conf at start from the environment
 * (docker/runtime.php nginx-config). These tests render it the same way and
 * check what reaches PHP and what nginx refuses on its own.
 */

/**
 * The runtime helper: under deploy/openhdid/repo in this repository, at the
 * root of the published OpenHDID tree.
 */
function openhdidRuntimeHelper(): string
{
    foreach (['deploy/openhdid/repo/docker/runtime.php', 'docker/runtime.php'] as $path) {
        if (is_file(base_path($path))) {
            return base_path($path);
        }
    }

    test()->markTestSkipped('The container runtime helper is not part of this tree.');
}

/**
 * The Kubernetes manifests, wherever the tree keeps them.
 */
function openhdidKubernetesManifests(): string
{
    foreach (['deploy/openhdid/repo/kubernetes', 'kubernetes'] as $path) {
        if (is_dir(base_path($path))) {
            return base_path($path);
        }
    }

    test()->markTestSkipped('The Kubernetes manifests are not part of this tree.');
}

/**
 * nginx.conf as the web container renders it: behind a TLS-terminating load
 * balancer (OPENHDID_TLS=off) whose pod network is trusted, unless overridden.
 *
 * @param  array<string, string>  $env
 */
function renderContainerNginx(array $env = []): ProcessResult
{
    return Process::env([
        'APP_URL' => 'https://hdid.example.org',
        'OPENHDID_TLS' => 'off',
        'TRUSTED_PROXIES' => '10.244.0.0/16',
        'OPENHDID_EXTRA_HOSTS' => '',
        'OPENHDID_MONITORING_ALLOW' => '',
        'OPENHDID_NGINX_WORKERS' => '',
        'OPENHDID_NGINX_RATE_LIMIT' => '',
        'OPENHDID_NGINX_RATE_BURST' => '',
        'OPENHDID_NGINX_CONN_LIMIT' => '',
        'OPENHDID_NGINX_CLIENT_TIMEOUT' => '',
        'OPENHDID_HTTP_REDIRECT' => '',
        'HDID_RATE_LIMIT_EXEMPT_NETWORKS' => '',
        ...$env,
    ])->run([PHP_BINARY, openhdidRuntimeHelper(), 'nginx-config']);
}

/**
 * The first "location <match>… { … }" block of the rendered configuration.
 */
function nginxLocationBlock(string $config, string $match): string
{
    $start = strpos($config, 'location '.$match);
    expect($start)->not->toBeFalse("no location {$match} in the configuration");

    $end = strpos($config, "\n        }", $start);

    return substr($config, $start, $end - $start);
}

/**
 * What PHP-FPM receives for a request that nginx hands to index.php: the
 * fastcgi_params defaults, every request header as HTTP_* unless the location
 * sets a parameter of that name, then the location's own parameters with the
 * nginx variables of the request.
 *
 * @param  array<string, string>  $headers
 * @param  array<string, string>  $variables  nginx variables after realip, e.g. remote_addr, host
 * @return array<string, string>
 */
function phpServerFromNginx(string $config, array $headers, array $variables): array
{
    preg_match_all('/^\s*fastcgi_param\s+(\S+)\s+("[^"]*"|[^\s;]+)\s*(if_not_empty)?;/m', nginxLocationBlock($config, '= /index.php'), $params, PREG_SET_ORDER);
    $set = array_column($params, 1);

    $server = [
        'REMOTE_ADDR' => $variables['remote_addr'],
        'SERVER_PORT' => '8080',
        'SERVER_NAME' => 'hdid.example.org',
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/v1/client/auth/magic-link',
        'SCRIPT_NAME' => '/index.php',
        'SCRIPT_FILENAME' => '/app/public/index.php',
    ];
    foreach ($headers as $name => $value) {
        $key = 'HTTP_'.strtoupper(str_replace('-', '_', $name));
        if (! in_array($key, $set, true)) {
            $server[$key] = $value;
        }
    }
    foreach ($params as [, $name, $value]) {
        $value = preg_replace_callback('/\$([a-z_]+)/', fn (array $match): string => $variables[$match[1]] ?? $match[0], trim($value, '"'));
        // An empty parameter does not show up in PHP at all (as PHP-FPM behind the image's nginx shows).
        if ($value === '') {
            unset($server[$name]);
        } else {
            $server[$name] = $value;
        }
    }

    return $server;
}

dataset('requests reaching the web pod', [
    'a pod of the trusted network, straight to the service, with forged forwarding headers' => [
        ['Host' => 'hdid.example.org', 'X-Forwarded-Host' => 'attacker.example', 'X-Forwarded-Proto' => 'http', 'X-Forwarded-Port' => '8443', 'X-Forwarded-Prefix' => '@attacker.example'],
        '10.244.3.3',
    ],
    'the ingress controller forwarding an internet client' => [
        ['Host' => 'hdid.example.org', 'X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Host' => 'hdid.example.org', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'],
        '203.0.113.9',
    ],
]);

it('hands PHP only the forwarding values nginx established itself, whatever a trusted address sends', function (array $headers, string $clientAfterRealIp): void {
    $config = renderContainerNginx()->throw()->output();
    $server = phpServerFromNginx($config, $headers, ['remote_addr' => $clientAfterRealIp, 'host' => 'hdid.example.org', 'realpath_root' => '/app/public']);

    // The application trusts the same TRUSTED_PROXIES list as nginx's realip.
    config()->set('trustedproxy.proxies', '10.244.0.0/16');
    $trusted = [Request::getTrustedProxies(), Request::getTrustedHeaderSet()];

    try {
        $request = (new TrustProxies)->handle(new Request([], [], [], [], [], $server), fn (Request $request): Request => $request);

        expect($request->getHost())->toBe('hdid.example.org')
            ->and($request->isSecure())->toBeTrue()
            ->and($request->getPort())->toBe(443)
            ->and($request->getBaseUrl())->toBe('')
            ->and($request->getSchemeAndHttpHost())->toBe('https://hdid.example.org')
            ->and($request->getClientIp())->toBe($clientAfterRealIp);
    } finally {
        Request::setTrustedProxies(...$trusted);
    }
})->with('requests reaching the web pod');

it('forwards the public port of APP_URL, not one a client names', function (): void {
    $config = renderContainerNginx(['APP_URL' => 'https://hdid.example.org:8443'])->throw()->output();
    $server = phpServerFromNginx($config, ['Host' => 'hdid.example.org:8443', 'X-Forwarded-Port' => '9999'], ['remote_addr' => '10.244.3.3', 'host' => 'hdid.example.org']);

    expect($server['HTTP_X_FORWARDED_PORT'])->toBe('8443');
});

it('counts loopback for the monitoring endpoints by the connection itself, not by a forwarded address', function (): void {
    $config = renderContainerNginx(['OPENHDID_MONITORING_ALLOW' => '192.0.2.0/24'])->throw()->output();

    preg_match_all('/geo (\$realip_remote_addr )?\$\w+ \{(.*?)\}/s', $config, $geos, PREG_SET_ORDER);
    $byConnection = implode('', array_map(fn (array $geo): string => $geo[2], array_filter($geos, fn (array $geo): bool => $geo[1] !== '')));
    $byClient = implode('', array_map(fn (array $geo): string => $geo[2], array_filter($geos, fn (array $geo): bool => $geo[1] === '')));

    // A trusted proxy sending "X-Forwarded-For: 127.0.0.1" makes realip report
    // 127.0.0.1 as the client; only the real peer may count as the container itself.
    expect($byConnection)->toContain('127.0.0.1 1;')->toContain('::1 1;')
        ->and($byClient)->toContain('192.0.2.0/24 1;')->not->toContain('127.0.0.1')->not->toContain('::1');
});

it('limits the requests per client address to the application, not to the static files', function (): void {
    $config = renderContainerNginx()->throw()->output();

    expect($config)->toContain('limit_req_zone $openhdid_limit_key zone=openhdid:10m rate=20r/s;')
        ->toContain('limit_req_status 429;')
        ->and(nginxLocationBlock($config, '= /index.php'))->toContain('limit_req zone=openhdid burst=100 nodelay;')
        ->and(nginxLocationBlock($config, '^~ /build/'))->not->toContain('limit_req')
        ->and(nginxLocationBlock($config, '~* \\.(css|js'))->not->toContain('limit_req');

    // The refusal says why, in both languages, and when to come back.
    $busy = nginxLocationBlock($config, '= /__openhdid_busy');
    expect($config)->toContain('error_page 429 /__openhdid_busy;')
        ->and($busy)->toContain('internal;')->toContain('Retry-After')->toContain('return 429')
        ->toContain('Too many requests')->toContain('Túl sok kérés');
});

it('takes the request limit and burst from the environment, 0 switching the limit off', function (): void {
    $custom = renderContainerNginx(['OPENHDID_NGINX_RATE_LIMIT' => '10', 'OPENHDID_NGINX_RATE_BURST' => '40'])->throw();

    expect($custom->output())->toContain('rate=10r/s;')
        ->and(nginxLocationBlock($custom->output(), '= /index.php'))->toContain('limit_req zone=openhdid burst=40 nodelay;')
        ->and($custom->errorOutput())->toContain('10 requests/s')->toContain('OPENHDID_NGINX_RATE_LIMIT');

    $off = renderContainerNginx(['OPENHDID_NGINX_RATE_LIMIT' => '0'])->throw();

    expect($off->output())->not->toContain('limit_req')
        ->and($off->errorOutput())->toContain('OPENHDID_NGINX_RATE_LIMIT=0');
});

it('leaves the burst parameter out at a burst of 0, which nginx would refuse', function (): void {
    $config = renderContainerNginx(['OPENHDID_NGINX_RATE_LIMIT' => '1', 'OPENHDID_NGINX_RATE_BURST' => '0'])->throw()->output();

    expect(nginxLocationBlock($config, '= /index.php'))->toContain('limit_req zone=openhdid;')
        ->not->toContain('burst=');
});

it('refuses to start on a request limit that is not a whole number', function (string $key, string $value): void {
    $result = renderContainerNginx([$key => $value]);

    expect($result->exitCode())->toBe(64)
        ->and($result->errorOutput())->toContain($key)
        ->and($result->output())->toBe('');
})->with([
    ['OPENHDID_NGINX_RATE_LIMIT', 'fifty'],
    ['OPENHDID_NGINX_RATE_LIMIT', '-5'],
    ['OPENHDID_NGINX_RATE_BURST', '2.5'],
]);

it('lets only the ingress controller reach the web pods on Kubernetes', function (): void {
    $manifests = openhdidKubernetesManifests();
    $policy = (string) @file_get_contents($manifests.'/base/networkpolicy.yaml');

    expect(file_get_contents($manifests.'/base/kustomization.yaml'))->toContain('  - networkpolicy.yaml')
        ->and($policy)->toContain('kind: NetworkPolicy')
        ->toContain('app.kubernetes.io/component: web')
        ->toContain('policyTypes: [Ingress]')
        ->toContain('kubernetes.io/metadata.name: ingress-nginx')
        ->toContain('port: 8080');
});

/**
 * An IPv6 address as nginx writes $remote_addr (ngx_inet6_ntop): lower-case
 * groups without leading zeros, the first longest run of two or more zero
 * groups written as "::", a single zero group kept (RFC 5952).
 *
 * @param  list<int>  $groups  eight 16-bit groups
 */
function nginxIpv6Text(array $groups): string
{
    [$start, $length] = [-1, 0];
    for ($i = 0; $i < 8; $i++) {
        $run = 0;
        while ($i + $run < 8 && $groups[$i + $run] === 0) {
            $run++;
        }
        if ($run > $length) {
            [$start, $length] = [$i, $run];
        }
    }

    $hex = array_map('dechex', $groups);
    if ($length < 2) {
        return implode(':', $hex);
    }

    return implode(':', array_slice($hex, 0, $start)).'::'.implode(':', array_slice($hex, $start + $length));
}

/**
 * The key nginx's "map $remote_addr $openhdid_client" gives an address: the
 * first matching regex in order, its captures put into the value.
 */
function nginxClientKey(string $config, string $address): string
{
    preg_match('/map \$remote_addr \$openhdid_client \{(.*?)\n    \}/s', $config, $block);
    preg_match_all("/^\s*'~(.+)' (\S+);$/m", $block[1] ?? '', $rules, PREG_SET_ORDER);
    expect($rules)->not->toBeEmpty();

    foreach ($rules as [, $regex, $value]) {
        if (preg_match('/'.str_replace('/', '\/', $regex).'/', $address, $match)) {
            return preg_replace_callback('/\$([a-z]+)/', fn (array $name): string => $match[$name[1]], $value);
        }
    }

    return $address;
}

it('keys the limits per client to the /64 network of an IPv6 address, and to the whole IPv4 address', function (): void {
    $config = renderContainerNginx()->throw()->output();

    expect($config)->toContain('limit_req_zone $openhdid_limit_key ', 'limit_conn_zone $openhdid_limit_key ')
        ->and(nginxClientKey($config, '203.0.113.9'))->toBe('203.0.113.9')
        ->and(nginxClientKey($config, '198.51.100.10'))->not->toBe(nginxClientKey($config, '198.51.100.11'));

    mt_srand(64);
    // Networks with zero groups in every position, hosts from all zeros to none: every
    // shape of "::" nginx may write. Each host of a /64 gets the key of the network.
    $networks = [[0x2001, 0xDB8, 0, 0], [0x2001, 0, 0, 0], [0x2001, 0, 0, 7], [0x2001, 0xDB8, 0, 7], [0x2A01, 0x36D, 0x110, 0x4D2], [0x2001, 0, 0x5, 0]];
    $hosts = [[0, 0, 0, 0], [0, 0, 0, 1], [1, 2, 3, 4], [0, 0, 1, 0], [0xFFFF, 0, 0, 0xFFFF], [1, 0, 0, 0]];
    for ($i = 0; $i < 40; $i++) {
        $hosts[] = array_map(fn (): int => mt_rand(0, 3) === 0 ? 0 : mt_rand(1, 0xFFFF), range(1, 4));
    }

    foreach ($networks as $network) {
        $expected = implode(':', array_map('dechex', $network));

        foreach ($hosts as $host) {
            $address = nginxIpv6Text([...$network, ...$host]);
            expect(inet_pton($address))->toBe(inet_pton(implode(':', array_map('dechex', [...$network, ...$host]))), $address)
                ->and(nginxClientKey($config, $address))->toBe($expected, $address);
        }
    }

    expect(nginxClientKey($config, '2001:db8:0:1::1'))->not->toBe(nginxClientKey($config, '2001:db8:0:2::1'));
});

it('limits the requests one client address has in the application at once, 0 switching it off', function (): void {
    $config = renderContainerNginx()->throw()->output();

    expect($config)->toContain('limit_conn_zone $openhdid_limit_key zone=openhdid_conn:10m;', 'limit_conn_status 429;')
        ->and(nginxLocationBlock($config, '= /index.php'))->toContain('limit_conn openhdid_conn 16;')
        ->and(nginxLocationBlock($config, '~ ^/livewire-[a-f0-9]+/upload-file'))->toContain('limit_conn openhdid_conn 16;', 'limit_req zone=openhdid')
        ->and(nginxLocationBlock($config, '^~ /build/'))->not->toContain('limit_conn');

    $custom = renderContainerNginx(['OPENHDID_NGINX_CONN_LIMIT' => '64'])->throw();
    expect(nginxLocationBlock($custom->output(), '= /index.php'))->toContain('limit_conn openhdid_conn 64;')
        ->and($custom->errorOutput())->toContain('OPENHDID_NGINX_CONN_LIMIT');

    $off = renderContainerNginx(['OPENHDID_NGINX_CONN_LIMIT' => '0'])->throw();
    expect($off->output())->not->toContain('limit_conn')
        ->and($off->errorOutput())->toContain('OPENHDID_NGINX_CONN_LIMIT=0');

    expect(renderContainerNginx(['OPENHDID_NGINX_CONN_LIMIT' => 'many'])->exitCode())->toBe(64);
});

it('gives a slow client little time and keeps large request bodies to the file upload', function (): void {
    $config = renderContainerNginx()->throw()->output();
    $server = substr($config, (int) strpos($config, 'server_name hdid.example.org;'));

    expect($server)->toContain('client_max_body_size 4m;', 'client_header_timeout 10s;', 'client_body_timeout 15s;', 'send_timeout 15s;', 'keepalive_timeout 15s;', 'http2_max_concurrent_streams 32;')
        ->and(nginxLocationBlock($config, '~ ^/livewire-[a-f0-9]+/upload-file'))->toContain('client_max_body_size 11m;')
        ->and(nginxLocationBlock($config, '= /index.php'))->not->toContain('client_max_body_size')
        ->and(nginxLocationBlock($config, '= /index.php'))->toContain('fastcgi_max_temp_file_size 16m;');

    expect(renderContainerNginx(['OPENHDID_NGINX_CLIENT_TIMEOUT' => '30'])->throw()->output())->toContain('client_body_timeout 30s;', 'send_timeout 30s;', 'keepalive_timeout 30s;');

    foreach (['4', '301', 'slow'] as $value) {
        $refused = renderContainerNginx(['OPENHDID_NGINX_CLIENT_TIMEOUT' => $value]);
        expect($refused->exitCode())->toBe(64, $value)
            ->and($refused->errorOutput())->toContain('OPENHDID_NGINX_CLIENT_TIMEOUT');
    }
});

it('refuses at once with 503 when every PHP-FPM process is busy, and keeps the liveness probe off the pool', function (): void {
    $config = renderContainerNginx()->throw()->output();
    $fpm = (string) file_get_contents(dirname(openhdidRuntimeHelper()).'/php-fpm.conf');
    $dockerfile = (string) file_get_contents(dirname(openhdidRuntimeHelper(), 2).'/Dockerfile');

    expect($config)->toContain('error_page 502 503 =503 /__openhdid_unavailable;')
        ->and(nginxLocationBlock($config, '= /__openhdid_unavailable'))->toContain('internal;', 'Retry-After', 'return 503', 'busy')
        ->and($config)->toContain('location = /alive { return 204; }')
        ->and($fpm)->toContain('listen.backlog = ${OPENHDID_FPM_BACKLOG}', 'php_admin_value[memory_limit] = ${OPENHDID_FPM_MEMORY_LIMIT}')
        ->and($dockerfile)->toContain('OPENHDID_FPM_BACKLOG=64', 'OPENHDID_FPM_MEMORY_LIMIT=128M');

    // PHP-FPM frees a process soon after nginx gave up on its request.
    preg_match('/fastcgi_read_timeout (\d+)s;/', $config, $read);
    preg_match('/^request_terminate_timeout = (\d+)s$/m', $fpm, $terminate);
    expect((int) $terminate[1])->toBeGreaterThanOrEqual((int) $read[1])->toBeLessThanOrEqual((int) $read[1] + 10);

    $web = (string) file_get_contents(openhdidKubernetesManifests().'/base/web.yaml');
    preg_match('/livenessProbe:\n\s+exec:\n\s+command: \[([^\]]+)\]/', $web, $liveness);
    expect($liveness[1] ?? '')->toBe('/usr/local/bin/openhdid, healthcheck, live');
});

/**
 * docker/runtime.php check-env as the web role starts it, with a complete
 * production environment unless overridden.
 *
 * @param  array<string, string>  $env
 */
function runContainerCheckEnv(array $env = []): ProcessResult
{
    return Process::env([
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'APP_URL' => 'https://hdid.example.org',
        'DB_CONNECTION' => 'mariadb',
        'DB_HOST' => 'mariadb',
        'DB_DATABASE' => 'openhdid',
        'DB_USERNAME' => 'openhdid',
        'DB_PASSWORD' => 'secret',
        'DB_SOCKET' => '',
        'SESSION_DRIVER' => 'redis',
        'CACHE_STORE' => 'redis',
        'QUEUE_CONNECTION' => 'redis',
        'REDIS_PASSWORD' => 'secret',
        'REDIS_SESSION_HOST' => 'valkey-sessions',
        'SESSION_SECURE_COOKIE' => 'true',
        'TRUSTED_PROXIES' => '',
        'HDID_HTTP_PROXY' => '',
        'OPENHDID_TLS' => 'on',
        'MAIL_MAILER' => 'smtp',
        'MAIL_REQUIRE_TLS' => 'true',
        'SMS_DRIVER' => 'ozeki',
        'OPENHDID_FPM_MEMORY_LIMIT' => '128M',
        'OPENHDID_FPM_MAX_CHILDREN' => '8',
        'OPENHDID_FPM_BACKLOG' => '64',
        'OPENHDID_NGINX_WORKERS' => '2',
        'HDID_RATE_LIMIT_EXEMPT_NETWORKS' => '',
        ...$env,
    ])->run([PHP_BINARY, '-d', 'max_execution_time=300', openhdidRuntimeHelper(), 'check-env', 'web']);
}

it('starts in production with the sessions on the main Valkey, and says so', function (): void {
    $own = runContainerCheckEnv();
    expect($own->exitCode())->toBe(0, $own->errorOutput())
        ->and($own->errorOutput())->not->toContain('REDIS_SESSION_HOST');

    // An installation from before the session Valkey keeps starting; the status page shows it too.
    $shared = runContainerCheckEnv(['REDIS_SESSION_HOST' => '']);
    expect($shared->exitCode())->toBe(0, $shared->errorOutput())
        ->and($shared->errorOutput())->toContain('warning: the sessions share the main Valkey', 'REDIS_SESSION_HOST', 'allkeys-lru')
        ->and($shared->errorOutput())->not->toContain('ERROR');
});

it('refuses to start on PHP-FPM pool values that are not a size or a count', function (string $key, string $value): void {
    $result = runContainerCheckEnv([$key => $value]);

    expect($result->exitCode())->toBe(64)
        ->and($result->errorOutput())->toContain('ERROR: '.$key);
})->with([
    ['OPENHDID_FPM_MEMORY_LIMIT', '128'],
    ['OPENHDID_FPM_MEMORY_LIMIT', '32M'],
    ['OPENHDID_FPM_MEMORY_LIMIT', '-1'],
    ['OPENHDID_FPM_MAX_CHILDREN', '0'],
    ['OPENHDID_FPM_MAX_CHILDREN', 'twelve'],
    ['OPENHDID_FPM_BACKLOG', '0'],
]);

/**
 * The limit key nginx gives a client address: the "geo $openhdid_rate_exempt"
 * block (the longest matching network wins, as in nginx) decides whether the
 * "map $openhdid_rate_exempt $openhdid_limit_key" hands out the empty key.
 */
function nginxLimitKey(string $config, string $address): string
{
    preg_match('/geo \$openhdid_rate_exempt \{(.*?)\n    \}/s', $config, $geo);
    preg_match('/map \$openhdid_rate_exempt \$openhdid_limit_key \{(.*?)\n    \}/s', $config, $map);
    expect($geo)->not->toBeEmpty()->and($map[1] ?? '')->toContain('1 "";', 'default $openhdid_client;');

    preg_match_all('/^\s*(\S+) 1;$/m', $geo[1], $networks);
    $exempt = $networks[1] !== [] && IpUtils::checkIp($address, $networks[1]);

    return $exempt ? '' : nginxClientKey($config, $address);
}

it('gives the networks of HDID_RATE_LIMIT_EXEMPT_NETWORKS no request or connection limit, every other address its own', function (): void {
    $result = renderContainerNginx(['HDID_RATE_LIMIT_EXEMPT_NETWORKS' => '192.0.2.0/24, 2001:db8::/32 198.51.100.7'])->throw();
    $config = $result->output();

    expect($config)->toContain('limit_req_zone $openhdid_limit_key ', 'limit_conn_zone $openhdid_limit_key ')
        ->and($config)->toContain("        192.0.2.0/24 1;\n", "        2001:db8::/32 1;\n", "        198.51.100.7 1;\n")
        ->and(nginxLimitKey($config, '192.0.2.200'))->toBe('')
        ->and(nginxLimitKey($config, '198.51.100.7'))->toBe('')
        ->and(nginxLimitKey($config, '2001:db8:5::1'))->toBe('')
        ->and(nginxLimitKey($config, '198.51.100.8'))->toBe('198.51.100.8')
        ->and(nginxLimitKey($config, '203.0.113.9'))->toBe('203.0.113.9')
        ->and(nginxLimitKey($config, '2001:db9:1:2::1'))->toBe('2001:db9:1:2')
        ->and($result->errorOutput())->toContain('HDID_RATE_LIMIT_EXEMPT_NETWORKS', '192.0.2.0/24');

    // Without the list nobody is exempt; the geo block stays empty.
    $plain = renderContainerNginx()->throw()->output();
    expect(nginxLimitKey($plain, '192.0.2.200'))->toBe('192.0.2.200');
});

it('refuses to start on an exempt network that is invalid or too wide, saying which', function (string $entry): void {
    $render = renderContainerNginx(['HDID_RATE_LIMIT_EXEMPT_NETWORKS' => '10.0.0.0/8 '.$entry]);
    $check = runContainerCheckEnv(['HDID_RATE_LIMIT_EXEMPT_NETWORKS' => '10.0.0.0/8 '.$entry]);

    expect($render->exitCode())->toBe(64)
        ->and($render->output())->toBe('')
        ->and($render->errorOutput())->toContain('HDID_RATE_LIMIT_EXEMPT_NETWORKS', $entry)
        ->and($check->exitCode())->toBe(64)
        ->and($check->errorOutput())->toContain('ERROR', 'HDID_RATE_LIMIT_EXEMPT_NETWORKS', $entry);
})->with(['*', '0.0.0.0/0', '::/0', '10.0.0.0/7', '2001::/31', 'example.org', '10.0.0.0/33']);

it('starts with a valid exempt list, and the container and the application accept the same entries', function (): void {
    $networks = '10.0.0.0/8 192.0.2.7 2001:db8::/32 fd00::/48';

    expect(runContainerCheckEnv(['HDID_RATE_LIMIT_EXEMPT_NETWORKS' => $networks])->exitCode())->toBe(0);

    config()->set('hdid.rate_limit_exempt_networks', $networks);
    expect(app(RateLimitExemptions::class)->invalid())->toBe([]);

    foreach (['10.0.0.0/7', '2001::/31', '*'] as $entry) {
        expect(RateLimitExemptions::acceptable($entry))->toBeFalse($entry);
    }
});
