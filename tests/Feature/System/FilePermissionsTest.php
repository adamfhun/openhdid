<?php

use App\System\CheckStatus;
use App\System\FilePermissions;
use App\System\HealthChecks;
use Illuminate\Support\Str;

/**
 * A throwaway installation folder under /tmp, whose parents every account
 * may pass (the system temp folder of macOS is private to its owner), with
 * the given files and modes. Returns the folder; the caller removes it.
 *
 * @param  array<string, int>  $files  relative path => mode
 */
function installationTreeWithModes(array $files, int $folderMode = 0755): string
{
    $root = '/tmp/hdid-permissions-'.Str::random(10);

    foreach ($files as $path => $mode) {
        $folder = dirname("{$root}/{$path}");
        if (! is_dir($folder)) {
            mkdir($folder, 0755, true);
        }
        file_put_contents("{$root}/{$path}", 'APP_KEY=base64:secret');
        chmod("{$root}/{$path}", $mode);
    }
    chmod($root, $folderMode);

    return $root;
}

function removeInstallationTree(string $root): void
{
    chmod($root, 0755);
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}

/**
 * @return list<string>
 */
function exposedIn(string $root, string $mountInfo = '/nonexistent'): array
{
    $files = array_map(fn (string $path): string => "{$root}/{$path}", ['.env', 'bootstrap/cache/config.php', 'bootstrap/cache/packages.php', 'storage/logs/laravel-2026-10-25.log', 'storage/framework/sessions/abc']);

    return (new FilePermissions(array_values(array_filter($files, 'is_file')), $mountInfo))->exposed();
}

it('turns red when a file with secrets can be read by any local account, naming the files', function (): void {
    $root = installationTreeWithModes([
        '.env' => 0644,
        'bootstrap/cache/config.php' => 0644,
        'storage/logs/laravel-2026-10-25.log' => 0640,
        'storage/framework/sessions/abc' => 0604,
    ]);

    try {
        app()->instance(FilePermissions::class, new FilePermissions(array_map(fn (string $path): string => "{$root}/{$path}", ['.env', 'bootstrap/cache/config.php', 'storage/logs/laravel-2026-10-25.log', 'storage/framework/sessions/abc'])));
        $tile = collect(app(HealthChecks::class)->all())->firstWhere('key', 'file_permissions');

        expect($tile->status)->toBe(CheckStatus::Fail)
            ->and($tile->detail)->toContain('3 file(s)', '.env', 'bootstrap/cache/config.php', 'storage/framework/sessions/abc')
            ->and($tile->detail)->not->toContain('laravel-2026-10-25.log')
            ->and($tile->hint)->toContain("sudo -u www-data sh -c 'umask 027 && php artisan optimize'", 'chmod -R u+rwX,g+rwX,o-rwx storage bootstrap/cache', 'chmod 640 .env', 'deploy/php-fpm-umask.conf', 'chmod o-rwx storage/logs/*');
    } finally {
        removeInstallationTree($root);
    }
});

it('counts a world-readable file as safe when a folder above it lets no other account pass', function (): void {
    $root = installationTreeWithModes(['.env' => 0644, 'bootstrap/cache/config.php' => 0644], folderMode: 0750);

    try {
        expect(exposedIn($root))->toBe([]);

        chmod($root, 0755);
        chmod("{$root}/bootstrap/cache", 0750);
        expect(exposedIn($root))->toBe(["{$root}/.env"]);
    } finally {
        removeInstallationTree($root);
    }
});

it('is green when the files are kept to their owner and group, as with umask 027', function (): void {
    $root = installationTreeWithModes([
        '.env' => 0640,
        'bootstrap/cache/config.php' => 0640,
        'bootstrap/cache/packages.php' => 0640,
        'storage/logs/laravel-2026-10-25.log' => 0640,
        'storage/framework/sessions/abc' => 0600,
    ]);

    try {
        expect(exposedIn($root))->toBe([]);

        app()->instance(FilePermissions::class, new FilePermissions([]));
        expect(app(HealthChecks::class)->filePermissions()->status)->toBe(CheckStatus::Ok);
    } finally {
        removeInstallationTree($root);
    }
});

it('leaves out the read-only files of a container image but checks a writable volume below it', function (): void {
    $root = installationTreeWithModes(['bootstrap/cache/packages.php' => 0644, 'storage/logs/laravel-2026-10-25.log' => 0644]);
    $mountInfo = tempnam(sys_get_temp_dir(), 'mountinfo');

    try {
        // As in a container with read_only: true: the root is read-only, the storage volume is not.
        // The kernel lists real paths (on macOS /tmp is a link to /private/tmp).
        $realRoot = realpath($root);
        file_put_contents($mountInfo, implode("\n", [
            '1 0 0:1 / / ro,relatime - overlay overlay ro',
            "2 1 0:2 / {$realRoot}/storage rw,relatime - ext4 /dev/sda1 rw",
            '3 1 0:3 / /run rw,nosuid - tmpfs tmpfs rw',
        ])."\n");

        expect(exposedIn($root, $mountInfo))->toBe(["{$root}/storage/logs/laravel-2026-10-25.log"])
            ->and(exposedIn($root))->toHaveCount(2);
    } finally {
        @unlink($mountInfo);
        removeInstallationTree($root);
    }
});

it('looks at the .env, the framework caches, the logs and the session files of the installation', function (): void {
    $files = FilePermissions::installationFiles();

    expect($files)->toContain(base_path('.env'), app()->getCachedConfigPath())
        ->and(collect($files)->contains(fn (string $file): bool => str_starts_with(basename($file), '.') && $file !== base_path('.env')))->toBeFalse();
});

it('leaves a local development checkout alone, whose files are the developer\'s own', function (): void {
    app()->instance(FilePermissions::class, new FilePermissions([base_path('composer.json')]));
    app()->detectEnvironment(fn (): string => 'local');

    try {
        $check = app(HealthChecks::class)->filePermissions();

        expect($check->status)->toBe(CheckStatus::Info)
            ->and($check->detail)->toContain('APP_ENV=local');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

it('follows a symlinked folder to where the file really lies, whose folders decide who can reach it', function (): void {
    // The installation is closed (0750), but its logs folder links to an open one elsewhere.
    $root = installationTreeWithModes(['.env' => 0640], folderMode: 0750);
    $outside = installationTreeWithModes(['laravel.log' => 0644]);
    mkdir("{$root}/storage", 0750);
    symlink($outside, "{$root}/storage/logs");

    // An open link into a closed folder: the file stays out of reach.
    $closed = installationTreeWithModes(['config.php' => 0644], folderMode: 0700);
    $open = installationTreeWithModes(['readme.txt' => 0644]);
    symlink($closed, "{$open}/cache");

    try {
        expect((new FilePermissions(["{$root}/storage/logs/laravel.log"], '/nonexistent'))->exposed())->toBe(["{$root}/storage/logs/laravel.log"])
            ->and((new FilePermissions(["{$open}/cache/config.php"], '/nonexistent'))->exposed())->toBe([]);
    } finally {
        unlink("{$root}/storage/logs");
        unlink("{$open}/cache");
        removeInstallationTree($root);
        removeInstallationTree($outside);
        removeInstallationTree($closed);
        removeInstallationTree($open);
    }
});
