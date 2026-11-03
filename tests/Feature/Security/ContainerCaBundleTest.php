<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * The container trusts the extra CA files mounted at /etc/openhdid/ca
 * (prepare_ca_bundle in docker/openhdid). Kubernetes mounts a ConfigMap as
 * symbolic links (name -> ..data/name -> ..<timestamp>/name), Compose as
 * plain files; both must be taken, and a file that cannot be used is named.
 */

/**
 * The container's entry script: under deploy/openhdid/repo in this
 * repository, at the root of the published OpenHDID tree.
 */
function containerEntryScript(): string
{
    foreach (['deploy/openhdid/repo/docker/openhdid', 'docker/openhdid'] as $path) {
        if (is_file(base_path($path))) {
            return base_path($path);
        }
    }

    test()->markTestSkipped('The container entry script is not part of this tree.');
}

/**
 * Runs prepare_ca_bundle of the entry script on a temporary CA folder.
 *
 * @return array{result: ProcessResult, bundle: ?string, ini: bool}
 */
function runCaBundle(string $caDir): array
{
    $script = (string) file_get_contents(containerEntryScript());
    preg_match('/^log\(\) \{[^\n]*\n/m', $script, $log);
    preg_match('/^prepare_ca_bundle\(\) \{.*?^\}\n/ms', $script, $function);
    // The paths as variables, so the function can run outside the image.
    $function = str_replace(['/etc/openhdid/ca', '/etc/ssl/certs/ca-certificates.crt'], ['$CA_DIR', '$SYSTEM_CA'], $function[0]);

    $runtime = sys_get_temp_dir().'/hdid-ca-runtime-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($runtime.'/php');
    file_put_contents($runtime.'/system.pem', 'SYSTEM CA');

    $result = Process::env(['CA_DIR' => $caDir, 'SYSTEM_CA' => $runtime.'/system.pem', 'RUNTIME' => $runtime])
        ->run(['sh', '-c', "set -eu\n".$log[0].$function.'prepare_ca_bundle']);

    $bundle = is_file($runtime.'/ca-bundle.pem') ? (string) file_get_contents($runtime.'/ca-bundle.pem') : null;
    $ini = is_file($runtime.'/php/zz-ca.ini');
    File::deleteDirectory($runtime);

    return ['result' => $result, 'bundle' => $bundle, 'ini' => $ini];
}

function caFolder(): string
{
    $dir = sys_get_temp_dir().'/hdid-ca-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($dir);

    return $dir;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/hdid-ca-*') ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

it('trusts the CA files of a Kubernetes ConfigMap mounted as symbolic links', function (): void {
    $dir = caFolder();
    File::ensureDirectoryExists($dir.'/..2026_11_02_10_00_00.123');
    file_put_contents($dir.'/..2026_11_02_10_00_00.123/internal.crt', "-----BEGIN CERTIFICATE-----\nINTERNAL\n-----END CERTIFICATE-----\n");
    symlink('..2026_11_02_10_00_00.123', $dir.'/..data');
    symlink('..data/internal.crt', $dir.'/internal.crt');

    $run = runCaBundle($dir);

    expect($run['result']->exitCode())->toBe(0, $run['result']->errorOutput())
        ->and(substr_count((string) $run['bundle'], 'INTERNAL'))->toBe(1)
        ->and($run['bundle'])->toStartWith('SYSTEM CA')
        ->and($run['ini'])->toBeTrue()
        ->and($run['result']->errorOutput())->toContain('trusting 1 extra CA file(s) from '.$dir)
        ->and($run['result']->errorOutput())->not->toContain('warning');
});

it('names the files of the CA folder it cannot use', function (): void {
    $dir = caFolder();
    file_put_contents($dir.'/internal.cer', 'DER');
    symlink('..data/gone.pem', $dir.'/gone.pem');

    $run = runCaBundle($dir);

    expect($run['result']->exitCode())->toBe(0)
        ->and($run['result']->errorOutput())->toContain('warning:', 'internal.cer', 'gone.pem')
        ->and($run['bundle'])->toBeNull()
        ->and($run['ini'])->toBeFalse();
});

it('keeps CA files that lack a final newline apart in the bundle', function (): void {
    $dir = caFolder();
    file_put_contents($dir.'/a.pem', "-----BEGIN CERTIFICATE-----\nA\n-----END CERTIFICATE-----");
    file_put_contents($dir.'/b.pem', "-----BEGIN CERTIFICATE-----\nB\n-----END CERTIFICATE-----");

    $run = runCaBundle($dir);

    expect($run['bundle'])->toContain("SYSTEM CA\n-----BEGIN CERTIFICATE-----\nA")
        ->and($run['bundle'])->toContain("-----END CERTIFICATE-----\n-----BEGIN CERTIFICATE-----\nB")
        ->and($run['result']->errorOutput())->toContain('trusting 2 extra CA file(s)');
});

it('takes a CA file whose name contains a space', function (): void {
    $dir = caFolder();
    file_put_contents($dir.'/internal ca.crt', "-----BEGIN CERTIFICATE-----\nSPACED\n-----END CERTIFICATE-----\n");

    $run = runCaBundle($dir);

    expect($run['result']->exitCode())->toBe(0, $run['result']->errorOutput())
        ->and($run['bundle'])->toContain('SPACED');
});

it('logs nothing and builds no bundle for an empty or missing CA folder', function (string $which): void {
    $dir = $which === 'empty' ? caFolder() : sys_get_temp_dir().'/hdid-ca-missing-'.bin2hex(random_bytes(4));

    $run = runCaBundle($dir);

    expect($run['result']->exitCode())->toBe(0)
        ->and($run['result']->errorOutput())->toBe('')
        ->and($run['bundle'])->toBeNull()
        ->and($run['ini'])->toBeFalse();
})->with(['empty', 'missing']);
