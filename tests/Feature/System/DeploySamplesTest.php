<?php

use Illuminate\Support\Facades\Log;

/**
 * The traditional (non-container) systemd units shipped under deploy/.
 *
 * @return list<string>
 */
function deploySampleUnits(): array
{
    $units = array_merge(glob(base_path('deploy/*.service')) ?: [], glob(base_path('deploy/*.timer')) ?: []);
    sort($units);

    return $units;
}

/**
 * Every traditional deploy sample that an operator copies onto the server.
 *
 * @return list<string>
 */
function deploySampleFiles(): array
{
    return array_merge(deploySampleUnits(), glob(base_path('deploy/*.cron')) ?: [], glob(base_path('deploy/*.conf')) ?: []);
}

/**
 * The effective `Key=Value` directives of a systemd unit (a later assignment wins).
 *
 * @return array<string, string>
 */
function deploySampleDirectives(string $unit): array
{
    $directives = [];

    foreach (preg_split('/\R/', (string) file_get_contents($unit)) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $directives[trim($key)] = trim($value);
    }

    return $directives;
}

describe('traditional deploy samples', function (): void {
    beforeEach(function (): void {
        // The OpenHDID export ships the tests without deploy/ (its samples are the container files).
        if (! is_dir(base_path('deploy'))) {
            $this->markTestSkipped('The traditional deploy samples are not part of this tree.');
        }
    });

    it('ships the traditional systemd and cron samples', function (): void {
        expect(array_map('basename', deploySampleUnits()))->toContain('hdid-queue.service', 'hdid-queue-sync.service')
            ->and(is_file(base_path('deploy/hdid-scheduler.cron')))->toBeTrue()
            ->and(is_file(base_path('deploy/php-fpm-umask.conf')))->toBeTrue();
    });

    it('gives PHP-FPM the same umask as the workers with a drop-in that changes nothing else', function (): void {
        $dropIn = base_path('deploy/php-fpm-umask.conf');
        $text = (string) file_get_contents($dropIn);
        $directives = deploySampleDirectives($dropIn);

        // A drop-in only adds to the packaged unit: a [Service] section with the umask alone.
        expect($directives)->toBe(['UMask' => '0027'])
            ->and($text)->toMatch('/^\[Service\]$/m')
            ->and($text)->not->toMatch('/^\[(Unit|Install)\]$/m')
            ->and($text)->toContain('/etc/systemd/system/php8.5-fpm.service.d/', 'systemctl daemon-reload', 'systemctl show php8.5-fpm -p UMask');
    });

    it('keeps each worker below its memory ceiling, restarting it softly before PHP would stop a job', function (): void {
        foreach (['hdid-queue.service' => 384, 'hdid-queue-sync.service' => 448] as $name => $ceiling) {
            $directives = deploySampleDirectives(base_path("deploy/{$name}"));
            preg_match('/--memory=(\d+)/', $directives['ExecStart'], $soft);

            // memory_limit 256M (the installation guide's php.ini) + about 22 MB of the process itself.
            expect((int) $soft[1])->toBeLessThan(256, $name)
                ->and($directives['MemoryMax'] ?? null)->toBe("{$ceiling}M", $name)
                ->and($ceiling)->toBeGreaterThanOrEqual(256 + 22, $name);
        }
    });

    it('sends the worker output to the journal, never to a file systemd would open as root in the writable app tree', function (): void {
        foreach (deploySampleUnits() as $unit) {
            $name = basename($unit);
            $directives = deploySampleDirectives($unit);

            expect(file_get_contents($unit))->not->toMatch('/^\s*Standard(Output|Error)\s*=\s*(append|file|truncate):/m', $name);

            if (! isset($directives['ExecStart'])) {
                continue;
            }

            expect($directives['StandardOutput'] ?? null)->toBe('journal', "{$name} StandardOutput")
                ->and($directives['StandardError'] ?? null)->toBe('journal', "{$name} StandardError")
                ->and($directives['SyslogIdentifier'] ?? null)->toBe(basename($unit, '.service'), "{$name} SyslogIdentifier");
        }
    });

    it('creates owner and group only files from every sample that runs the application', function (): void {
        foreach (deploySampleUnits() as $unit) {
            $directives = deploySampleDirectives($unit);

            if (isset($directives['ExecStart'])) {
                expect($directives['UMask'] ?? null)->toBe('0027', basename($unit).' UMask');
            }
        }

        $cronLines = array_filter(
            preg_split('/\R/', (string) file_get_contents(base_path('deploy/hdid-scheduler.cron'))),
            fn (string $line): bool => $line !== '' && ! str_starts_with(ltrim($line), '#') && str_contains($line, 'php artisan'),
        );

        expect($cronLines)->not->toBeEmpty();

        foreach ($cronLines as $line) {
            expect(str_contains((string) strstr($line, 'php artisan', true), 'umask 027'))->toBeTrue($line);
        }
    });

    it('runs artisan only as the runtime account in the deploy samples, never as root or the installer', function (): void {
        foreach (deploySampleUnits() as $unit) {
            $directives = deploySampleDirectives($unit);

            if (isset($directives['ExecStart'])) {
                expect($directives['User'] ?? null)->toBe('www-data', basename($unit).' User')
                    ->and($directives['Group'] ?? null)->toBe('www-data', basename($unit).' Group');
            }
        }

        expect(file_get_contents(base_path('deploy/hdid-scheduler.cron')))->toContain('crontab -u www-data');

        foreach (deploySampleFiles() as $file) {
            foreach (preg_split('/\R/', (string) file_get_contents($file)) as $line) {
                if (! str_starts_with(ltrim($line), '#') || ! str_contains($line, 'php artisan')) {
                    continue;
                }

                $command = (string) strstr($line, 'php artisan', true);

                expect(str_contains($command, 'sudo -u www-data'))->toBeTrue(basename($file).': '.$line)
                    ->and(str_contains($command, 'umask 027'))->toBeTrue(basename($file).': '.$line);
            }
        }
    });
});

it('writes the file log channels without any access for other accounts, whatever the umask', function (): void {
    $directory = sys_get_temp_dir().'/hdid-log-permission-'.bin2hex(random_bytes(4));
    $previousUmask = umask(0022);

    try {
        foreach (['single', 'daily', 'monthly'] as $channel) {
            expect(config("logging.channels.{$channel}.permission"))->toBe(0640, $channel);

            config()->set("logging.channels.{$channel}.path", "{$directory}/{$channel}.log");
            config()->set("logging.channels.{$channel}.level", 'debug');
            Log::forgetChannel($channel);
            Log::channel($channel)->warning('File permission probe.');
            Log::forgetChannel($channel);
        }

        $files = glob("{$directory}/*.log") ?: [];

        expect($files)->toHaveCount(3);

        foreach ($files as $file) {
            clearstatcache(true, $file);

            expect(fileperms($file) & 0777)->toBe(0640, basename($file));
        }
    } finally {
        umask($previousUmask);
        array_map('unlink', glob("{$directory}/*") ?: []);
        @rmdir($directory);
    }
});
