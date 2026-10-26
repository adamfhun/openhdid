<?php

namespace Tests\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * A throwaway installation folder for the OpenHDID installer (install.sh): the
 * script, the release files of this tree (for --from), and a bin folder in
 * front of PATH whose docker, kubectl, curl, nproc, free, df, ss, getent,
 * timedatectl, sleep and id are one fake (and sudo, exportfs, firewall-cmd,
 * ufw, git and the package managers too, so a call of them would show). The fake logs every call (name,
 * arguments, the length of the -e NAME variables, how many bytes it read on
 * its input) and answers by rules, so the installer runs without Docker and
 * every call it makes can be checked.
 *
 * The fake docker reads its whole input: a call the script does not feed from
 * /dev/null would swallow the operator's piped answers, and the test sees it.
 */
final class InstallerSandbox
{
    /**
     * The fake application key the fake image prints; split in two, so the secret scan of the
     * release export (gitleaks, laravel-app-key rule) does not take the literal for a real key.
     */
    public const FAKE_APP_KEY = 'base64:'.'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

    public string $root;

    /** The installation folder: install.sh lives here and works here. */
    public string $dir;

    /** The release files in the repository layout (the --from folder). */
    public string $release;

    public string $bin;

    /** @var array<string, string> */
    public array $env = [];

    /** @var list<string> */
    private array $rules = [];

    /** @var list<string> The rules of installed(): after the test's own, before the defaults. */
    private array $baseRules = [];

    private int $outputs = 0;

    /** @var list<self> */
    private static array $open = [];

    public function __construct()
    {
        $source = self::releaseRoot();
        // The real path: the script resolves its folder with pwd -P (macOS: /var is /private/var).
        $this->root = realpath(sys_get_temp_dir()).'/openhdid-installer-'.bin2hex(random_bytes(6));
        $this->dir = $this->root.'/opt/openhdid';
        $this->release = $this->root.'/release';
        $this->bin = $this->root.'/bin';
        foreach ([$this->dir, $this->release, $this->bin, $this->root.'/fake', $this->root.'/downloads'] as $folder) {
            mkdir($folder, 0700, true);
        }

        copy($source.'/install.sh', $this->dir.'/install.sh');
        chmod($this->dir.'/install.sh', 0755);
        foreach (['compose.yaml', '.env.example', 'db.env.example', 'backup.sh', 'UPGRADE.md', 'TROUBLESHOOTING.md'] as $file) {
            copy($source.'/'.$file, $this->release.'/'.$file);
        }
        File::copyDirectory($source.'/profiles', $this->release.'/profiles');
        File::copyDirectory($source.'/kubernetes', $this->release.'/kubernetes');

        file_put_contents($this->root.'/fake/fake', self::FAKE);
        chmod($this->root.'/fake/fake', 0755);
        // Also every system command the installer must never run itself (it only prints them).
        foreach ([...self::FAKED, 'sudo', 'exportfs', 'firewall-cmd', 'ufw', 'showmount', 'systemctl', 'sysctl', 'usermod', 'apt', 'apt-get', 'dnf', 'yum', 'git'] as $name) {
            symlink($this->root.'/fake/fake', $this->bin.'/'.$name);
        }
        touch($this->root.'/fake/log');

        self::$open[] = $this;
    }

    /**
     * The release files: under deploy/openhdid/repo in this repository, the
     * root of an exported OpenHDID tree.
     */
    public static function releaseRoot(): string
    {
        foreach ([base_path('deploy/openhdid/repo'), base_path()] as $candidate) {
            if (is_file($candidate.'/install.sh') && is_file($candidate.'/compose.yaml') && is_dir($candidate.'/profiles')) {
                return $candidate;
            }
        }

        test()->markTestSkipped('The container release files are not part of this tree.');
    }

    public static function cleanUpAll(): void
    {
        foreach (self::$open as $sandbox) {
            if (is_dir($sandbox->root)) {
                // Files a test made unwritable would stop the removal.
                Process::run(['chmod', '-R', 'u+rwX', $sandbox->root]);
                File::deleteDirectory($sandbox->root);
            }
        }
        self::$open = [];
    }

    /**
     * Drops the rules a test gave so far (a second run of the installer that answers differently).
     */
    public function resetRules(): self
    {
        $this->rules = [];

        return $this;
    }

    /**
     * An answer of the fake: the first rule whose glob matches the whole call
     * ("docker compose run *check-env*") gives the exit code and the output;
     * with several outputs the n-th matching call gets the n-th (the last one
     * repeats).
     *
     * @param  string|list<string>  $output
     */
    public function rule(string $glob, int $exit = 0, string|array $output = '', string $stderr = ''): self
    {
        $outputs = is_array($output) ? $output : [$output];
        $base = $this->root.'/fake/out-'.(++$this->outputs);
        foreach (array_values($outputs) as $index => $text) {
            file_put_contents($base.'.'.($index + 1), $text);
        }
        file_put_contents($base.'.count', (string) count($outputs));
        if ($stderr !== '') {
            file_put_contents($base.'.err', $stderr);
        }
        $this->rules[] = $glob."\t".$exit."\t".$base;

        return $this;
    }

    /**
     * A healthy host: Docker 29 with Compose 2.40, rootful, 8 CPUs, 32 GB, a
     * large disk, a synchronised clock, nothing on the ports, no containers or
     * volumes; the fake image makes the secrets and the container checks pass.
     */
    private function defaultRules(): array
    {
        $saved = $this->rules;
        $this->rules = [];
        $this->rule('docker version --format*', 0, "29.0.0\n")
            ->rule('docker compose version*', 0, "2.40.0\n")
            ->rule('docker info --format*SecurityOptions*', 0, "[name=seccomp,profile=builtin name=cgroupns]\n")
            ->rule('docker info --format*DockerRootDir*', 0, "/var/lib/docker\n")
            ->rule('docker info --format*Proxy*', 0, "\n")
            ->rule('docker info', 0, "Server Version: 29.0.0\n")
            ->rule('docker version', 0, "Client: Docker Engine - Community\n")
            ->rule('docker compose run *tls-check*', 0, "subject: hdid.example.org; issuer: Example CA\nvalid until 2027-01-01 00:00 UTC (60 days)\n")
            ->rule('kubectl kustomize*', 0, "apiVersion: v1\nkind: List\n");
        $defaults = $this->rules;
        $this->rules = $saved;

        return $defaults;
    }

    /**
     * The value the fake image prints for a secret name.
     */
    public static function fakeSecret(string $name): string
    {
        if ($name === 'APP_KEY') {
            return self::FAKE_APP_KEY;
        }

        return substr(str_pad(bin2hex('fake-'.$name), 48, 'a'), 0, 48);
    }

    /** The passwords of installed(). */
    public const DB_PASSWORD = 'runtime-db-password-0123456789abcdef';

    public const REDIS_PASSWORD = 'valkey-password-0123456789abcdef0123';

    public const MIGRATE_PASSWORD = 'migrate-db-password-0123456789abcdef';

    public const ROOT_PASSWORD = 'root-db-password-0123456789abcdef012';

    /**
     * A finished 5k installation as the installer leaves it (compose.yaml, the
     * profile file, backup.sh, .env from env.example, db.env, .install/state,
     * the release's env.example and SHA256SUMS, a fresh backup), all of it two
     * hours old, and the answers of a healthy running stack started an hour ago.
     * A test overrides a value with $env and a call with rule().
     *
     * @param  array<string, string>  $env
     */
    public function installed(array $env = [], string $profile = '5k', string $role = 'single'): self
    {
        $files = ['compose.yaml' => 'compose.yaml', 'backup.sh' => 'backup.sh', '.env.example' => 'env.example'];
        $composeFile = ['5k' => 'compose.yaml:profiles/5k/compose.yaml', '10k-single' => 'compose.yaml:profiles/10k/single.yaml', '10k-ha' => 'compose.yaml:profiles/10k/compose.yaml', 'trial' => ''][$profile];
        foreach ($files as $source => $target) {
            copy($this->release.'/'.$source, $this->dir.'/'.$target);
        }
        chmod($this->dir.'/backup.sh', 0755);
        File::copyDirectory($this->release.'/profiles', $this->dir.'/profiles');

        $values = [
            'OPENHDID_VERSION' => '1.15.0', 'COMPOSE_FILE' => $composeFile, 'COMPOSE_PROFILES' => 'db,redis',
            'OPENHDID_TLS' => 'off', 'TRUSTED_PROXIES' => '10.0.0.2', 'OPENHDID_MONITORING_ALLOW' => '10.0.0.2',
            'APP_ENV' => 'production', 'APP_KEY' => self::FAKE_APP_KEY, 'APP_URL' => 'https://hdid.example.org',
            'DB_PASSWORD' => self::DB_PASSWORD, 'REDIS_PASSWORD' => self::REDIS_PASSWORD,
            'MAIL_MAILER' => 'smtp', 'SMS_DRIVER' => 'ozeki',
            ...$env,
        ];
        $this->writeEnv('.env', (string) file_get_contents($this->release.'/.env.example'), $values);
        $this->writeEnv('db.env', (string) file_get_contents($this->release.'/db.env.example'), [
            'MARIADB_ROOT_PASSWORD' => self::ROOT_PASSWORD, 'DB_USERNAME' => 'openhdid_migrate', 'DB_PASSWORD' => self::MIGRATE_PASSWORD,
        ]);

        mkdir($this->dir.'/.install', 0700, true);
        file_put_contents($this->dir.'/.install/state', "installer_version=1.15.0\nprofile={$profile}\nrole={$role}\nproject=openhdid\nopenhdid_version=1.15.0\n");
        copy($this->release.'/.env.example', $this->dir.'/.install/env.example-1.15.0');
        $sums = [];
        foreach (['compose.yaml' => 'compose.yaml', 'backup.sh' => 'backup.sh', 'profiles/5k/compose.yaml' => 'profile-5k.yaml', 'profiles/10k/compose.yaml' => 'profile-10k.yaml', 'profiles/10k/single.yaml' => 'profile-10k-single.yaml'] as $file => $asset) {
            $sums[] = hash_file('sha256', $this->dir.'/'.$file).'  '.$asset;
        }
        file_put_contents($this->dir.'/.install/SHA256SUMS-1.15.0', implode("\n", $sums)."\n");
        mkdir($this->dir.'/backup/'.date('Y-m-d_His', time() - 3600), 0700, true);
        foreach (['tls', 'ca'] as $folder) {
            is_dir($this->dir.'/'.$folder) || mkdir($this->dir.'/'.$folder, 0755);
        }
        $this->age($this->dir, time() - 7200);
        $this->env['FAKE_GETENT'] = '192.0.2.10';

        $started = gmdate('Y-m-d\TH:i:s', time() - 3600).'.123456789Z';
        $services = $role === 'b' ? ['web', 'worker'] : ['web', 'worker', 'sync-worker', 'scheduler'];
        if ($profile !== '10k-ha') {
            array_push($services, 'mariadb', 'valkey', 'valkey-sessions');
        }
        $ps = ['migrate|exited||0|ghcr.io/adamfhun/openhdid:1.15.0'];
        $inspect = ["migrate|{$started}|false|0"];
        foreach ($services as $service) {
            $health = in_array($service, ['web', 'mariadb', 'valkey', 'valkey-sessions'], true) ? 'healthy' : '';
            $ps[] = "{$service}|running|{$health}|0|".(in_array($service, ['mariadb', 'valkey', 'valkey-sessions'], true) ? $service : 'ghcr.io/adamfhun/openhdid:1.15.0');
            $inspect[] = "{$service}|{$started}|false|0";
        }
        $health = '';
        foreach (['database' => 'Database', 'cache' => 'Cache', 'valkey' => 'Valkey / Redis', 'queue' => 'Queue', 'scheduler' => 'Scheduler', 'mail' => 'Mail'] as $key => $label) {
            $health .= "HEALTH\x1f{$key}\x1fok\x1f{$label}\x1fok\x1fhint of {$key}\n";
        }

        $saved = $this->rules;
        $this->rules = [];
        $this->rule('docker compose config --services*', 0, implode("\n", ['migrate', ...$services])."\n")
            ->rule('docker compose ps -a --format*', 0, implode("\n", $ps)."\n")
            ->rule('docker compose ps -a -q*', 0, "c1\nc2\nc3\n")
            ->rule('docker inspect *', 0, implode("\n", $inspect)."\n")
            ->rule('docker compose port web 8443*', 0, "0.0.0.0:443\n")
            ->rule('docker compose port web 8080*', 0, "0.0.0.0:80\n")
            ->rule('docker compose exec -T web sh -c *hdid:health*', 0, $health)
            ->rule('docker compose exec -T web php -r *"REDIS_PASSWORD"*', 0, self::REDIS_PASSWORD)
            ->rule('docker compose exec -T web php -r *"REDIS_SESSION_PASSWORD"*', 0, '')
            ->rule('docker compose exec * valkey valkey-cli*PING*', 0, "PONG\n")
            ->rule('docker compose exec * valkey-sessions valkey-cli*PING*', 0, "PONG\n")
            ->rule('docker compose exec * valkey valkey-cli*maxmemory-policy*', 0, "maxmemory-policy\nnoeviction\n")
            ->rule('docker compose exec * valkey-sessions valkey-cli*maxmemory-policy*', 0, "maxmemory-policy\nallkeys-lru\n")
            ->rule('docker compose exec -T web php artisan tinker*SHOW GRANTS*', 0, "GRANT\x1fGRANT SELECT, INSERT, UPDATE, DELETE ON `openhdid`.* TO `openhdid`@`%`\n")
            ->rule('docker compose exec -T scheduler php artisan tinker*', 0, '')
            ->rule('docker compose exec -T scheduler ps -o args*', 0, "PID   USER     COMMAND\nphp artisan schedule:work --no-interaction\n")
            ->rule('docker compose run --rm --no-deps -T migrate artisan migrate:status*', 0, "  Migration name .......... Batch / Status\n  2026_09_11_000000_create_users_table .... [1] Ran\n");
        $this->baseRules = $this->rules;
        $this->rules = $saved;

        return $this;
    }

    /**
     * Sets every path under $path to the given modification time.
     */
    public function age(string $path, int $time): void
    {
        touch($path, $time);
        if (is_dir($path)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
                touch($item->getPathname(), $time);
            }
        }
    }

    /**
     * An env file from a template: the given keys set (or appended), mode 600.
     *
     * @param  array<string, string>  $values
     */
    public function writeEnv(string $file, string $template, array $values): void
    {
        foreach ($values as $key => $value) {
            $line = $key.'='.$value;
            $count = 0;
            $template = (string) preg_replace('/^'.preg_quote($key, '/').'=.*$/m', str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $template, 1, $count);
            if ($count === 0) {
                $template .= $line."\n";
            }
        }
        file_put_contents($this->dir.'/'.$file, $template);
        chmod($this->dir.'/'.$file, 0600);
    }

    /**
     * Writes an answers file (600 unless told otherwise) and returns its path.
     *
     * @param  array<string, string>  $values
     */
    public function answers(array $values, string $name = 'answers.txt', int $mode = 0600, ?string $folder = null): string
    {
        $path = ($folder ?? $this->root).'/'.$name;
        $lines = ['# openhdid-answers 1'];
        foreach ($values as $key => $value) {
            $lines[] = $key.'='.$value;
        }
        file_put_contents($path, implode("\n", $lines)."\n");
        chmod($path, $mode);

        return $path;
    }

    /**
     * The release files in the release layout (env.example, profile-5k.yaml …)
     * with a SHA256SUMS, for --from or the fake curl.
     */
    public function releaseAssets(string $folder = 'downloads'): string
    {
        $target = $this->root.'/'.$folder;
        if (! is_dir($target)) {
            mkdir($target, 0700, true);
        }
        $map = [
            'compose.yaml' => 'compose.yaml', 'env.example' => '.env.example', 'db.env.example' => 'db.env.example',
            'backup.sh' => 'backup.sh', 'UPGRADE.md' => 'UPGRADE.md', 'TROUBLESHOOTING.md' => 'TROUBLESHOOTING.md',
            'profile-5k.yaml' => 'profiles/5k/compose.yaml', 'profile-10k.yaml' => 'profiles/10k/compose.yaml',
            'profile-10k-single.yaml' => 'profiles/10k/single.yaml', 'profile-10k-infra.yaml' => 'profiles/10k/infra.yaml',
        ];
        $sums = [];
        foreach ($map as $asset => $source) {
            copy($this->release.'/'.$source, $target.'/'.$asset);
            $sums[] = hash_file('sha256', $target.'/'.$asset).'  '.$asset;
        }
        file_put_contents($target.'/SHA256SUMS', implode("\n", $sums)."\n");

        return $target;
    }

    /**
     * Runs install.sh in the installation folder with the fakes in front of PATH.
     *
     * @param  list<string>  $arguments
     */
    public function run(array $arguments, string $input = '', ?string $folder = null): ProcessResult
    {
        file_put_contents($this->root.'/fake/rules', implode("\n", [...$this->rules, ...$this->baseRules, ...$this->defaultRules()])."\n");

        return Process::path($folder ?? $this->dir)
            ->env([
                'PATH' => $this->bin.':'.getenv('PATH'),
                'LANG' => 'C',
                'LC_ALL' => '',
                'HOME' => $this->root,
                'FAKE_DIR' => $this->root.'/fake',
                'FAKE_DOWNLOADS' => $this->root.'/downloads',
                'https_proxy' => '',
                'HTTPS_PROXY' => '',
                'COMPOSE_FILE' => 'exported-by-the-shell.yaml',
                'COMPOSE_PROJECT_NAME' => 'not-this-one',
                ...$this->env,
            ])
            ->input($input)
            ->timeout(60)
            ->run(['bash', ($folder ?? $this->dir).'/install.sh', ...$arguments]);
    }

    /**
     * Every call of the fakes: name, arguments, -e variable lengths, input bytes.
     *
     * @return list<array{name: string, args: list<string>, line: string, env: array<string, int>, stdin: int}>
     */
    public function calls(): array
    {
        $calls = [];
        foreach (file($this->root.'/fake/log', FILE_IGNORE_NEW_LINES) ?: [] as $entry) {
            [$command, $env, $stdin] = array_pad(explode("\x1e", $entry), 3, '');
            $parts = explode("\x1f", $command);
            $lengths = [];
            foreach (array_filter(explode(' ', $env)) as $pair) {
                [$name, $length] = explode('=', $pair, 2);
                $lengths[$name] = (int) $length;
            }
            $calls[] = [
                'name' => $parts[0],
                'args' => array_slice($parts, 1),
                'line' => implode(' ', $parts),
                'env' => $lengths,
                'stdin' => (int) $stdin,
            ];
        }

        return $calls;
    }

    /**
     * @return list<string>
     */
    public function callLines(?string $name = null): array
    {
        return array_values(array_map(fn (array $call): string => $call['line'], array_filter($this->calls(), fn (array $call): bool => $name === null || $call['name'] === $name)));
    }

    /**
     * The text of every log file the installer wrote under .install.
     */
    public function installLogs(): string
    {
        return implode("\n", array_map(fn (string $file): string => (string) file_get_contents($file), glob($this->dir.'/.install/*.log') ?: []));
    }

    public function mode(string $path): string
    {
        clearstatcache();

        return substr(sprintf('%o', fileperms($path)), -3);
    }

    /** The commands the fake answers for. */
    public const FAKED = ['docker', 'kubectl', 'curl', 'nproc', 'free', 'df', 'ss', 'getent', 'timedatectl', 'sleep', 'id'];

    private const FAKE = <<<'BASH'
#!/usr/bin/env bash
# The fake of docker and friends for the installer tests (see InstallerSandbox).
name=$(basename "$0")
dir=${FAKE_DIR:?}
n=$(( $(cat "$dir/count" 2>/dev/null || echo 0) + 1 ))
echo "$n" > "$dir/count"
line=$name
for a in "$@"; do
    a=${a//$'\n'/\\n}
    line="$line"$'\x1f'"$a"
done
envs=""
prev=""
for a in "$@"; do
    if [[ $prev == -e && $a =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
        v=${!a-}
        envs="$envs $a=${#v}"
    fi
    prev=$a
done
stdin_file="$dir/stdin-$n"
: > "$stdin_file"
case $name in
    docker | kubectl | curl) [[ -t 0 ]] || cat > "$stdin_file" ;;
esac
bytes=$(wc -c < "$stdin_file" | tr -d ' ')
printf '%s\x1e%s\x1e%s\n' "$line" "${envs# }" "$bytes" >> "$dir/log"

case $name in
    sleep) exit 0 ;;
    id)
        case ${1:-} in
            -u) echo "${FAKE_UID:-1000}" ;;
            -un) echo tester ;;
            *) echo "uid=${FAKE_UID:-1000}(tester)" ;;
        esac
        exit 0 ;;
    nproc) echo "${FAKE_NPROC:-8}"; exit 0 ;;
    free) printf '              total        used        free\nMem:    %s 1 1\nSwap: 0 0 0\n' "$(( ${FAKE_MEM_MB:-32768} * 1048576 ))"; exit 0 ;;
    df) printf 'Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/sda1 999999999 1 %s 1%% /\n' "${FAKE_DISK_KB:-524288000}"; exit 0 ;;
    timedatectl) echo "${FAKE_NTP:-yes}"; exit 0 ;;
    ss) [[ -n ${FAKE_LISTEN:-} ]] && printf 'LISTEN 0 4096 0.0.0.0:%s 0.0.0.0:*\n' "$FAKE_LISTEN"; exit 0 ;;
    getent) [[ -n ${FAKE_GETENT:-} ]] && { echo "$FAKE_GETENT ${3:-}"; exit 0; }; exit 2 ;;
    curl)
        target="" url=""
        while [[ $# -gt 0 ]]; do
            case $1 in
                -o) target=$2; shift 2 ;;
                -*) shift ;;
                *) url=$1; shift ;;
            esac
        done
        source="${FAKE_DOWNLOADS:?}/$(basename "$url")"
        [[ -f $source ]] || exit 22
        cp "$source" "$target"
        exit 0 ;;
esac

# docker run -v HOST:/t … tar xf -: unpack the tar stream into the host folder, as the container would.
if [[ $name == docker && -s $stdin_file && " $* " == *" tar xf - "* ]]; then
    prev=""
    for a in "$@"; do
        if [[ $prev == -v ]]; then
            tar xf "$stdin_file" -C "${a%%:*}" 2>/dev/null || true
        fi
        prev=$a
    done
fi
# docker run … php -r <code> -- NAMES: the fake image prints a fixed value per name.
if [[ $name == docker && " $* " == *" --entrypoint php "* ]]; then
    after=0
    for a in "$@"; do
        if [[ $after == 1 ]]; then
            if [[ $a == APP_KEY ]]; then
                fake_body=MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=
                echo "APP_KEY=${FAKE_APP_KEY:-base64:$fake_body}"
            else
                hex=$(printf '%s' "fake-$a" | od -An -tx1 | tr -d ' \n')
                hex="${hex}aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
                echo "$a=${hex:0:48}"
            fi
        fi
        [[ $a == -- ]] && after=1
    done
    exit 0
fi

full="$name $*"
while IFS=$'\t' read -r glob code base; do
    [[ -n $glob ]] || continue
    # shellcheck disable=SC2053 # the rule is a glob
    if [[ $full == $glob ]]; then
        counter="$base.calls"
        k=$(( $(cat "$counter" 2>/dev/null || echo 0) + 1 ))
        echo "$k" > "$counter"
        max=$(cat "$base.count")
        [[ $k -le $max ]] || k=$max
        cat "$base.$k"
        [[ ! -f $base.err ]] || cat "$base.err" >&2
        exit "$code"
    fi
done < "$dir/rules"
exit 0
BASH;
}
