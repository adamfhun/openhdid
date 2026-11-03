<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * A backup set may come back from storage outside the host, and the restore
 * loads its dump into the bundled MariaDB. Sandbox mode refuses the dump's
 * client commands (\! shell, source, tee, pager), but not LOAD DATA LOCAL
 * INFILE, with which the mariadb client itself sends a file of the container
 * (its environment holds the migration and the root password) to the server.
 * The client switch --local-infile=0 and the server's local_infile=0 refuse
 * it; neither guards against the SQL itself, which the trusted storage does.
 */

beforeEach(function (): void {
    if (openhdidReleaseFile('backup.sh') === null || openhdidReleaseFile('compose.yaml') === null) {
        $this->markTestSkipped('The container release files are not part of this tree.');
    }
});

it('runs every mariadb client that reads a dump or generated statements with the sandbox and local file reads off', function (): void {
    $script = (string) openhdidReleaseFile('backup.sh');

    // Every client invocation (the service name stands alone; mariadb-dump is another program).
    preg_match_all('/(?<![\w-])mariadb\s+-[^\n]*/', $script, $calls);

    expect($calls[0])->toHaveCount(5);
    foreach ($calls[0] as $call) {
        expect($call)->toStartWith('mariadb --sandbox --local-infile=0 -u"$u"');
    }

    // The load of the dump itself.
    expect($script)->toContain('exec mariadb --sandbox --local-infile=0 -u"$u" "$db"\'');
});

it('escapes backticks in the table names of the pre-restore drop statement', function (): void {
    $script = (string) openhdidReleaseFile('backup.sh');

    // A table name with a backtick, left by a tampered dump, is doubled inside the quoted name.
    expect($script)->toContain('sed "s/\\`/\\`\\`/g; s/.*/DROP TABLE IF EXISTS \\`&\\`;/"')
        ->and($script)->not->toContain('sed "s/.*/DROP TABLE IF EXISTS');
});

it('says what the sandbox and the switches refuse, and that the trusted storage guards the rest', function (): void {
    $script = (string) openhdidReleaseFile('backup.sh');

    expect($script)->toContain('--local-infile=0', 'local_infile=0', 'LOAD DATA LOCAL')
        ->and($script)->not->toContain('a dump is data, its client commands (\! shell, file access) are refused');
});

it('switches LOAD DATA LOCAL off in every bundled database server configuration', function (): void {
    $compose = (string) openhdidReleaseFile('compose.yaml');
    $infra = (string) openhdidReleaseFile('profiles/10k/infra.yaml');

    // Through the service's command, not configs.content: a changed command makes a plain
    // "docker compose up -d" recreate the container, a changed configs.content does not.
    expect(composeBlock($compose, 'services', 'mariadb'))->toContain('command: [--local-infile=0]')
        ->and(composeBlock($infra, 'services', 'mariadb'))->toContain('command: [--local-infile=0]');

    // A usage profile that set its own command would drop the switch.
    foreach (['profiles/5k/compose.yaml', 'profiles/10k/single.yaml', 'profiles/10k/compose.yaml'] as $profile) {
        $text = openhdidReleaseFile($profile);
        if ($text !== null) {
            expect(composeBlock($text, 'services', 'mariadb'))->not->toContain('command:', $profile);
        }
    }
});

it('keeps the switch in the replica sample of the installer, whose override replaces the command', function (): void {
    $installer = (string) openhdidReleaseFile('install.sh');

    expect($installer)->toContain('command: [--local-infile=0, --server-id=1, --log-bin=mariadb-bin, --binlog-format=ROW]')
        // The replica's base is a dump too.
        ->and($installer)->toContain('exec mariadb --sandbox --local-infile=0 -uroot')
        ->and($installer)->not->toContain('exec mariadb -uroot');
});

it('archives a CA folder of symbolic links as plain files, so the restore check takes the backup', function (): void {
    $script = (string) openhdidReleaseFile('backup.sh');
    preg_match('/^config_archive\(\) \{.*?^\}\n/ms', $script, $archive);
    preg_match('/^check_config_archive\(\) \{.*?^\}\n/ms', $script, $check);
    $dir = sys_get_temp_dir().'/hdid-backup-ca-'.bin2hex(random_bytes(4));
    mkdir($dir.'/ca/..2026_01_01_00_00_00.000000000', 0o755, true);
    foreach (['.env', 'db.env', 'compose.yaml'] as $file) {
        file_put_contents($dir.'/'.$file, "# {$file}\n");
    }
    // A Kubernetes ConfigMap layout, and a CA linked in from elsewhere on the host.
    file_put_contents($dir.'/ca/..2026_01_01_00_00_00.000000000/internal-ca.crt', "-----BEGIN CERTIFICATE-----\n");
    symlink('..2026_01_01_00_00_00.000000000', $dir.'/ca/..data');
    symlink('..data/internal-ca.crt', $dir.'/ca/internal-ca.crt');
    file_put_contents($dir.'/host-ca.crt', "-----BEGIN CERTIFICATE-----\n");
    symlink($dir.'/host-ca.crt', $dir.'/ca/host-ca.crt');
    // The container user reads the CA files: the modes travel with the backup.
    chmod($dir.'/ca/..2026_01_01_00_00_00.000000000/internal-ca.crt', 0o644);
    chmod($dir.'/host-ca.crt', 0o644);
    chmod($dir.'/ca', 0o755);
    chmod($dir.'/.env', 0o600);

    try {
        $result = Process::path($dir)->run(['bash', '-c', 'set -euo pipefail; die() { echo "DIE: $*"; exit 1; }; '
            .$archive[0].$check[0].'mkdir -m 700 work && config_archive work > config.tar.gz && [ ! -e work/.config ] && check_config_archive config.tar.gz && tar tvzf config.tar.gz']);

        expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
            ->and($result->output())->not->toContain('DIE:')
            ->and($result->output())->toContain('ca/host-ca.crt', 'ca/internal-ca.crt')
            ->and(preg_grep('/^l/', explode("\n", $result->output())))->toBe([])
            ->and($result->output())->toMatch('#^-rw-r--r-- .* ca/internal-ca\.crt$#m')
            ->and($result->output())->toMatch('#^-rw-r--r-- .* ca/host-ca\.crt$#m')
            ->and($result->output())->toMatch('#^drwxr-xr-x .* ca/?$#m')
            ->and($result->output())->toMatch('#^-rw------- .* \.env$#m');
    } finally {
        File::deleteDirectory($dir);
        @unlink($dir.'/host-ca.crt');
    }
});
