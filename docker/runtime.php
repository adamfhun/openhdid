<?php

use App\Models\User;
use App\System\DemoData;
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
 *   demo-data          loads the demo data once when OPENHDID_DEMO_DATA=true (never in production);
 *                      in production it stops while the database holds demo data
 *   healthcheck [live] image HEALTHCHECK: the web role answers /up on the internal port (PHP-FPM);
 *                      "live" asks nginx alone, for a liveness probe that a busy pool does not fail
 *
 * Plain PHP with the openssl extension; only wait-db and demo-data boot Laravel.
 */

const RUNTIME = '/tmp/openhdid';
const APP_DIR = '/app';
const DEFAULT_CERT = '/etc/openhdid/tls/fullchain.pem';
const DEFAULT_KEY = '/etc/openhdid/tls/privkey.pem';
const EXPIRY_WARNING_DAYS = 30;
// The memory limit of the container (cgroup v2, then v1).
const CGROUP_MEMORY_FILES = ['/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/memory/memory.limit_in_bytes'];
// Memory of a PHP-FPM process outside its memory_limit, the opcache (php.ini) and one nginx worker
// with full connection buffers, in MB: what the web container's memory limit has to hold.
const FPM_PROCESS_OVERHEAD_MB = 22;
const OPCACHE_MB = 208;
const NGINX_WORKER_MB = 80;

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
 * HDID_RATE_LIMIT_EXEMPT_NETWORKS: the networks free of the limits keyed on the client
 * address (nginx's limit_req and limit_conn, the application's address limits), an IPv4
 * network at most /8 wide, an IPv6 one /32, as the application's RateLimitExemptions
 * reads them; the second list names the refused entries with the reason.
 *
 * @return array{list<string>, list<string>}
 */
function exempt_networks(): array
{
    $networks = env_list('HDID_RATE_LIMIT_EXEMPT_NETWORKS');
    $errors = [];
    foreach ($networks as $entry) {
        if (! is_cidr($entry)) {
            $errors[] = "invalid address '{$entry}' in HDID_RATE_LIMIT_EXEMPT_NETWORKS (an IPv4 or IPv6 address or network, such as 192.0.2.0/24)";

            continue;
        }
        [$ip, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
        $min = str_contains($ip, ':') ? 32 : 8;
        if ($prefix !== null && (int) $prefix < $min) {
            $errors[] = "'{$entry}' in HDID_RATE_LIMIT_EXEMPT_NETWORKS is too wide: a network may be at most /8 (IPv4) or /32 (IPv6); list the company's own addresses.";
        }
    }

    return [$errors === [] ? $networks : [], $errors];
}

/**
 * @return array{host: string, origin: string, port: int}
 */
function app_url(): array
{
    $parts = parse_url((string) env_value('APP_URL', ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $port = isset($parts['port']) ? (int) $parts['port'] : 443;

    return ['host' => $host, 'origin' => 'https://'.$host.($port !== 443 ? ':'.$port : ''), 'port' => $port];
}

/**
 * A whole number from the environment for an nginx directive; anything else stops the start.
 */
function env_count(string $name, int $default, string $hint): int
{
    $value = env_value($name, (string) $default);
    if (! ctype_digit($value) || strlen($value) > 6) {
        stop_on(["{$name} must be a whole number from 0 to 999999 (got {$value}); {$hint}"]);
    }

    return (int) $value;
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
    // Laravel compares the environment name exactly: "prod" or "Production"
    // would silently switch every production safeguard below off.
    $environment = env_value('APP_ENV', 'production');
    $knownEnvironments = ['production', 'staging', 'demo', 'local', 'testing'];
    if (! in_array($environment, $knownEnvironments, true)) {
        stop_on(['APP_ENV must be one of '.implode(', ', $knownEnvironments)." (got {$environment}); any other value would switch the production safeguards off."]);
    }
    $production = $environment === 'production';

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
        $errors[] = 'APP_DEBUG=true is not allowed with APP_ENV=production: error pages would show internals. For a debugging session set APP_ENV=staging together with APP_DEBUG=true and revert both afterwards; LOG_LEVEL=debug widens the log without exposing details.';
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
        if (env_value('DB_PASSWORD') === null && $production && env_value('DB_SOCKET') === null) {
            $errors[] = 'DB_PASSWORD is empty: the database account would have no password. Set it in .env (and the migration account\'s in db.env).';
        } elseif (env_value('DB_PASSWORD') === null) {
            $warnings[] = 'DB_PASSWORD is empty.';
        }
    }

    $redisStores = array_filter(['CACHE_STORE', 'QUEUE_CONNECTION', 'SESSION_DRIVER'], fn (string $name): bool => env_value($name) === 'redis');
    if ($production && $redisStores !== [] && env_value('REDIS_PASSWORD') === null) {
        $errors[] = 'REDIS_PASSWORD is empty while '.implode(', ', $redisStores).' uses redis: Valkey would answer anyone on the network, and queued jobs run what they read. Set REDIS_PASSWORD in .env.';
    }

    // Each of these trusts every address (Laravel reads "*" and "**" so too),
    // which lets any client forge its address and the host of generated links.
    $trustAll = array_values(array_intersect(env_list('TRUSTED_PROXIES'), ['*', '**', '0.0.0.0/0', '::/0']));
    if ($production && $trustAll !== []) {
        $errors[] = 'TRUSTED_PROXIES='.implode(',', $trustAll).' trusts every address and is not allowed in production; list the load balancer addresses.';
    }
    // The application's outbound requests (master data, Exchange, SMS, ADFS,
    // Entra) go through HDID_HTTP_PROXY; an address cURL cannot use would fail
    // each of them. The value is never echoed: it may carry a password.
    $outboundProxy = env_value('HDID_HTTP_PROXY');
    if ($outboundProxy !== null) {
        $parts = parse_url(str_contains($outboundProxy, '://') ? $outboundProxy : 'http://'.$outboundProxy);
        if ($parts === false
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https', 'socks4', 'socks4a', 'socks5', 'socks5h'], true)
            || empty($parts['host'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            $errors[] = 'HDID_HTTP_PROXY must be a proxy address such as http://proxy.example.org:3128 or http://user:password@proxy.example.org:3128 (the value is not shown, it may contain a password).';
        }
    }
    // An entry that is not a network, or one wide enough to take most of the internet
    // off the limits, stops every role: the application would ignore the list too.
    array_push($errors, ...exempt_networks()[1]);
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
    // The image builds its own libcurl with NTLM (Alpine's has none); should the PHP extension ever
    // load the wrong library, say so at start instead of answering every EWS request with a 401.
    if (env_value('MAIL_MAILER', 'log') === 'ews' && env_value('EWS_AUTH', 'basic') === 'ntlm' && ! (curl_version()['features'] & CURL_VERSION_NTLM)) {
        $errors[] = 'EWS_AUTH=ntlm cannot work: the libcurl this PHP loaded has no NTLM support (libcurl '.curl_version()['version'].'). Report it with the image version; meanwhile enable basic authentication on the Exchange EWS virtual directory (EWS_AUTH=basic), use oauth (Exchange Online), or an SMTP connector (MAIL_MAILER=smtp).';
    }
    if ($production && in_array(env_value('MAIL_MAILER', 'log'), ['log', 'array'], true)) {
        $warnings[] = 'MAIL_MAILER writes e-mails to the log only; no e-mail leaves the server.';
    }
    if ($production && env_value('MAIL_MAILER', 'log') === 'smtp' && strtolower(trim((string) env_value('MAIL_REQUIRE_TLS', 'true'))) === 'false'
        && strtolower((string) env_value('MAIL_SCHEME', '')) !== 'smtps' && (string) env_value('MAIL_PORT', '') !== '465') {
        $warnings[] = 'MAIL_REQUIRE_TLS=false: SMTP may send the password and every e-mail unencrypted when the server offers no STARTTLS.';
    }
    if ($production && env_value('SMS_DRIVER', 'log') === 'log') {
        $warnings[] = 'SMS_DRIVER=log writes SMS to the log only; clients receive nothing.';
    }
    if (in_array(env_value('QUEUE_CONNECTION', 'database'), ['sync', 'null'], true)) {
        $warnings[] = 'QUEUE_CONNECTION=sync: e-mails and SMS are sent inside web requests and the panel cannot start an EMD sync or a file import.';
    }

    if ($production && in_array('SESSION_DRIVER', $redisStores, true) && env_value('REDIS_SESSION_HOST') === null) {
        $warnings[] = 'the sessions share the main Valkey (REDIS_SESSION_HOST is empty): a flood of sign-in pages that fills it also stops the queue, the locks and the rate limits. Give them a Valkey of their own with maxmemory-policy allkeys-lru (the valkey-sessions service of the redis profile, or REDIS_SESSION_HOST for an external one).';
    }

    if ($role === 'web') {
        [$fpmErrors, $fpmWarnings] = fpm_limits();
        array_push($errors, ...$fpmErrors);
        array_push($warnings, ...$fpmWarnings);
    }

    // The image ships max_execution_time = 300 for FPM and the CLI alike; an
    // override below 120 s would make every EMD sync refuse to start.
    $timeLimit = (int) ini_get('max_execution_time');
    if ($timeLimit > 0 && $timeLimit < 120) {
        $errors[] = "max_execution_time is {$timeLimit} s; EMD sync needs at least 120 s (300 recommended). Remove the override of the image's php.ini.";
    } elseif ($timeLimit > 0 && $timeLimit < 300) {
        $warnings[] = "max_execution_time is {$timeLimit} s; 300 s is recommended for large EMD imports.";
    }

    foreach ($warnings as $warning) {
        say('warning: '.$warning);
    }
    stop_on($errors);
}

/**
 * A php.ini size ("128M", "1G") in MB, or null when it is not one.
 */
function size_mb(string $value): ?int
{
    if (! preg_match('/^([1-9][0-9]{0,5})([MG])$/i', $value, $match)) {
        return null;
    }

    return (int) $match[1] * (strtoupper($match[2]) === 'G' ? 1024 : 1);
}

/**
 * The memory limit of this container in MB, or null when it has none.
 */
function container_memory_mb(): ?int
{
    foreach (CGROUP_MEMORY_FILES as $file) {
        $value = is_readable($file) ? trim((string) file_get_contents($file)) : '';
        if (ctype_digit($value) && (int) $value < PHP_INT_MAX / 2) {
            return intdiv((int) $value, 1_048_576);
        }
    }

    return null;
}

/**
 * The PHP-FPM pool values of the web role, and whether the pool fits the
 * container: processes × (memory_limit + overhead), the opcache and the
 * nginx workers above the container's memory limit get the kernel to stop
 * the whole web server under load.
 *
 * @return array{0: list<string>, 1: list<string>} errors, warnings
 */
function fpm_limits(): array
{
    $errors = [];
    $warnings = [];

    $limit = (string) env_value('OPENHDID_FPM_MEMORY_LIMIT', '128M');
    $limitMb = size_mb($limit);
    if ($limitMb === null || $limitMb < 64) {
        $errors[] = "OPENHDID_FPM_MEMORY_LIMIT must be a size of at least 64M, such as 128M (got {$limit}); it is the memory one web request may use.";
    }

    $children = (string) env_value('OPENHDID_FPM_MAX_CHILDREN', '8');
    if (! ctype_digit($children) || (int) $children < 1 || (int) $children > 512) {
        $errors[] = "OPENHDID_FPM_MAX_CHILDREN must be a whole number from 1 to 512 (got {$children}).";
    }

    $backlog = (string) env_value('OPENHDID_FPM_BACKLOG', '64');
    if (! ctype_digit($backlog) || (int) $backlog < 1 || (int) $backlog > 65535) {
        $errors[] = "OPENHDID_FPM_BACKLOG must be a whole number from 1 to 65535 (got {$backlog}); it is the number of requests that may wait for a free PHP-FPM process.";
    }

    $container = container_memory_mb();
    if ($errors === [] && $container !== null) {
        $workers = (string) env_value('OPENHDID_NGINX_WORKERS', 'auto');
        $nginx = ($workers === 'auto' ? (int) (shell_cpu_count() ?: 1) : max(1, (int) $workers)) * NGINX_WORKER_MB;
        $need = (int) $children * ($limitMb + FPM_PROCESS_OVERHEAD_MB) + OPCACHE_MB + $nginx;
        if ($need > $container) {
            $warnings[] = "PHP-FPM and nginx may take {$need} MB ({$children} processes with memory_limit {$limit}, the opcache and the nginx workers), more than the web container's memory limit of {$container} MB: under load the kernel stops the whole web server. Lower OPENHDID_FPM_MAX_CHILDREN, OPENHDID_FPM_MEMORY_LIMIT or OPENHDID_NGINX_WORKERS (leave it empty for the usage profile's value instead of auto), or raise OPENHDID_WEB_MEM_LIMIT (Kubernetes: the web pod's memory limit).";
        }
    }

    return [$errors, $warnings];
}

/**
 * The CPUs nginx's "worker_processes auto" sees.
 */
function shell_cpu_count(): ?int
{
    $info = is_readable('/proc/cpuinfo') ? (string) file_get_contents('/proc/cpuinfo') : '';
    $count = preg_match_all('/^processor\s*:/m', $info);

    return $count > 0 ? $count : null;
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

    // Requests per second (and the burst on top) per client address, after realip,
    // to the application; static files do not count. 0 switches the limit off. The
    // defaults (20/s, 100 at once) leave the normal peak of a profile to several
    // addresses: a NAT'd office is exempted with HDID_RATE_LIMIT_EXEMPT_NETWORKS.
    $rate = env_count('OPENHDID_NGINX_RATE_LIMIT', 20, 'it is the requests per second one client address may send to the application, 0 switches the limit off.');
    $burst = env_count('OPENHDID_NGINX_RATE_BURST', 100, 'it is the number of requests one client address may send at once above OPENHDID_NGINX_RATE_LIMIT.');
    // Requests one client address may have in the application at the same time (each HTTP/2
    // stream counts): a few addresses cannot hold every PHP-FPM process. 0 switches it off.
    $connLimit = env_count('OPENHDID_NGINX_CONN_LIMIT', 16, 'it is the number of requests one client address may have in the application at once, 0 switches the limit off.');
    // Seconds a client may stay silent while sending the request body or receiving the answer,
    // and an idle connection stays open: a slow client cannot hold connections for long.
    $clientTimeout = env_count('OPENHDID_NGINX_CLIENT_TIMEOUT', 15, 'it is the number of seconds a client may stay silent while sending or receiving.');
    if ($clientTimeout < 5 || $clientTimeout > 300) {
        stop_on(["OPENHDID_NGINX_CLIENT_TIMEOUT must be from 5 to 300 seconds (got {$clientTimeout})."]);
    }

    // Behind a TLS-terminating load balancer (off) the client address comes from
    // X-Forwarded-For, but only from the TRUSTED_PROXIES addresses.
    $realIp = '';
    if ($mode === 'off') {
        $proxies = array_values(array_diff(env_list('TRUSTED_PROXIES'), ['127.0.0.1', '::1']));
        foreach ($proxies as $proxy) {
            if (! is_cidr($proxy)) {
                stop_on(["invalid address '{$proxy}' in TRUSTED_PROXIES"]);
            }
        }
        if ($proxies === []) {
            say('warning: OPENHDID_TLS=off but TRUSTED_PROXIES names no load balancer; logs and rate limits will see the proxy address'
                .($rate > 0 ? ', and the nginx request limit (OPENHDID_NGINX_RATE_LIMIT) counts every client as that one address' : ''));
        }
        $realIp = implode('', array_map(fn (string $proxy): string => "    set_real_ip_from {$proxy};\n", $proxies))
            ."    real_ip_header X-Forwarded-For;\n    real_ip_recursive on;\n";
    } elseif ($rate > 0 && array_diff(env_list('TRUSTED_PROXIES'), ['127.0.0.1', '::1']) !== []) {
        // With TLS here (on, selfsigned) nginx takes the client address from the
        // connection: behind a re-encrypting proxy every client is that proxy.
        say('warning: OPENHDID_TLS='.$mode.' with a proxy in TRUSTED_PROXIES: nginx sees the proxy\'s address, so the request limit (OPENHDID_NGINX_RATE_LIMIT) counts every client behind it as one; raise the limit, set it to 0, or let the proxy terminate TLS (OPENHDID_TLS=off)');
    }

    // The networks of HDID_RATE_LIMIT_EXEMPT_NETWORKS get an empty key, which neither
    // limit_req nor limit_conn counts; every other client is keyed as $openhdid_client.
    [$exempt, $exemptErrors] = exempt_networks();
    stop_on($exemptErrors);
    if ($exempt !== []) {
        say('nginx: no request or connection limit for HDID_RATE_LIMIT_EXEMPT_NETWORKS: '.implode(', ', $exempt));
    }
    $exemptGeo = implode('', array_map(fn (string $cidr): string => "        {$cidr} 1;\n", $exempt));

    $rateZone = $rateApp = '';
    if ($rate > 0) {
        $rateZone = "    limit_req_zone \$openhdid_limit_key zone=openhdid:10m rate={$rate}r/s;\n    limit_req_status 429;\n    limit_req_log_level warn;\n";
        // nginx refuses burst=0: no burst means no burst parameter (and nodelay has nothing to do).
        $rateApp = $burst > 0
            ? "            limit_req zone=openhdid burst={$burst} nodelay;\n"
            : "            limit_req zone=openhdid;\n";
        say("nginx: at most {$rate} requests/s per client address to the application, {$burst} more at once (OPENHDID_NGINX_RATE_LIMIT, OPENHDID_NGINX_RATE_BURST); above that the answer is 429");
    } else {
        say('warning: OPENHDID_NGINX_RATE_LIMIT=0: nginx sets no request limit per client address');
    }
    if ($connLimit > 0) {
        $rateZone .= "    limit_conn_zone \$openhdid_limit_key zone=openhdid_conn:10m;\n    limit_conn_status 429;\n    limit_conn_log_level warn;\n";
        $rateApp .= "            limit_conn openhdid_conn {$connLimit};\n";
        say("nginx: at most {$connLimit} requests at once per client address in the application (OPENHDID_NGINX_CONN_LIMIT); above that the answer is 429");
    } else {
        say('warning: OPENHDID_NGINX_CONN_LIMIT=0: nginx sets no limit on the requests one client address has in the application at once');
    }

    $listen = fn (string $port, string $extra = ''): string => "        listen {$port}{$extra};\n".($ipv6 ? "        listen [::]:{$port}{$extra};\n" : '');
    $serverNames = implode(' ', $names);
    $geo = implode('', array_map(fn (string $cidr): string => "        {$cidr} 1;\n", $monitoring));
    $httpsParam = $mode === 'off' ? "            fastcgi_param HTTPS on;\n" : '';
    $publicPort = app_url()['port'];

    $fastcgi = <<<NGINX
            include /etc/nginx/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME \$realpath_root/index.php;
            fastcgi_param DOCUMENT_ROOT \$realpath_root;
            fastcgi_param HTTP_PROXY "";
{$httpsParam}            # Forwarding headers reach PHP only as nginx established them: the client
            # address after realip, the matched server name, https and the APP_URL port.
            # Whatever a client or another host of the TRUSTED_PROXIES range sends is
            # dropped, so the application's own proxy trust cannot be talked into it.
            fastcgi_param HTTP_X_FORWARDED_FOR \$remote_addr;
            fastcgi_param HTTP_X_FORWARDED_HOST \$host;
            fastcgi_param HTTP_X_FORWARDED_PORT {$publicPort};
            fastcgi_param HTTP_X_FORWARDED_PROTO https;
            fastcgi_param HTTP_X_FORWARDED_PREFIX "";
            fastcgi_param HTTP_FORWARDED "";
{$rateApp}            fastcgi_pass unix:/tmp/openhdid/run/php-fpm.sock;
            fastcgi_read_timeout 120s;
            # A slow reader does not fill /tmp: beyond this nginx waits for the client.
            fastcgi_max_temp_file_size 16m;
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
NGINX;

    $app = <<<NGINX
        root /app/public;
        index index.php;
        # Request bodies of the panel and the APIs; file uploads have their own location below.
        client_max_body_size 4m;
        client_header_timeout 10s;
        client_body_timeout {$clientTimeout}s;
        send_timeout {$clientTimeout}s;
        keepalive_timeout {$clientTimeout}s;
        http2_max_concurrent_streams 32;

        if (\$request_method = TRACE) { return 405; }

        add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
        add_header X-Content-Type-Options nosniff always;
        add_header X-Frame-Options DENY always;
        add_header Referrer-Policy strict-origin-when-cross-origin always;
        add_header Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=()" always;
        add_header Cross-Origin-Opener-Policy same-origin always;
        add_header Content-Security-Policy \$openhdid_csp always;
        add_header X-Robots-Tag "noindex, nofollow" always;

        error_page 400 403 404 405 408 413 414 421 431 500 501 504 /__openhdid_error.html;
        location = /__openhdid_error.html {
            internal;
            client_max_body_size 0;
            alias /usr/local/share/openhdid/error.html;
        }

        # No free PHP-FPM process and its queue (OPENHDID_FPM_BACKLOG) full: refused at once with
        # 503, instead of waiting for minutes. The application's own 503 (maintenance) passes as is.
        error_page 502 503 =503 /__openhdid_unavailable;
        location = /__openhdid_unavailable {
            internal;
            default_type text/plain;
            add_header Retry-After 10 always;
            return 503 "A szolgáltatás most túlterhelt. Kérjük, próbálja újra néhány másodperc múlva.\\nThe service is busy right now. Please try again in a few seconds.\\n";
        }

        # The request limit per client address says why it refused, and when to retry.
        error_page 429 /__openhdid_busy;
        location = /__openhdid_busy {
            internal;
            default_type text/plain;
            add_header Retry-After 5 always;
            return 429 "Túl sok kérés érkezett erről a címről rövid idő alatt. Kérjük, várjon néhány másodpercet, majd próbálja újra.\\nToo many requests from this address in a short time. Please wait a few seconds and try again.\\n";
        }

        location ~ /\\. { return 404; }
        location = /robots.txt { try_files \$uri =404; }

        location ~ ^/(health|up)/?\$ {
            if (\$openhdid_monitoring = 0) { return 404; }
            rewrite ^ /index.php last;
        }

        # File uploads (the EMD import takes files up to 10 MiB; the multipart body is a little
        # larger) are the only requests with a body above 4 MB.
        location ~ ^/livewire-[a-f0-9]+/upload-file\$ {
            client_max_body_size 11m;
{$fastcgi}        }

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
{$fastcgi}        }

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
# Two descriptors per proxied connection (client, PHP-FPM) and a temporary file.
worker_rlimit_nofile 4096;
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

    # /health and /up: the OPENHDID_MONITORING_ALLOW hosts by client address (after
    # realip), the container itself by the connection's own address; a trusted proxy
    # sending "X-Forwarded-For: 127.0.0.1" is not the container.
    geo \$openhdid_monitoring_client {
        default 0;
{$geo}    }
    geo \$realip_remote_addr \$openhdid_loopback {
        default 0;
        127.0.0.1 1;
        ::1 1;
    }
    map "\$openhdid_monitoring_client\$openhdid_loopback" \$openhdid_monitoring {
        default 1;
        "00" 0;
    }

    # The key of the limits per client address: an IPv4 address, or the /64 network of an IPv6
    # one (a single subscriber gets a whole /64 and could rotate addresses within it). nginx
    # writes IPv6 addresses in the RFC 5952 form, so the first four groups are the network;
    # zero groups left out by "::" are written back as 0. An address of another shape counts alone.
    map \$remote_addr \$openhdid_client {
        '~^(?<net>[0-9a-f]{1,4}:[0-9a-f]{1,4}:[0-9a-f]{1,4}:[0-9a-f]{1,4}):' \$net;
        '~^(?<net>[0-9a-f]{1,4}:[0-9a-f]{1,4}:[0-9a-f]{1,4})::' \$net:0;
        '~^(?<net>[0-9a-f]{1,4}:[0-9a-f]{1,4})::(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,3})?\$' \$net:0:0;
        '~^(?<net>[0-9a-f]{1,4})::(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,3})?\$' \$net:0:0:0;
        '~^(?<head>[0-9a-f]{1,4})::(?<fourth>[0-9a-f]{1,4})(?::[0-9a-f]{1,4}){4}\$' \$head:0:0:\$fourth;
        default \$remote_addr;
    }

    # HDID_RATE_LIMIT_EXEMPT_NETWORKS by client address (after realip): an empty key, which
    # the request and connection limits do not count.
    geo \$openhdid_rate_exempt {
        default 0;
{$exemptGeo}    }
    map \$openhdid_rate_exempt \$openhdid_limit_key {
        1 "";
        default \$openhdid_client;
    }

{$realIp}{$rateZone}
{$servers}
    # Internal liveness endpoint for the container health check only.
    server {
        listen 127.0.0.1:8081;
        server_name _;
        # nginx alone: a liveness probe that a fully busy PHP-FPM pool does not fail.
        location = /alive { return 204; }
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

/**
 * The last step of the migrate role. With OPENHDID_DEMO_DATA=true it loads
 * the demo data once, and only with APP_ENV=demo: the demo accounts have
 * published passwords, so no other environment (staging is often reachable
 * from outside) gets them. In production it stops while the database holds
 * demo data (App\System\DemoData): a demo volume brought into production by
 * switching APP_ENV alone would keep the demo accounts, whose passwords, PIN
 * and answers are published; the other roles wait for this one. The message
 * names the addresses found first, so a real database with a stray .test
 * address is told apart from the demo.
 */
function demo_data(): void
{
    $wanted = env_bool('OPENHDID_DEMO_DATA', false);
    $environment = env_value('APP_ENV', 'production');
    if ($wanted && $environment !== 'demo' && $environment !== 'production') {
        stop_on(["OPENHDID_DEMO_DATA=true needs APP_ENV=demo (now APP_ENV={$environment}): the demo accounts have published passwords. Set APP_ENV=demo for a demo, or OPENHDID_DEMO_DATA=false."]);
    }
    if (! $wanted && $environment !== 'production') {
        return;
    }
    $app = boot_laravel();
    if ($app->isProduction()) {
        $errors = $wanted ? ['OPENHDID_DEMO_DATA=true needs APP_ENV=demo (demo accounts have published passwords).'] : [];
        $demo = $app->make(DemoData::class);
        $found = $demo->found();
        if (array_sum($found) > 0) {
            $samples = implode(', ', $demo->samples());
            $errors[] = "The database holds the demo data ({$found['users']} staff and {$found['clients']} client account(s) and {$found['records']} EMD record(s) under the reserved .test domain, {$found['calls']} demo call(s); found first: {$samples}) while APP_ENV=production. "
                .'The demo passwords, PIN and answers are published, and the EMD sync would meet the demo records under real external ids. '
                .'Start production with an empty database: stop the installation and remove the demo volumes (docker compose down -v removes every volume of the project, uploaded files too), then docker compose up -d and create the first administrator (hdid:make-admin). '
                .'To keep trying the demo, set APP_ENV=demo again. '
                .'If the addresses found are not the demo accounts but a few real rows with a .test address, the database is real: do not remove it; set APP_ENV=staging for a moment, correct those addresses on the panel, then set APP_ENV=production again.';
        }
        stop_on($errors);

        return;
    }
    if (User::query()->where('email', 'agent@example.test')->exists()) {
        say('demo data already loaded');

        return;
    }
    Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
    say('demo data loaded (see the installation guide for the demo accounts)');
}

/**
 * The web role answers /up through PHP-FPM (readiness, the Compose health
 * check); with $live only nginx is asked (/alive), so a liveness probe does
 * not restart a container whose pool is merely busy.
 */
function healthcheck(bool $live = false): int
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
    fwrite($socket, 'GET '.($live ? '/alive' : '/up')." HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $status = (string) fgets($socket);
    fclose($socket);

    return preg_match($live ? '#^HTTP/1\.[01] 204 #' : '#^HTTP/1\.[01] 200 #', $status) ? 0 : 1;
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
        exit(healthcheck(($argv[2] ?? '') === 'live'));
    default:
        fwrite(STDERR, "usage: runtime.php check-env|nginx-config|tls-check|wait-db|demo-data|healthcheck\n");
        exit(64);
}
