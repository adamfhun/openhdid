<?php

use App\System\CacheOwnership;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
 * artisan loads bootstrap/cache/*.php on every start, so a cache file the
 * runtime account (www-data) wrote must not run with the rights of the
 * installer or root (G44b). artisan stops before loading such a file.
 */

function cacheOwnershipFile(): string
{
    $dir = sys_get_temp_dir().'/hdid-cache-owner-'.Str::random(10);
    mkdir($dir);
    $path = $dir.'/services.php';
    file_put_contents($path, "<?php return [];\n");
    chmod($path, 0o644);

    return $path;
}

it('lets the owner of a cache file, and anybody below root-owned files, load it', function (): void {
    $path = cacheOwnershipFile();
    $me = fileowner($path);

    try {
        expect(CacheOwnership::problemWith($path, $me))->toBeNull()
            ->and(CacheOwnership::problemWith($path.'.missing', $me + 1))->toBeNull();
    } finally {
        @unlink($path);
        @rmdir(dirname($path));
    }
});

it('stops an account that would load a cache file another account wrote', function (): void {
    $path = cacheOwnershipFile();
    $me = fileowner($path);
    expect($me)->not->toBe(0, 'the test needs a non-root owner');

    try {
        // Root, or another account such as the installer, running artisan over the runtime account's file.
        foreach ([0, $me + 1] as $runner) {
            expect(CacheOwnership::problemWith($path, $runner))
                ->toContain('belongs to')
                ->toContain('Run artisan as the runtime account: sudo -u ');
        }
    } finally {
        @unlink($path);
        @rmdir(dirname($path));
    }
});

it('stops artisan before it loads a cache file anybody may write', function (): void {
    $path = cacheOwnershipFile();
    $marker = dirname($path).'/ran';
    // What a planted line would do: run code, then hand the real manifest back.
    file_put_contents($path, '<?php file_put_contents('.var_export($marker, true).', "x"); return [];'."\n");
    chmod($path, 0o666);

    try {
        expect(CacheOwnership::problemWith($path, fileowner($path)))->toContain('may be written by anybody');

        $result = Process::path(base_path())->env(['APP_SERVICES_CACHE' => $path])->timeout(60)->run([PHP_BINARY, 'artisan', '--version']);

        expect($result->exitCode())->toBe(78)
            ->and($result->errorOutput())->toContain('may be written by anybody')
            ->and(is_file($marker))->toBeFalse('the planted code never ran');

        chmod($path, 0o644);
        $result = Process::path(base_path())->env(['APP_SERVICES_CACHE' => $path])->timeout(60)->run([PHP_BINARY, 'artisan', '--version']);
        expect($result->exitCode())->toBe(0, 'its own file is loaded as before')
            ->and(is_file($marker))->toBeTrue();
    } finally {
        @unlink($marker);
        @unlink($path);
        @rmdir(dirname($path));
    }
});
