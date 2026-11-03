<?php

use App\Auth\AccountLogin;
use App\Auth\Oidc\MobileTokenExchange;
use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Auth\Passwordless\OneTimeCodes;
use App\Enums\OneTimeCodePurpose;
use App\Http\Middleware\AuthenticateApiKey;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\System\SignOutEveryone;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * The OpenHDID backup.sh restore loads a dump of the backup time: it brings
 * back the session epochs, remember-me tokens and mobile tokens of that time,
 * and a session kept outside the database (Valkey) is not part of the dump at
 * all. The sign-out step, php artisan hdid:sign-out-all (SignOutEveryone),
 * which the script runs in a one-off web container and the classic restore
 * procedure runs by hand, must end every sign-in made before the restore.
 */

beforeEach(function (): void {
    $this->withHeader('Referer', config('app.url'));
    Mail::fake();
});

/**
 * The backup.sh of the release: under deploy/openhdid/repo here, in the
 * root of the exported OpenHDID tree.
 */
function backupScript(): string
{
    $path = collect([base_path('backup.sh'), base_path('deploy/openhdid/repo/backup.sh')])->first(fn (string $path): bool => is_file($path));
    expect($path)->not->toBeNull('backup.sh is missing');

    return (string) file_get_contents($path);
}

/**
 * Runs the sign-out step the way backup.sh does after a restore (and on
 * ./backup.sh signout): the command without a question, with its reason.
 */
function runBackupScriptSignOut(): string
{
    $exitCode = Artisan::call('hdid:sign-out-all', ['--force' => true, '--reason' => 'backup_restore']);
    $output = Artisan::output();
    expect($exitCode)->toBe(0, $output);

    return $output;
}

/**
 * The restore as backup.sh does it: the accounts and tokens of the backup
 * time come back from the dump, the sessions and cache tables start empty.
 *
 * @param  array{accounts: array<string, list<array<string, mixed>>>, tokens: list<array<string, mixed>>}  $backup
 */
function restoreBackupSnapshot(array $backup): void
{
    foreach ($backup['accounts'] as $table => $rows) {
        foreach ($rows as $row) {
            DB::table($table)->where('id', $row['id'])->update(['session_epoch' => $row['session_epoch'], 'remember_token' => $row['remember_token']]);
        }
    }
    DB::table('personal_access_tokens')->delete();
    DB::table('personal_access_tokens')->insert($backup['tokens']);
    foreach (['sessions', 'cache', 'cache_locks'] as $table) {
        DB::table($table)->delete();
    }
}

/**
 * What the nightly dump holds of the accounts and the mobile tokens.
 *
 * @return array{accounts: array<string, list<array<string, mixed>>>, tokens: list<array<string, mixed>>}
 */
function takeBackupSnapshot(): array
{
    $rows = fn (string $table): array => DB::table($table)->get(['id', 'session_epoch', 'remember_token'])
        ->map(fn (object $row): array => (array) $row)->all();

    return [
        'accounts' => ['users' => $rows('users'), 'clients' => $rows('clients')],
        'tokens' => DB::table('personal_access_tokens')->get()->map(fn (object $row): array => (array) $row)->all(),
    ];
}

/**
 * GET /me as a later request of the browser or app: a fresh guard, and for a
 * bearer request no session, as the mobile app has none.
 */
function restoredClientMe(?string $bearer = null): TestResponse
{
    Auth::forgetGuards();
    if ($bearer === null) {
        return test()->getJson('/api/v1/client/me');
    }

    test()->flushSession();

    return test()->flushHeaders()->withToken($bearer)->getJson('/api/v1/client/me');
}

/**
 * GET /me with nothing but a remember-me cookie carrying the given token.
 */
function restoredClientMeByRememberCookie(Client $client, string $rememberToken): TestResponse
{
    $guard = Auth::guard('client');
    $name = $guard->getRecallerName();
    $value = $client->getKey().'|'.$rememberToken.'|'.$guard->hashPasswordForCookie((string) $client->getAuthPassword());

    Auth::forgetGuards();
    test()->flushSession();

    return test()->flushHeaders()->call('GET', '/api/v1/client/me', [], [
        $name => encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$value, false),
    ], [], ['HTTP_ACCEPT' => 'application/json']);
}

it('ends a browser session revoked after the backup once the restore signs everyone out', function (): void {
    // A store that is not a table (the redis driver keeps sessions in Valkey).
    $client = Client::factory()->synced()->create();
    signInWithLink(app(ClientPasswordlessLogin::class)->issueMagicLink($client))->assertOk();
    restoredClientMe()->assertOk();

    $backup = takeBackupSnapshot();
    // After the backup the client is signed out everywhere; the stolen session sends no request.
    $client->revokeAccess();

    restoreBackupSnapshot($backup);
    // The backup's epoch matches the session again: without the sign-out step it would count.
    restoredClientMe()->assertOk()->assertJsonPath('data.id', $client->id);

    $output = runBackupScriptSignOut();

    restoredClientMe()->assertUnauthorized();
    expect((int) $client->fresh()->session_epoch)->toBeGreaterThan((int) $backup['accounts']['clients'][0]['session_epoch'])
        ->and($output)->toContain('signed-out 0 1 0');
});

it('removes the mobile tokens and remember-me tokens of the backup and records the sign-out', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginRememberEnabled, true);
    $client = Client::factory()->synced()->create();
    $bearer = app(AccountLogin::class)->issueToken($client, 'test', 'phone');
    $client->forceFill(['remember_token' => $rememberToken = Str::random(60)])->saveQuietly();
    $staff = User::factory()->create(['remember_token' => Str::random(60)]);
    restoredClientMe($bearer)->assertOk();

    $backup = takeBackupSnapshot();
    $client->revokeAccess();
    $staff->revokeAccess();
    restoredClientMe($bearer)->assertUnauthorized();

    restoreBackupSnapshot($backup);
    restoredClientMe($bearer)->assertOk();

    runBackupScriptSignOut();

    restoredClientMe($bearer)->assertUnauthorized();
    restoredClientMeByRememberCookie($client, $rememberToken)->assertUnauthorized();
    expect(DB::table('personal_access_tokens')->count())->toBe(0)
        ->and($client->fresh()->remember_token)->toBeNull()
        ->and($staff->fresh()->remember_token)->toBeNull()
        ->and((int) $staff->fresh()->session_epoch)->toBeGreaterThan((int) $backup['accounts']['users'][0]['session_epoch']);

    $entry = AuditLog::query()->where('event', 'access.revoked_all')->sole();
    expect($entry->context)->toMatchArray(['reason' => 'backup_restore', 'users' => 1, 'clients' => 1, 'tokens' => 1]);
});

it('empties a session store kept outside the database, as Valkey is, and the cache', function (): void {
    // A cache-backed session handler, as the redis driver builds one, on its own store.
    config()->set('cache.stores.restore-sessions', ['driver' => 'array', 'serialize' => true]);
    config()->set('session.store', 'restore-sessions');
    config()->set('session.driver', 'memcached');
    app('session')->setDefaultDriver('memcached');
    app()->forgetInstance('session.store');
    Auth::forgetGuards();

    $client = Client::factory()->synced()->create();
    signInWithLink(app(ClientPasswordlessLogin::class)->issueMagicLink($client))->assertOk();
    $handler = app('session')->driver()->getHandler();
    $sessionId = app('session')->driver()->getId();
    Cache::put('restore-probe', 'cached', 3600);
    expect($handler->read($sessionId))->not->toBe('');

    $output = runBackupScriptSignOut();

    expect($handler->read($sessionId))->toBe('')
        ->and(Cache::get('restore-probe'))->toBeNull()
        ->and($output)->toContain(' memcached array')
        // Not on Valkey at all: nothing of the queue's database was emptied for the sessions.
        ->and($output)->toMatch('/ memcached array shared$/m');
});

it('tells the script that the queue survived in every Valkey layout', function (): void {
    $script = backupScript();

    // One place for the logic: the script runs the command, and reads its last line.
    expect($script)->toContain('SIGN_OUT_COMMAND=(artisan hdid:sign-out-all --force --reason=backup_restore)')
        ->and($script)->toContain('run_web web "${SIGN_OUT_COMMAND[@]}" </dev/null')
        ->and($script)->not->toContain('tinker --execute')
        // The bundled session Valkey is started like the main one, and the report still tells the two cases apart.
        ->and($script)->toContain('for store in valkey valkey-sessions; do')
        ->and($script)->toContain('(\\ (shared|separate))?')
        // Keys go by prefix: the queue and the replay guards stay whether or not the sessions share the main Valkey.
        ->and($script)->toContain('a várakozó sorfeladatok és a visszajátszás-védelem kulcsai megmaradtak')
        ->and($script)->not->toMatch('/sorfeladatok[^\n]*(is törlődtek|is elvesznek|elvesznek)/');

    $output = runBackupScriptSignOut();
    expect($output)->toMatch('/^signed-out 0 0 0 0 array array shared$/m')
        ->and($output)->not->toMatch('/queue[^\n]*(lost|emptied|with them)/i');
});

/**
 * The report of backup.sh's sign_out_everyone for the command's machine line:
 * the function taken from the script and run by bash, with docker, the
 * one-off web container and the messages stubbed.
 */
function backupScriptSignOutReport(string $machineLine): string
{
    if (Process::run(['sh', '-c', 'command -v bash'])->failed()) {
        test()->markTestSkipped('No bash here (the OpenHDID image ships none).');
    }

    preg_match('/^sign_out_everyone\(\) \{\n.*?\n\}\n/ms', backupScript(), $function);
    expect($function)->not->toBeEmpty('sign_out_everyone is missing from backup.sh');

    $stubs = <<<'BASH'
        set -euo pipefail
        say() { printf '%s\n' "$*"; }
        die() { printf 'DIE %s\n' "$*"; exit 1; }
        docker() { [[ $1 == compose && $2 == config ]] && printf 'web\n'; return 0; }
        run_web() { printf '%s\n' "$SIGN_OUT_LINE"; }
        SIGN_OUT_COMMAND=(artisan hdid:sign-out-all)
        BASH;

    $result = Process::env(['SIGN_OUT_LINE' => $machineLine])->run(['bash', '-c', $stubs."\n".$function[0]."\nsign_out_everyone\n"]);
    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());

    return $result->output();
}

it('says in the script report what stayed in Valkey, and claims the replay guards only where Valkey holds them', function (): void {
    $valkey = backupScriptSignOutReport('signed-out 1 2 3 4 redis redis separate');
    expect($valkey)->toContain('a munkamenetek saját Valkey-ben')
        ->and($valkey)->toContain('a várakozó sorfeladatok és a visszajátszás-védelem kulcsai megmaradtak');

    // Sessions in Valkey, the cache in the database: the replay guards are rows of the cache table,
    // which a restore loads from the dump and empties; the Valkey line speaks of the queue only.
    $databaseCache = backupScriptSignOutReport('signed-out 1 2 3 4 redis database shared');
    expect($databaseCache)->toContain('a munkamenetek a fő Valkey-ben')
        ->and($databaseCache)->toContain('a várakozó sorfeladatok megmaradtak')
        ->and($databaseCache)->not->toContain('visszajátszás-védelem');

    expect(backupScriptSignOutReport('signed-out 1 2 3 4 database database shared'))->not->toContain('Valkey');
});

it('asks before signing everybody out and changes nothing on no', function (): void {
    $client = Client::factory()->synced()->create();
    $bearer = app(AccountLogin::class)->issueToken($client, 'test', 'phone');
    $epoch = (int) $client->fresh()->session_epoch;

    $this->artisan('hdid:sign-out-all')
        ->expectsConfirmation('Sign everybody out now?', 'no')
        ->expectsOutputToContain('Nothing changed.')
        ->assertFailed();

    expect((int) $client->fresh()->session_epoch)->toBe($epoch)
        ->and(DB::table('personal_access_tokens')->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'access.revoked_all')->exists())->toBeFalse();

    // Confirmed by hand: the default reason says so, and the report names what happened.
    $this->artisan('hdid:sign-out-all')
        ->expectsConfirmation('Sign everybody out now?', 'yes')
        ->expectsOutputToContain('1 mobile token(s) deleted')
        ->expectsOutputToContain('access.revoked_all (reason manual)')
        ->assertSuccessful();

    restoredClientMe($bearer)->assertUnauthorized();
    expect((int) $client->fresh()->session_epoch)->toBeGreaterThan($epoch)
        ->and(AuditLog::query()->where('event', 'access.revoked_all')->sole()->context)->toMatchArray(['reason' => 'manual', 'clients' => 1, 'tokens' => 1]);
});

it('empties the sessions table when the sessions live in the database', function (): void {
    config()->set('session.driver', 'database');
    app('session')->setDefaultDriver('database');
    app()->forgetInstance('session.store');
    Auth::forgetGuards();

    $client = Client::factory()->synced()->create();
    signInWithLink(app(ClientPasswordlessLogin::class)->issueMagicLink($client))->assertOk();
    expect(DB::table('sessions')->count())->toBeGreaterThan(0);

    runBackupScriptSignOut();

    expect(DB::table('sessions')->count())->toBe(0);
});

it('uses up a login link of the backup time that was redeemed after the backup, so it does not sign in again after the restore', function (): void {
    $client = Client::factory()->synced()->create();
    $link = app(ClientPasswordlessLogin::class)->issueMagicLink($client);
    $codesAtBackup = DB::table('one_time_codes')->get()->map(fn (object $row): array => (array) $row)->all();

    // After the backup the link is redeemed and the client signed out everywhere.
    signInWithLink($link)->assertOk();
    $client->revokeAccess();
    Auth::forgetGuards();
    $this->flushSession();

    // The dump brings the unused link back.
    DB::table('one_time_codes')->delete();
    DB::table('one_time_codes')->insert($codesAtBackup);
    signInWithLink($link)->assertOk();
    Auth::forgetGuards();
    $this->flushSession();
    DB::table('one_time_codes')->delete();
    DB::table('one_time_codes')->insert($codesAtBackup);

    $output = runBackupScriptSignOut();

    signInWithLink($link)->assertForbidden();
    expect($output)->toMatch('/signed-out 0 1 0 1 /')
        ->and(AuditLog::query()->where('event', 'access.revoked_all')->sole()->context)->toMatchArray(['codes' => 1]);
});

it('consumes every unused code, expired ones too, and wipes the decryptable copy and the lookup of each', function (): void {
    $codes = app(OneTimeCodes::class);
    $live = $codes->issueUnique(Client::factory()->create(), OneTimeCodePurpose::IvrCode, fn (): string => '12345678', 10, 5)['record'];
    $expired = $codes->issueUnique(Client::factory()->create(), OneTimeCodePurpose::MobileOtp, fn (): string => '87654321', 10, 5)['record'];
    $expired->forceFill(['expires_at' => now()->subMinute()])->save();
    // A code used up by the sign-out of an earlier release kept its copy.
    $spent = $codes->issueUnique(Client::factory()->create(), OneTimeCodePurpose::IvrCode, fn (): string => '11223344', 10, 5)['record'];
    DB::table('one_time_codes')->where('id', $spent->id)->update(['consumed_at' => now()->subDay(), 'lookup' => null]);

    $output = runBackupScriptSignOut();

    foreach ([$live, $expired, $spent] as $code) {
        $row = DB::table('one_time_codes')->where('id', $code->id)->first();
        expect($row->consumed_at)->not->toBeNull()
            ->and($row->secret_encrypted)->toBeNull()
            ->and($row->lookup)->toBeNull();
    }
    // The count still names the codes that were usable.
    expect($output)->toMatch('/^signed-out \d+ \d+ \d+ 1 /m')
        ->and(DB::table('one_time_codes')->where('id', $spent->id)->value('consumed_at'))->toStartWith(now()->subDay()->format('Y-m-d'));
});

it('keeps the replay guards in a database cache while it empties the rest of the cache and every lock', function (): void {
    config()->set('cache.default', 'database');
    Cache::add(MobileTokenExchange::REPLAY_KEY_PREFIX.'probe', true, 3600);
    Cache::add(AuthenticateApiKey::SIGNATURE_REPLAY_PREFIX.'probe', 1, 3600);
    Cache::put('hdid-probe:login-lockout', 5, 3600);
    Cache::put('restore-probe', 'cached', 3600);
    expect(Cache::lock('restore-probe-lock', 60)->get())->toBeTrue();

    app(SignOutEveryone::class)->run('manual');

    expect(Cache::has(MobileTokenExchange::REPLAY_KEY_PREFIX.'probe'))->toBeTrue()
        ->and(Cache::has(AuthenticateApiKey::SIGNATURE_REPLAY_PREFIX.'probe'))->toBeTrue()
        ->and(Cache::has('hdid-probe:login-lockout'))->toBeFalse()
        ->and(Cache::has('restore-probe'))->toBeFalse()
        ->and(DB::table('cache')->count())->toBe(2)
        ->and(DB::table('cache_locks')->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'access.revoked_all')->sole()->context)->toMatchArray(['cache_keys' => 2, 'lock_keys' => 1]);
});

it('keeps the replay guards of a database cache whose prefix holds LIKE wildcards', function (): void {
    config()->set('cache.default', 'database');
    config()->set('cache.prefix', 'hdid_prod_');
    Cache::forgetDriver('database');
    DB::table('cache')->insert([
        ['key' => 'hdid_prod_'.MobileTokenExchange::REPLAY_KEY_PREFIX.'z', 'value' => serialize(true), 'expiration' => now()->addHour()->getTimestamp()],
        ['key' => 'hdid_prodX'.MobileTokenExchange::REPLAY_KEY_PREFIX.'z', 'value' => serialize(true), 'expiration' => now()->addHour()->getTimestamp()],
        ['key' => 'hdid%prod_'.AuthenticateApiKey::SIGNATURE_REPLAY_PREFIX.'z', 'value' => serialize(1), 'expiration' => now()->addHour()->getTimestamp()],
    ]);

    app(SignOutEveryone::class)->run('manual');

    expect(DB::table('cache')->pluck('key')->all())->toBe(['hdid_prod_'.MobileTokenExchange::REPLAY_KEY_PREFIX.'z']);
});

/**
 * Valkey servers behind the named connections, each holding the given raw
 * keys (with the connection prefix, as the server keeps them). SCAN answers in
 * pages of two, with an empty page in between, filtered by its MATCH glob;
 * UNLINK and DEL record what they were given. Any other command, FLUSHDB
 * among them, fails the test.
 *
 * @param  array<string, array{prefix: string, keys: list<string>}>  $servers
 * @return ArrayObject<string, mixed> per connection: the MATCH patterns asked ("<name>.match") and the keys unlinked ("<name>")
 */
function fakeValkeyKeyspaces(array $servers): ArrayObject
{
    $seen = new ArrayObject;

    foreach ($servers as $name => $server) {
        $seen[$name] = [];
        $seen[$name.'.match'] = [];
        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('getName')->andReturn($name);
        $connection->shouldReceive('_prefix')->with('')->andReturn($server['prefix']);
        $connection->shouldReceive('scan')->andReturnUsing(function (mixed $cursor, array $options) use ($server, $seen, $name): array {
            $seen[$name.'.match'] = [...$seen[$name.'.match'], $options['match']];
            expect($options['count'])->toBe(1000);
            $matching = array_values(array_filter($server['keys'], fn (string $key): bool => fnmatch($options['match'], $key)));
            $pages = array_chunk($matching, 2);
            array_splice($pages, 1, 0, [[]]);
            $page = (int) ($cursor ?? 0);

            return [$page + 1 < count($pages) ? (string) ($page + 1) : 0, $pages[$page] ?? []];
        });
        $connection->shouldReceive('command')->with('unlink', Mockery::type('array'))->andReturnUsing(function (string $method, array $keys) use ($seen, $name): int {
            $seen[$name] = [...$seen[$name], ...$keys];

            return count($keys);
        });
        $connection->shouldNotReceive('flushdb');
        Redis::shouldReceive('connection')->with($name)->andReturn($connection);
    }

    Cache::forgetDriver('redis');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    return $seen;
}

/**
 * The OpenHDID layout: sessions on the session connection, the cache on the
 * cache connection (REDIS_CACHE_DB 1), locks and the queue on the default one.
 */
function useValkeyStores(string $sessionHost, string $cachePrefix = 'openhdid-cache-'): void
{
    config()->set('database.redis.default', ['host' => 'valkey', 'port' => '6379', 'database' => '0']);
    config()->set('database.redis.cache', ['host' => 'valkey', 'port' => '6379', 'database' => '1']);
    config()->set('database.redis.session', ['host' => $sessionHost, 'port' => '6379', 'database' => '0']);
    config()->set('cache.prefix', $cachePrefix);
    config()->set('cache.default', 'redis');
    config()->set('session.driver', 'redis');
    config()->set('session.connection', 'session');
    app('session')->setDefaultDriver('redis');
}

it('deletes only this installation\'s session, cache and lock keys from Valkey and keeps the queue and the replay guards', function (string $sessionHost): void {
    $p = 'openhdid-prod-';
    $c = 'openhdid-cache-';
    $replay = [$p.$c.MobileTokenExchange::REPLAY_KEY_PREFIX.'a1', $p.$c.AuthenticateApiKey::SIGNATURE_REPLAY_PREFIX.'b2'];
    useValkeyStores($sessionHost);
    $sessionKeys = [$p.$c.'Xq3sessionid1', $p.$c.'Xq3sessionid2', $p.$c.'hdid.session-store.heartbeat'];
    $mainKeys = [$p.'queues:default', $p.'queues:default:reserved', $p.'queues:priority:delayed', $p.$c.'hdid-0123456789ab:hdid:login-lockout:1', 'other-install:'.$c.'x', $p.'other-cache-y'];
    $servers = $sessionHost === 'valkey'
        // Without a session Valkey of their own the sessions sit in the main one's database 0, beside the queue.
        ? ['session' => ['prefix' => $p, 'keys' => [...$sessionKeys, ...$mainKeys]], 'default' => ['prefix' => $p, 'keys' => [...$sessionKeys, ...$mainKeys]]]
        : ['session' => ['prefix' => $p, 'keys' => [...$sessionKeys, 'other-install:'.$c.'s']], 'default' => ['prefix' => $p, 'keys' => $mainKeys]];
    $servers['cache'] = ['prefix' => $p, 'keys' => [$p.$c.'restore-probe', ...$replay, $p.$c.'hdid-0123456789ab:hdid:settings', 'other-install:'.$c.'z']];
    $seen = fakeValkeyKeyspaces($servers);

    $result = app(SignOutEveryone::class)->run('manual');

    $deleted = [...$seen['session'], ...$seen['cache'], ...$seen['default']];
    // Without the connection prefix: phpredis adds it to the keys of UNLINK by itself.
    expect($deleted)->toEqualCanonicalizing([$c.'Xq3sessionid1', $c.'Xq3sessionid2', $c.'hdid.session-store.heartbeat', $c.'hdid-0123456789ab:hdid:login-lockout:1', $c.'restore-probe', $c.'hdid-0123456789ab:hdid:settings'])
        ->and($seen['cache.match'])->toContain('openhdid-prod-openhdid-cache-*')
        ->and($result)->toMatchArray(['cache_keys' => 2]);

    $context = AuditLog::query()->where('event', 'access.revoked_all')->sole()->context;
    expect($context['session_keys'] + $context['lock_keys'])->toBe(4);
})->with(['a session Valkey of its own' => 'valkey-sessions', 'the sessions in the main Valkey' => 'valkey']);

it('never sweeps the queue keys when the cache prefix is empty and the cache shares the queue database', function (): void {
    $p = 'openhdid-prod-';
    useValkeyStores('valkey', '');
    config()->set('database.redis.cache', ['host' => 'valkey', 'port' => '6379', 'database' => '0']);
    $keys = [$p.'queues:default', $p.'queues:default:reserved', $p.'queues:default:notify', $p.'Xq3sessionid1', $p.'restore-probe', $p.MobileTokenExchange::REPLAY_KEY_PREFIX.'a1'];
    $seen = fakeValkeyKeyspaces(['session' => ['prefix' => $p, 'keys' => $keys], 'cache' => ['prefix' => $p, 'keys' => $keys], 'default' => ['prefix' => $p, 'keys' => $keys]]);

    app(SignOutEveryone::class)->run('manual');

    // One database, swept once.
    expect([...$seen['session'], ...$seen['cache'], ...$seen['default']])->toEqualCanonicalizing(['Xq3sessionid1', 'restore-probe'])
        ->and(count($seen['session.match']) + count($seen['cache.match']) + count($seen['default.match']))->toBeLessThanOrEqual(4);
});

it('escapes the glob characters of the prefix and strips the connection prefix before unlinking', function (): void {
    useValkeyStores('valkey-sessions', 'openhdid-prod-cache[1]*?-');
    $c = 'openhdid-prod-cache[1]*?-';
    $seen = fakeValkeyKeyspaces([
        'session' => ['prefix' => 'openhdid-prod-', 'keys' => []],
        'default' => ['prefix' => 'openhdid-prod-', 'keys' => []],
        // A key the unescaped glob would match too (the * and ? would take any character).
        'cache' => ['prefix' => 'openhdid-prod-', 'keys' => ['openhdid-prod-'.$c.'x', 'openhdid-prod-openhdid-prod-cache1ab-y']],
    ]);

    app(SignOutEveryone::class)->run('manual');

    expect($seen['cache'])->toBe([$c.'x'])
        ->and($seen['cache.match'][0])->toBe('openhdid-prod-openhdid-prod-cache\[1\]\*\?-*');
});
