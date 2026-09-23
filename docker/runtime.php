<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * OpenHDID container runtime helper, called by /usr/local/bin/openhdid.
 *
 *   check-env <role>   validates the environment before anything starts
 *   nginx-config       prints nginx.conf for the current environment (and checks the TLS files)
 *   tls-check          validates the mounted certificate and key and prints a summary
 *   wait-db            waits until the database accepts connections
 *   demo-data          loads the demo data once when OPENHDID_DEMO_DATA=true (never in production)
 *   healthcheck        image HEALTHCHECK: the web role answers /up on the internal port
 *
 * Plain PHP with the openssl extension; only wait-db and demo-data boot Laravel.
 */

const RUNTIME = '/tmp/openhdid';
const APP_DIR = '/app';
const DEFAULT_CERT = '/etc/openhdid/tls/fullchain.pem';
const DEFAULT_KEY = '/etc/openhdid/tls/privkey.pem';
const EXPIRY_WARNING_DAYS = 30;

function env_value(string $name, ?string $default = null): ?string
{
    $value = getenv($name);

    return $value === false || trim($value) === '' ? $default : trim($value);
}

function env_bool(string $name, bool $default): bool
{
    $value = env_value($name);

    return $value === null ? $default : (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default);
}

function say(string $message): void
{
    fwrite(STDERR, gmdate('Y-m-d\TH:i:s\Z').' openhdid: '.$message.PHP_EOL);
}

/**
 * @param  list<string>  $errors
 */
function stop_on(array $errors): void
{
    foreach ($errors as $error) {
        say('ERROR: '.$error);
    }
    if ($errors !== []) {
        exit(64);
    }
}

/**
 * @return list<string>
 */
function env_list(string $name): array
{
    return array_values(array_filter(preg_split('/[\s,]+/', (string) env_value($name, '')) ?: [], fn (string $item): bool => $item !== ''));
}

function is_cidr(string $value): bool
{
    [$ip, $prefix] = array_pad(explode('/', $value, 2), 2, null);
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    $max = str_contains($ip, ':') ? 128 : 32;

    return $prefix === null || (ctype_digit($prefix) && (int) $prefix <= $max);
}

/**
 * @return array{host: string, origin: string}
 */
function app_url(): array
{
    $parts = parse_url((string) env_value('APP_URL', ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $port = isset($parts['port']) && (int) $parts['port'] !== 443 ? ':'.$parts['port'] : '';

    return ['host' => $host, 'origin' => 'https://'.$host.$port];
}

/**
 * @return list<string>
 */
function server_names(): array
{
    $names = array_values(array_unique(array_merge([app_url()['host']], array_map('strtolower', env_list('OPENHDID_EXTRA_HOSTS')))));
    $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
    foreach ($names as $name) {
        if (! preg_match('/^(\*\.)?'.$label.'(\.'.$label.')*$/', $name) && filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            stop_on(["invalid host name '{$name}' in APP_URL or OPENHDID_EXTRA_HOSTS"]);
        }
    }

    return $names;
}

function check_env(string $role): void
{
    $errors = [];
    $warnings = [];
    $production = env_value('APP_ENV', 'production') === 'production';

    $key = (string) env_value('APP_KEY', '');
    if ($key === '') {
        $errors[] = 'APP_KEY is not set. Generate it once with "docker compose run --rm --no-deps web key", put it into .env and keep a protected copy: losing it makes encrypted data unreadable.';
    } elseif (str_starts_with($key, 'base64:')) {
        $raw = base64_decode(substr($key, 7), true);
        if ($raw === false || strlen($raw) !== 32) {
            $errors[] = 'APP_KEY must hold 32 bytes (base64:...); generate a new one only for a new installation.';
        }
    } elseif (strlen($key) !== 32) {
        $errors[] = 'APP_KEY must be 32 characters or base64:<32 bytes>.';
    }

    $url = parse_url((string) env_value('APP_URL', ''));
    if (($url['scheme'] ?? '') !== 'https' || empty($url['host'])) {
        $errors[] = 'APP_URL must be the public https:// address of the site, for example https://hdid.example.org';
    }

    if ($production && env_bool('APP_DEBUG', false)) {
        $errors[] = 'APP_DEBUG=true is not allowed with APP_ENV=production: error pages would show internals.';
    }

    $connection = env_value('DB_CONNECTION');
    if (! in_array($connection, ['mariadb', 'mysql'], true)) {
        $errors[] = 'DB_CONNECTION must be mariadb in the container (got '.($connection ?? 'nothing').').';
    } else {
        if (env_value('DB_HOST') === null && env_value('DB_SOCKET') === null) {
            $errors[] = 'DB_HOST is not set (use "mariadb" for the bundled database service).';
        }
        foreach (['DB_DATABASE', 'DB_USERNAME'] as $name) {
            if (env_value($name) === null) {
                $errors[] = "{$name} is not set.";
            }
        }
        if (env_value('DB_PASSWORD') === null) {
            $warnings[] = 'DB_PASSWORD is empty.';
        }
    }

    if ($production && in_array('*', env_list('TRUSTED_PROXIES'), true)) {
        $errors[] = 'TRUSTED_PROXIES=* is not allowed in production; list the load balancer addresses.';
    }
    if ($production && ! env_bool('SESSION_SECURE_COOKIE', true)) {
        $errors[] = 'SESSION_SECURE_COOKIE=false is not allowed in production.';
    }

    $tls = strtolower((string) env_value('OPENHDID_TLS', 'on'));
    if (! in_array($tls, ['on', 'off', 'selfsigned'], true)) {
        $errors[] = "OPENHDID_TLS must be on, off or selfsigned (got {$tls}).";
    }
    if ($production && $tls === 'selfsigned') {
        $warnings[] = 'OPENHDID_TLS=selfsigned is for trials only; browsers and partners will reject the certificate.';
    }
    if ($production && in_array(env_value('MAIL_MAILER', 'log'), ['log', 'array'], true)) {
        $warnings[] = 'MAIL_MAILER writes e-mails to the log only; no e-mail leaves the server.';
    }
    if ($production && env_value('SMS_DRIVER', 'log') === 'log') {
        $warnings[] = 'SMS_DRIVER=log writes SMS to the log only; clients receive nothing.';
    }
    if (in_array(env_value('QUEUE_CONNECTION', 'database'), ['sync', 'null'], true)) {
        $warnings[] = 'QUEUE_CONNECTION=sync: e-mails and SMS are sent inside web requests and the panel cannot start an EMD sync.';
    }

    foreach ($warnings as $warning) {
        say('warning: '.$warning);
    }
    stop_on($errors);
}

/**
 * @param  list<string>  $names
 * @return array{errors: list<string>, warnings: list<string>, summary: list<string>}
 */
function inspect_tls(string $certPath, string $keyPath, array $names): array
{
    $errors = [];
    $warnings = [];
    $summary = [];

    if (! is_readable($certPath)) {
        return ['errors' => ["certificate file {$certPath} is missing or not readable by uid ".posix_getuid().'; mount the full chain there (fullchain.pem).'], 'warnings' => [], 'summary' => []];
    }
    if (! is_readable($keyPath)) {
        return ['errors' => ["private key {$keyPath} is missing or not readable by uid ".posix_getuid().'; mount it there (privkey.pem, chown 82, chmod 400).'], 'warnings' => [], 'summary' => []];
    }

    preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', (string) file_get_contents($certPath), $matches);
    $chain = $matches[0];
    $leaf = $chain === [] ? false : openssl_x509_read($chain[0]);
    if ($leaf === false) {
        return ['errors' => ["{$certPath} holds no PEM certificate."], 'warnings' => [], 'summary' => []];
    }
    $key = openssl_pkey_get_private((string) file_get_contents($keyPath));
    if ($key === false) {
        return ['errors' => ["{$keyPath} is not a readable PEM private key (password-protected keys are not supported)."], 'warnings' => [], 'summary' => []];
    }
    if (! openssl_x509_check_private_key($leaf, $key)) {
        $errors[] = 'the private key does not belong to the certificate.';
    }

    $info = openssl_x509_parse($leaf) ?: [];
    $now = time();
    $notAfter = (int) ($info['validTo_time_t'] ?? 0);
    $notBefore = (int) ($info['validFrom_time_t'] ?? 0);
    $daysLeft = intdiv($notAfter - $now, 86400);
    if ($notAfter <= $now) {
        $errors[] = 'the certificate expired on '.gmdate('Y-m-d', $notAfter).'.';
    } elseif ($daysLeft < EXPIRY_WARNING_DAYS) {
        $warnings[] = "the certificate expires in {$daysLeft} days (".gmdate('Y-m-d', $notAfter).'); renew it, then run "openhdid reload".';
    }
    if ($notBefore > $now) {
        $errors[] = 'the certificate is not valid before '.gmdate('Y-m-d H:i', $notBefore).' UTC; check the server clock.';
    }

    $san = array_map('trim', explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')));
    $dnsNames = array_map(fn (string $entry): string => strtolower(substr($entry, 4)), array_values(array_filter($san, fn (string $entry): bool => str_starts_with($entry, 'DNS:'))));
    $ipNames = array_map(fn (string $entry): string => trim(substr($entry, 11)), array_values(array_filter($san, fn (string $entry): bool => str_starts_with($entry, 'IP Address:'))));
    foreach ($names as $name) {
        $covered = in_array($name, $ipNames, true) || in_array($name, $dnsNames, true)
            || (($dot = strpos($name, '.')) !== false && in_array('*'.substr($name, $dot), $dnsNames, true));
        if (! $covered) {
            $errors[] = "the certificate does not cover {$name} (subjectAltName: ".implode(', ', $san).').';
        }
    }

    $details = openssl_pkey_get_details($key) ?: [];
    $keyInfo = 'unknown key type';
    if (($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA) {
        $keyInfo = 'RSA '.$details['bits'];
        if ($details['bits'] < 2048) {
            $errors[] = 'RSA keys shorter than 2048 bits are not accepted.';
        }
    } elseif (($details['type'] ?? null) === OPENSSL_KEYTYPE_EC) {
        $keyInfo = 'ECDSA '.($details['ec']['curve_name'] ?? '');
    }

    $issuer = $info['issuer'] ?? [];
    $subject = $info['subject'] ?? [];
    if (count($chain) === 1 && $issuer !== $subject) {
        $warnings[] = 'the certificate file holds no intermediate certificate; use the full chain or some clients will reject the site.';
    }
    if ((fileperms($keyPath) & 0o004) !== 0) {
        $warnings[] = "{$keyPath} is readable by every user of the host; restrict it (chmod 400).";
    }

    $summary[] = 'subject: '.($subject['CN'] ?? '-').'; issuer: '.($issuer['CN'] ?? $issuer['O'] ?? '-');
    $summary[] = 'valid until '.gmdate('Y-m-d H:i', $notAfter).' UTC ('.$daysLeft.' days); '.$keyInfo.'; chain: '.count($chain).' certificate(s)';
    $summary[] = 'names: '.implode(', ', array_merge($dnsNames, $ipNames));

    return ['errors' => $errors, 'warnings' => $warnings, 'summary' => $summary];
}

/**
 * A throwaway certificate for trials (OPENHDID_TLS=selfsigned), kept in memory-backed /tmp.
 *
 * @param  list<string>  $names
 * @return array{0: string, 1: string}
 */
function self_signed(array $names): array
{
    $cert = RUNTIME.'/tls/selfsigned.pem';
    $keyFile = RUNTIME.'/tls/selfsigned.key';
    if (is_readable($cert) && is_readable($keyFile)) {
        return [$cert, $keyFile];
    }

    $alt = array_map(fn (string $name): string => filter_var($name, FILTER_VALIDATE_IP) ? 'IP:'.$name : 'DNS:'.$name, array_unique(array_merge($names, ['localhost'])));
    $config = RUNTIME.'/tls/openssl.cnf';
    file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[ext]\nsubjectAltName = ".implode(',', $alt)."\nbasicConstraints = critical,CA:FALSE\nkeyUsage = critical,digitalSignature\nextendedKeyUsage = serverAuth\n");
    $options = ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'ext', 'req_extensions' => 'ext'];
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $config]);
    $csr = openssl_csr_new(['commonName' => $names[0]], $key, $options);
    $signed = openssl_csr_sign($csr, null, $key, 30, $options, random_int(1, PHP_INT_MAX));
    openssl_x509_export_to_file($signed, $cert);
    openssl_pkey_export_to_file($key, $keyFile, null, ['config' => $config]);
    chmod($keyFile, 0o600);
    say('warning: generated a self-signed certificate for '.implode(', ', $names).' (trial use only)');

    return [$cert, $keyFile];
}

function nginx_config(): string
{
    $mode = strtolower((string) env_value('OPENHDID_TLS', 'on'));
    $names = server_names();
    $origin = app_url()['origin'];
    $ipv6 = is_readable('/proc/net/if_inet6') && trim((string) file_get_contents('/proc/net/if_inet6')) !== '';
    $workers = (string) env_value('OPENHDID_NGINX_WORKERS', 'auto');
    if ($workers !== 'auto' && ! ctype_digit($workers)) {
        stop_on(['OPENHDID_NGINX_WORKERS must be a number or auto.']);
    }

    $monitoring = env_list('OPENHDID_MONITORING_ALLOW');
    foreach ($monitoring as $cidr) {
        if (! is_cidr($cidr)) {
            stop_on(["invalid address '{$cidr}' in OPENHDID_MONITORING_ALLOW"]);
        }
    }

    $cert = $key = '';
    if ($mode === 'on') {
        $cert = (string) env_value('OPENHDID_TLS_CERT', DEFAULT_CERT);
        $key = (string) env_value('OPENHDID_TLS_KEY', DEFAULT_KEY);
        $result = inspect_tls($cert, $key, $names);
        foreach ($result['summary'] as $line) {
            say('tls: '.$line);
        }
        foreach ($result['warnings'] as $warning) {
            say('warning: tls: '.$warning);
        }
        stop_on(array_map(fn (string $error): string => 'tls: '.$error, $result['errors']));
    } elseif ($mode === 'selfsigned') {
        [$cert, $key] = self_signed($names);
    }

    $realIp = '';
    if ($mode === 'off') {
        $proxies = array_values(array_diff(env_list('TRUSTED_PROXIES'), ['127.0.0.1', '::1']));
        foreach ($proxies as $proxy) {
            if (! is_cidr($proxy)) {
                stop_on(["invalid address '{$proxy}' in TRUSTED_PROXIES"]);
            }
        }
        if ($proxies === []) {
            say('warning: OPENHDID_TLS=off but TRUSTED_PROXIES names no load balancer; logs and rate limits will see the proxy address');
        }
        $realIp = implode('', array_map(fn (string $proxy): string => "    set_real_ip_from {$proxy};\n", $proxies))
            ."    real_ip_header X-Forwarded-For;\n    real_ip_recursive on;\n";
    }

    $listen = fn (string $port, string $extra = ''): string => "        listen {$port}{$extra};\n".($ipv6 ? "        listen [::]:{$port}{$extra};\n" : '');
    $serverNames = implode(' ', $names);
    $geo = implode('', array_map(fn (string $cidr): string => "        {$cidr} 1;\n", $monitoring));
    $httpsParam = $mode === 'off' ? "            fastcgi_param HTTPS on;\n" : '';

    $app = <<<NGINX
        root /app/public;
        index index.php;
        client_max_body_size 10m;
        client_header_timeout 15s;
        client_body_timeout 30s;
        send_timeout 30s;
        keepalive_timeout 30s;

        if (\$request_method = TRACE) { return 405; }

        add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
        add_header X-Content-Type-Options nosniff always;
        add_header X-Frame-Options DENY always;
        add_header Referrer-Policy strict-origin-when-cross-origin always;
        add_header Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=()" always;
        add_header Cross-Origin-Opener-Policy same-origin always;
        add_header Content-Security-Policy \$openhdid_csp always;
        add_header X-Robots-Tag "noindex, nofollow" always;

        error_page 400 403 404 405 408 413 414 421 429 431 500 501 502 503 504 /__openhdid_error.html;
        location = /__openhdid_error.html {
            internal;
            client_max_body_size 0;
            alias /usr/local/share/openhdid/error.html;
        }

        location ~ /\\. { return 404; }
        location = /robots.txt { try_files \$uri =404; }

        location ~ ^/(health|up)/?\$ {
            if (\$openhdid_monitoring = 0) { return 404; }
            rewrite ^ /index.php last;
        }

        # Livewire serves its scripts through Laravel under a hashed prefix.
        location ~ ^/livewire-[a-f0-9]+/ { rewrite ^ /index.php last; }

        # Vite output is content-hashed: cache it for a year, serve the pre-compressed copy.
        location ^~ /build/ {
            gzip_static on;
            add_header Cache-Control "public, max-age=31536000, immutable" always;
            try_files \$uri =404;
        }

        location ~* \\.(css|js|mjs|png|jpg|jpeg|gif|webp|avif|ico|svg|woff|woff2|ttf|eot)\$ {
            gzip_static on;
            try_files \$uri =404;
        }

        location / { rewrite ^ /index.php last; }

        location = /index.php {
            include /etc/nginx/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME \$realpath_root/index.php;
            fastcgi_param DOCUMENT_ROOT \$realpath_root;
            fastcgi_param HTTP_PROXY "";
{$httpsParam}            fastcgi_pass unix:/tmp/openhdid/run/php-fpm.sock;
            fastcgi_read_timeout 120s;
            fastcgi_intercept_errors off;
            fastcgi_hide_header X-Powered-By;
            fastcgi_hide_header Strict-Transport-Security;
            fastcgi_hide_header X-Content-Type-Options;
            fastcgi_hide_header X-Frame-Options;
            fastcgi_hide_header Referrer-Policy;
            fastcgi_hide_header Permissions-Policy;
            fastcgi_hide_header Cross-Origin-Opener-Policy;
            fastcgi_hide_header Content-Security-Policy;
            fastcgi_hide_header Cache-Control;
            fastcgi_hide_header Expires;
            add_header Cache-Control no-store always;
        }

        location ~* \\.(php|phar)(/|\$) { return 404; }
NGINX;

    $servers = '';
    if ($mode === 'off') {
        $servers .= "    server {\n".$listen('8080', ' default_server')."        server_name _;\n        return 444;\n    }\n\n";
        $servers .= "    server {\n".$listen('8080')."        server_name {$serverNames};\n{$app}\n    }\n";
    } else {
        $redirect = env_bool('OPENHDID_HTTP_REDIRECT', true)
            ? "        location / {\n            if (\$request_method !~ ^(GET|HEAD)\$) { return 444; }\n            return 308 {$origin}\$request_uri;\n        }\n        location /api/ { return 444; }\n        location /auth/ { return 444; }\n"
            : "        return 444;\n";
        $servers .= "    server {\n".$listen('8080', ' default_server')."        server_name _;\n        return 444;\n    }\n\n";
        $servers .= "    server {\n".$listen('8443', ' ssl default_server')."        server_name _;\n        ssl_reject_handshake on;\n    }\n\n";
        $servers .= "    server {\n".$listen('8080')."        server_name {$serverNames};\n{$redirect}    }\n\n";
        $servers .= "    server {\n".$listen('8443', ' ssl')."        http2 on;\n        server_name {$serverNames};\n        ssl_certificate {$cert};\n        ssl_certificate_key {$key};\n{$app}\n    }\n";
    }

    return <<<NGINX
# Generated by openhdid at container start from the environment; do not edit.
worker_processes {$workers};
pid /tmp/openhdid/nginx/nginx.pid;
error_log stderr warn;

events {
    worker_connections 1024;
}

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    charset utf-8;

    server_tokens off;
    more_clear_headers 'Server' 'X-Powered-By';
    add_header_inherit merge;

    client_body_temp_path /tmp/openhdid/nginx/body;
    fastcgi_temp_path /tmp/openhdid/nginx/fastcgi;
    server_names_hash_bucket_size 128;
    sendfile on;
    tcp_nopush on;
    gzip off;

    ssl_protocols TLSv1.3;
    ssl_conf_command Ciphersuites TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256:TLS_AES_128_GCM_SHA256;
    ssl_ecdh_curve X25519MLKEM768:X25519:prime256v1:secp384r1;
    ssl_early_data off;
    ssl_session_tickets off;
    ssl_session_cache shared:OPENHDIDTLS:10m;
    ssl_session_timeout 10m;

    # No URL, query, referrer, cookie or body: those may carry codes and keys.
    log_format openhdid escape=json
        '{"at":"\$time_iso8601","ip":"\$remote_addr",'
        '"request_id":"\$request_id","app_request_id":"\$sent_http_x_request_id",'
        '"method":"\$request_method","status":\$status,'
        '"bytes":\$body_bytes_sent,"seconds":\$request_time}';
    access_log /dev/stdout openhdid;

    # Keep the application's CSP; responses without one (static, errors) get a strict default.
    map \$upstream_http_content_security_policy \$openhdid_csp {
        default \$upstream_http_content_security_policy;
        "" "default-src 'none'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'";
    }

    geo \$openhdid_monitoring {
        default 0;
        127.0.0.1 1;
        ::1 1;
{$geo}    }

{$realIp}
{$servers}
    # Internal liveness endpoint for the container health check only.
    server {
        listen 127.0.0.1:8081;
        server_name _;
        location = /up {
            include /etc/nginx/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME /app/public/index.php;
            fastcgi_param HTTP_PROXY "";
            fastcgi_pass unix:/tmp/openhdid/run/php-fpm.sock;
        }
        location / { return 404; }
    }
}

NGINX;
}

function boot_laravel(): object
{
    require APP_DIR.'/vendor/autoload.php';
    $app = require APP_DIR.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    return $app;
}

function wait_db(): void
{
    boot_laravel();
    $deadline = time() + (int) env_value('OPENHDID_DB_WAIT_SECONDS', '120');
    $lastError = '';
    do {
        try {
            DB::connection()->getPdo();
            say('database connection ok');

            return;
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
            DB::purge();
            sleep(2);
        }
    } while (time() < $deadline);

    stop_on(['database not reachable: '.$lastError]);
}

function demo_data(): void
{
    if (! env_bool('OPENHDID_DEMO_DATA', false)) {
        return;
    }
    $app = boot_laravel();
    if ($app->isProduction()) {
        stop_on(['OPENHDID_DEMO_DATA=true needs APP_ENV=demo (demo accounts have published passwords).']);
    }
    if (User::query()->where('email', 'agent@example.test')->exists()) {
        say('demo data already loaded');

        return;
    }
    Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
    say('demo data loaded (see the installation guide for the demo accounts)');
}

function healthcheck(): int
{
    $role = trim((string) @file_get_contents(RUNTIME.'/role'));
    if ($role !== 'web') {
        return 0;
    }
    $socket = @fsockopen('127.0.0.1', 8081, $code, $message, 3);
    if ($socket === false) {
        return 1;
    }
    stream_set_timeout($socket, 4);
    fwrite($socket, "GET /up HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $status = (string) fgets($socket);
    fclose($socket);

    return preg_match('#^HTTP/1\.[01] 200 #', $status) ? 0 : 1;
}

$command = $argv[1] ?? '';

switch ($command) {
    case 'check-env':
        check_env($argv[2] ?? 'web');
        break;
    case 'nginx-config':
        echo nginx_config();
        break;
    case 'tls-check':
        $result = inspect_tls((string) env_value('OPENHDID_TLS_CERT', DEFAULT_CERT), (string) env_value('OPENHDID_TLS_KEY', DEFAULT_KEY), server_names());
        foreach ($result['summary'] as $line) {
            echo $line, PHP_EOL;
        }
        foreach ($result['warnings'] as $line) {
            echo 'warning: ', $line, PHP_EOL;
        }
        foreach ($result['errors'] as $line) {
            echo 'ERROR: ', $line, PHP_EOL;
        }
        echo $result['errors'] === [] ? 'TLS files are usable.' : 'TLS files are NOT usable.', PHP_EOL;
        exit($result['errors'] === [] ? 0 : 1);
    case 'wait-db':
        wait_db();
        break;
    case 'demo-data':
        demo_data();
        break;
    case 'healthcheck':
        exit(healthcheck());
    default:
        fwrite(STDERR, "usage: runtime.php check-env|nginx-config|tls-check|wait-db|demo-data|healthcheck\n");
        exit(64);
}
