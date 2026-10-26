<?php

use App\Auth\AccountLogin;
use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

it('says whether the sessions had a Valkey of their own, so the script knows the queue survived the flush', function (): void {
    $script = backupScript();

    // One place for the logic: the script runs the command, and reads its last line.
    expect($script)->toContain('SIGN_OUT_COMMAND=(artisan hdid:sign-out-all --force --reason=backup_restore)')
        ->and($script)->toContain('run_web web "${SIGN_OUT_COMMAND[@]}" </dev/null')
        ->and($script)->not->toContain('tinker --execute')
        // The bundled session Valkey is started like the main one, and the report tells the two cases apart.
        ->and($script)->toContain('for store in valkey valkey-sessions; do')
        ->and($script)->toContain('(\\ (shared|separate))?')
        ->and($script)->toContain('a fő Valkey-ben a sor megmaradt');

    $output = runBackupScriptSignOut();
    expect($output)->toMatch('/^signed-out 0 0 0 0 array array shared$/m');
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
