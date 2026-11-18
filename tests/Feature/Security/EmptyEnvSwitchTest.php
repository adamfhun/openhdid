<?php

/**
 * A security switch is only turned off by an explicit false. An empty line in the
 * .env (KEY=), "empty" or a garbled value keeps the default, the way the container
 * start check reads it too; before 1.15.5 an empty line turned these off.
 */
dataset('security switches', [
    'secure session cookie' => ['session', 'SESSION_SECURE_COOKIE', fn (array $c) => $c['secure']],
    'HttpOnly session cookie' => ['session', 'SESSION_HTTP_ONLY', fn (array $c) => $c['http_only']],
    'content security policy' => ['hdid', 'HDID_CSP_ENABLED', fn (array $c) => $c['security']['csp_enabled']],
    'stable SSO subject' => ['hdid', 'HDID_SSO_REQUIRE_SUBJECT', fn (array $c) => $c['oidc']['require_subject']],
    'EMD record for client logins' => ['hdid', 'CLIENT_LOGIN_REQUIRE_EMD', fn (array $c) => $c['client_login']['require_emd']],
]);

it('keeps a security switch on in production unless it is explicitly false', function (string $file, string $key, Closure $read): void {
    $value = fn (string $raw): mixed => $read(configFileWithEnv($file, ['APP_ENV' => 'production', $key => $raw]));

    foreach (['', 'empty', 'garbage', 'true', '1', 'on', 'yes'] as $raw) {
        expect($value($raw))->toBeTrue("{$key}={$raw}");
    }

    foreach (['false', '0', 'off', 'no'] as $raw) {
        expect($value($raw))->toBeFalse("{$key}={$raw}");
    }
})->with('security switches');

it('keeps the defaults that depend on the environment when the line is empty', function (): void {
    expect(configFileWithEnv('session', ['APP_ENV' => 'local', 'SESSION_SECURE_COOKIE' => ''])['secure'])->toBeFalse()
        ->and(configFileWithEnv('hdid', ['APP_ENV' => 'local', 'HDID_CSP_ENABLED' => ''])['security']['csp_enabled'])->toBeFalse()
        ->and(configFileWithEnv('session', ['APP_ENV' => 'local', 'SESSION_SECURE_COOKIE' => 'true'])['secure'])->toBeTrue();
});
