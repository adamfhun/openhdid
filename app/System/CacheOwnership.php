<?php

namespace App\System;

use Illuminate\Foundation\Application;

/**
 * The framework requires its cache files (bootstrap/cache/config.php,
 * services.php, packages.php, the route and event caches) on every start,
 * so whoever can write them runs code as whoever starts artisan. On a
 * classic installation they belong to the runtime account (www-data): an
 * artisan run as the installer or as root would load what that account
 * wrote, and with a fresh sudo ticket in the same terminal a planted line
 * runs as root (runtime account → installer → root).
 *
 * artisan therefore stops, before any of them is loaded, when such a file
 * belongs to another account than the one running it, unless it belongs to
 * root (a file root wrote, like the container image's own, is no step up),
 * or when anybody may write it. A developer machine, the CI, the container
 * (its caches are built by the runtime account under /tmp) and the first
 * install (the installer still owns the files) are not affected. Without
 * the posix extension nothing is checked.
 */
final class CacheOwnership
{
    /**
     * The message to stop with, or null when artisan may go on.
     */
    public static function refusal(Application $app, ?int $effectiveUid = null): ?string
    {
        if ($effectiveUid === null) {
            if (! function_exists('posix_geteuid')) {
                return null;
            }
            $effectiveUid = posix_geteuid();
        }

        $paths = [
            $app->getCachedConfigPath(),
            $app->getCachedServicesPath(),
            $app->getCachedPackagesPath(),
            $app->getCachedRoutesPath(),
            $app->getCachedEventsPath(),
        ];

        foreach ($paths as $path) {
            $problem = self::problemWith($path, $effectiveUid);

            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /**
     * Why this one cache file must not be loaded by the given account.
     */
    public static function problemWith(string $path, int $effectiveUid): ?string
    {
        clearstatcache(true, $path);

        if (! is_file($path)) {
            return null;
        }

        $owner = fileowner($path);
        $mode = fileperms($path);

        if ($owner === false || $mode === false) {
            return null;
        }

        $ownerName = self::accountName($owner);

        if (($mode & 0o002) !== 0) {
            return "{$path} may be written by anybody (mode ".sprintf('%04o', $mode & 0o7777).'), and artisan would run its contents. '
                .'Remove it (it is rebuilt by php artisan optimize) and keep bootstrap/cache writable only by the runtime account: sudo chmod -R o-rwx bootstrap/cache.';
        }

        if ($owner === $effectiveUid || $owner === 0) {
            return null;
        }

        $runnerName = self::accountName($effectiveUid);

        return "{$path} belongs to {$ownerName} (uid {$owner}), not to {$runnerName} (uid {$effectiveUid}) running artisan: "
            ."loading it would run what {$ownerName} wrote with the rights of {$runnerName}. "
            ."Run artisan as the runtime account: sudo -u {$ownerName} sh -c 'umask 027 && php artisan …'. "
            .'Remove the file only if this account is to rebuild it on purpose.';
    }

    private static function accountName(int $uid): string
    {
        if (function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid($uid);

            if (is_array($entry) && is_string($entry['name'] ?? null)) {
                return $entry['name'];
            }
        }

        return 'uid '.$uid;
    }
}
