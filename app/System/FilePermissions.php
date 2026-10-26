<?php

namespace App\System;

/**
 * Files of a classic installation that hold secrets, and which of them any
 * other local account of the server can read: the .env, the framework caches
 * under bootstrap/cache (config.php carries APP_KEY and every password, also
 * when APP_CONFIG_CACHE puts it elsewhere), the logs and the session files.
 * A file counts only when "other" may read it and may pass every folder
 * above it where it really lies (a symlinked folder is followed). Files on a read-only mount are the container image's own, built
 * once for everybody, and are left out; what the container writes at run
 * time (its config cache, its logs) is checked like any other file.
 */
class FilePermissions
{
    private const MOUNT_INFO = '/proc/self/mountinfo';

    /**
     * @param  list<string>|null  $candidates  the files to look at; null for the installation's own
     */
    public function __construct(
        private readonly ?array $candidates = null,
        private readonly string $mountInfo = self::MOUNT_INFO,
    ) {}

    /**
     * The files any other local account can read, as absolute paths.
     *
     * @return list<string>
     */
    public function exposed(): array
    {
        $mounts = $this->mounts();

        return array_values(array_filter(
            array_unique($this->candidates ?? self::installationFiles()),
            fn (string $path): bool => $this->readableByOthers($path) && ! self::onReadOnlyMount(realpath($path) ?: $path, $mounts),
        ));
    }

    /**
     * @return list<string>
     */
    public static function installationFiles(): array
    {
        $files = [base_path('.env'), app()->getCachedConfigPath()];

        foreach (glob(base_path('bootstrap/cache/*.php')) ?: [] as $file) {
            $files[] = $file;
        }

        foreach ([storage_path('logs'), storage_path('framework/sessions')] as $folder) {
            foreach (glob($folder.'/*') ?: [] as $file) {
                if (is_file($file) && ! str_starts_with(basename($file), '.')) {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    public function readableByOthers(string $path): bool
    {
        clearstatcache(true, $path);

        if (! is_file($path)) {
            return false;
        }

        $mode = @fileperms($path);
        // Through a symlinked folder (logs on another disk, a release folder) the
        // file is reachable where it really lies: its real folders decide.
        $real = realpath($path);

        if ($mode === false || ($mode & 0o004) === 0 || $real === false) {
            return false;
        }

        for ($folder = dirname($real); ; $folder = dirname($folder)) {
            $folderMode = @fileperms($folder);

            if ($folderMode === false || ($folderMode & 0o001) === 0) {
                return false;
            }

            if (dirname($folder) === $folder) {
                return true;
            }
        }
    }

    /**
     * Every mount point (Linux) with whether it is mounted read-only.
     *
     * @return array<string, bool>
     */
    private function mounts(): array
    {
        $table = is_readable($this->mountInfo) ? (string) @file_get_contents($this->mountInfo) : '';
        $mounts = [];

        foreach (preg_split('/\R/', $table) ?: [] as $line) {
            $fields = explode(' ', $line);

            if (count($fields) < 6) {
                continue;
            }

            // mountinfo: id parent major:minor root mount-point options …; a space in a name is \040.
            $mounts[str_replace('\040', ' ', $fields[4])] = in_array('ro', explode(',', $fields[5]), true);
        }

        return $mounts;
    }

    /**
     * Whether the path lies on a read-only mount. The longest mount point
     * holding it decides, so a writable volume (the storage) below the
     * read-only root of a container is not taken for read-only.
     *
     * @param  array<string, bool>  $mounts
     */
    private static function onReadOnlyMount(string $path, array $mounts): bool
    {
        $best = null;

        foreach (array_keys($mounts) as $mount) {
            $prefix = rtrim((string) $mount, '/');
            $holds = $prefix === '' || $path === $prefix || str_starts_with($path, $prefix.'/');

            if ($holds && ($best === null || strlen((string) $mount) > strlen($best))) {
                $best = (string) $mount;
            }
        }

        return $best !== null && $mounts[$best];
    }
}
