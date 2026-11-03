<?php

namespace App\System;

/**
 * Limits of the running PHP-FPM pool and its container, as far as they can
 * be read at run time: the pool's status, the per-request memory_limit, the
 * number of FPM processes (OPENHDID_FPM_MAX_CHILDREN, or the process count
 * of a static pool) and the memory limit of the cgroup the web server runs
 * in. Outside PHP-FPM (artisan, the worker) nothing here describes the web
 * server, so everything is null.
 */
class RuntimeLimits
{
    /** Memory of an FPM process outside its memory_limit (code, libraries, buffers), in bytes. */
    public const FPM_PROCESS_OVERHEAD = 22 * 1_048_576;

    /** Memory of one nginx worker in the web container, in bytes (runtime.php NGINX_WORKER_MB). */
    public const NGINX_WORKER = 80 * 1_048_576;

    /** Memory the web container keeps free beyond PHP-FPM, nginx and /tmp, in bytes (runtime.php WEB_SPARE_MB). */
    public const WEB_SPARE = 64 * 1_048_576;

    /** @var list<string> */
    protected const CGROUP_MEMORY_FILES = ['/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/memory/memory.limit_in_bytes'];

    protected const MOUNTS_FILE = '/proc/self/mounts';

    public function inFpm(): bool
    {
        return PHP_SAPI === 'fpm-fcgi' && function_exists('fpm_get_status');
    }

    /**
     * The pool status (fpm_get_status: active, idle and total processes,
     * listen queue, how often max_children was reached), or null.
     *
     * @return array<string, mixed>|null
     */
    public function fpmStatus(): ?array
    {
        if (! $this->inFpm()) {
            return null;
        }

        $status = fpm_get_status();

        return is_array($status) ? $status : null;
    }

    /**
     * pm.max_children of this web server, when known.
     */
    public function fpmMaxChildren(): ?int
    {
        if (! $this->inFpm()) {
            return null;
        }

        $configured = config('hdid.capacity.fpm_max_children');

        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        $status = $this->fpmStatus();

        return ($status['process-manager'] ?? null) === 'static' && isset($status['total-processes']) ? (int) $status['total-processes'] : null;
    }

    /**
     * memory_limit of one web request in bytes; null when unlimited or outside FPM.
     */
    public function requestMemoryLimit(): ?int
    {
        if (! $this->inFpm()) {
            return null;
        }

        $bytes = self::bytes((string) ini_get('memory_limit'));

        return $bytes > 0 ? $bytes : null;
    }

    /**
     * The memory limit of the web server's cgroup (the container or pod) in bytes, or null.
     */
    public function containerMemoryLimit(): ?int
    {
        if (! $this->inFpm()) {
            return null;
        }

        foreach (static::CGROUP_MEMORY_FILES as $file) {
            $value = is_readable($file) ? trim((string) @file_get_contents($file)) : '';

            // "max" (v2) or a huge number (v1) mean no limit.
            if (ctype_digit($value) && (int) $value < PHP_INT_MAX / 2) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Whether this is the OpenHDID web container, where nginx and the
     * memory-backed /tmp share the memory limit with PHP-FPM. On a classic
     * installation the PHP-FPM cgroup holds neither, so they are not counted.
     */
    public function inWebContainer(): bool
    {
        return $this->inFpm() && filled(config('hdid.capacity.nginx_workers'));
    }

    /**
     * The nginx workers of the web container ("auto" is one per CPU), or null elsewhere.
     */
    public function nginxWorkers(): ?int
    {
        if (! $this->inWebContainer()) {
            return null;
        }

        $workers = (string) config('hdid.capacity.nginx_workers');

        if ($workers !== 'auto') {
            return max(1, (int) $workers);
        }

        $info = is_readable('/proc/cpuinfo') ? (string) @file_get_contents('/proc/cpuinfo') : '';

        return max(1, (int) preg_match_all('/^processor\s*:/m', $info));
    }

    /**
     * The size of the memory-backed /tmp of the web container in bytes, or
     * null when /tmp is no tmpfs with a size of its own, or elsewhere.
     */
    public function tmpfsBytes(): ?int
    {
        if (! $this->inWebContainer()) {
            return null;
        }

        $mounts = is_readable(static::MOUNTS_FILE) ? (string) @file_get_contents(static::MOUNTS_FILE) : '';

        foreach (preg_split('/\R/', $mounts) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            if (($fields[1] ?? null) === '/tmp' && ($fields[2] ?? null) === 'tmpfs' && preg_match('/(?:^|,)size=(\d+)([kmg]?)(?:,|$)/i', $fields[3] ?? '', $size)) {
                return (int) $size[1] * match (strtolower($size[2])) {
                    'k' => 1024,
                    'm' => 1_048_576,
                    'g' => 1_073_741_824,
                    default => 1,
                };
            }
        }

        return null;
    }

    /**
     * How full the memory-backed /tmp is, or null when it is not one.
     *
     * @return array{used: int, total: int}|null
     */
    public function tmpUsage(): ?array
    {
        if ($this->tmpfsBytes() === null) {
            return null;
        }

        $total = @disk_total_space('/tmp');
        $free = @disk_free_space('/tmp');

        return $total === false || $free === false ? null : ['used' => (int) ($total - $free), 'total' => (int) $total];
    }

    /**
     * Shared opcache memory of the pool in bytes.
     */
    public function opcacheBytes(): int
    {
        if (! filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)) {
            return 0;
        }

        return ((int) ini_get('opcache.memory_consumption') + (int) ini_get('opcache.interned_strings_buffer')) * 1_048_576;
    }

    /**
     * A php.ini size ("128M", "1G", "-1") in bytes; -1 for unlimited.
     */
    public static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1_073_741_824,
            'M' => $number * 1_048_576,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
