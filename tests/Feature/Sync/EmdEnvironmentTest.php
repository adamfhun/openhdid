<?php

use App\System\HealthChecks;

/**
 * Evaluates config/hdid.php against exactly the given EMD variables, as a
 * fresh boot would; every other SYNC_* / EMD_SYNC_* value of the local .env
 * is hidden meanwhile and restored afterwards.
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function emdConfigWith(array $env): array
{
    $names = collect(['CRON', 'TOKEN_KEEP_ALIVE', 'API_URL', 'API_TOKEN', 'API_TIMEOUT', 'DRIVER', 'LOGIN_URL', 'REFRESH_URL',
        'USERNAME', 'PASSWORD', 'AUTH_TIMEOUT', 'EXPORT_FORMAT', 'EXPORT_TIMEOUT', 'CSV_DELIMITER'])
        ->flatMap(fn (string $name): array => ['SYNC_'.$name, 'EMD_SYNC_'.$name])->all();
    $saved = collect($names)->mapWithKeys(fn (string $name): array => [$name => [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)]])->all();

    $apply = function (string $name, mixed $value): void {
        if ($value === null || $value === false) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }
        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    };

    try {
        foreach ($names as $name) {
            $apply($name, null);
        }
        foreach ($env as $name => $value) {
            $apply($name, $value);
        }

        return (fn (): array => require config_path('hdid.php'))()['sync'];
    } finally {
        foreach ($saved as $name => [$envValue, $serverValue, $processValue]) {
            $apply($name, null);
            if ($envValue !== null) {
                $_ENV[$name] = $envValue;
            }
            if ($serverValue !== null) {
                $_SERVER[$name] = $serverValue;
            }
            if ($processValue !== false) {
                putenv($name.'='.$processValue);
            }
        }
    }
}

it('reads the EMD_SYNC_* names and still accepts the former SYNC_* names', function (): void {
    $sync = emdConfigWith([
        'EMD_SYNC_LOGIN_URL' => 'https://emd.test/login',
        'SYNC_LOGIN_URL' => 'https://old.test/login',
        'SYNC_API_URL' => 'https://old.test/export',
        // A blank line copied from .env.example must not hide the live value.
        'EMD_SYNC_USERNAME' => '',
        'SYNC_USERNAME' => 'legacy-user',
        'SYNC_CRON' => '0 * * * *',
    ]);

    expect($sync)->toMatchArray([
        'login_url' => 'https://emd.test/login',
        'api_url' => 'https://old.test/export',
        'username' => 'legacy-user',
        'cron' => '0 * * * *',
        'driver' => 'spreadsheet',
        'export_format' => 'xlsx',
        'legacy_env' => ['SYNC_CRON', 'SYNC_API_URL', 'SYNC_USERNAME'],
    ]);
});

it('uses the defaults and reports no former names without any EMD variable', function (): void {
    expect(emdConfigWith([]))->toMatchArray([
        'cron' => null,
        'token_keep_alive' => true,
        'driver' => 'spreadsheet',
        'auth_timeout' => 20,
        'csv_delimiter' => ',',
        'legacy_env' => [],
    ]);
});

it('reads the token keep-alive switch like a boolean', function (?string $value, bool $expected): void {
    $env = $value === null ? [] : ['EMD_SYNC_TOKEN_KEEP_ALIVE' => $value];

    expect(emdConfigWith($env)['token_keep_alive'])->toBe($expected);
})->with([
    'unset' => [null, true],
    'true' => ['true', true],
    'on' => ['on', true],
    '1' => ['1', true],
    'false' => ['false', false],
    'off' => ['off', false],
    'no' => ['no', false],
    '0' => ['0', false],
    'unreadable keeps the default' => ['maybe', true],
]);

it('notes former variable names on the EMD sync tile without changing its status', function (): void {
    $checks = app(HealthChecks::class);
    $before = $checks->syncApi();
    config()->set('hdid.sync.legacy_env', ['SYNC_API_URL', 'SYNC_PASSWORD']);

    $after = $checks->syncApi();

    expect($after->status)->toBe($before->status)
        ->and($after->detail)->toContain('SYNC_API_URL, SYNC_PASSWORD')
        ->and($before->detail)->not->toContain('SYNC_API_URL');
});
