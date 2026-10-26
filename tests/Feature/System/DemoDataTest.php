<?php

use App\Auth\AccountLogin;
use App\Auth\LoginRejectedException;
use App\Auth\Role;
use App\Enums\PrincipalType;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSource;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\SyncIdListItem;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\Contracts\SourceReader;
use App\Sync\ExternalRecordDto;
use App\Sync\IdList;
use App\Sync\SyncExternalRecords;
use App\System\CheckStatus;
use App\System\HealthChecks;
use App\System\NotDemoAddressRule;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Faker\Generator;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

it('loads the documented demo accounts without the Faker dev dependency', function (): void {
    // The container image has no Faker: any factory call inside the seeder must fail here.
    app()->bind(Generator::class.':'.config('app.faker_locale'), fn () => throw new LogicException('Faker is not installed in the image.'));

    $this->seed(DemoSeeder::class);

    $agent = User::query()->where('email', 'agent@example.test')->sole();
    $anna = Client::query()->where('email', 'anna@example.test')->sole();
    expect($agent->hasRole(Role::Agent->value))->toBeTrue()
        ->and($agent->externalRecord?->email)->toBe('agent@example.test')
        ->and($anna->externalRecord?->external_id)->toBe(100001)
        ->and($anna->explicit_package)->toBe('Premium+')
        ->and($anna->pin_hash)->not->toBeNull()
        ->and(Client::query()->where('email', 'bela@example.test')->sole()->implicit_package)->toBe('Basic')
        ->and(User::query()->where('email', 'like', '%@example.test')->count())->toBe(3)
        ->and(Call::withTrashed()->count())->toBeGreaterThan(3)
        ->and(SyncIdListItem::query()->count())->toBe(22)
        ->and(app(IdList::class)->selectedIds())->toBe(['11', '21', '25', '36', '51']);

    expect(app(AccountLogin::class)->assertEligible($agent, PrincipalType::User, 'password'))->toBe($agent);
});

it('refuses to load demo data in production and staging', function (string $environment): void {
    app()->detectEnvironment(fn () => $environment);

    expect(fn () => app(DemoSeeder::class)->run())->toThrow(RuntimeException::class, 'APP_ENV=demo');
    expect(User::query()->count())->toBe(0);
})->with(['production', 'staging']);

it('refuses a new demo address on the panel in production only', function (): void {
    $validate = fn (string $email) => Validator::make(['email' => $email], ['email' => [new NotDemoAddressRule]]);

    expect($validate('kezikonyv@example.test')->passes())->toBeTrue();

    app()->detectEnvironment(fn () => 'production');
    expect($validate('kezikonyv@example.test')->errors()->first('email'))->toContain('reserved .test domain')
        ->and($validate('real.agent@example.org')->passes())->toBeTrue();
});

it('names the addresses found first, so a stray .test address is told apart from the demo', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    User::factory()->synced()->withRole(Role::Admin)->create(['email' => 'admin@ceg.test']);
    app()->detectEnvironment(fn () => 'production');

    $tile = collect(app(HealthChecks::class)->all())->firstWhere('key', 'demo_data');

    expect($tile?->status)->toBe(CheckStatus::Fail)
        ->and($tile?->detail)->toContain('Found first: admin@ceg.test.')
        ->and($tile?->hint)->toContain('APP_ENV=staging for a moment')
        ->and($tile?->hint)->toContain('do not delete it');
});

/*
 * Demo data left in a production database (OPENHDID_DEMO_DATA, the
 * DemoSeeder): its accounts' passwords, PIN and answers are published in
 * the source, so with APP_ENV=production the container start stops, the
 * status page turns red, the demo accounts cannot sign in, the EMD sync
 * does not hand a demo record to the real person behind its id and
 * hdid:make-admin warns. Outside production nothing changes.
 */

/**
 * An EMD source of the given rows.
 *
 * @param  list<ExternalRecordDto>  $rows
 */
function demoDataEmdSource(array $rows): SourceReader
{
    return new class($rows) implements SourceReader
    {
        /** @param list<ExternalRecordDto> $rows */
        public function __construct(private array $rows) {}

        public function source(): SyncSource
        {
            return SyncSource::Csv;
        }

        public function label(): ?string
        {
            return 'demo-data-test';
        }

        public function read(): iterable
        {
            yield from $this->rows;
        }

        public function details(): array
        {
            return [];
        }
    };
}

/**
 * The real EMD rows that carry the demo agent's and Anna's external ids,
 * plus one unrelated real client.
 *
 * @return list<ExternalRecordDto>
 */
function demoDataRealRows(): array
{
    return [
        new ExternalRecordDto(200001, 'Valódi Munkatárs', 'valodi.munkatars@example.org', 'Példa Kft.'),
        new ExternalRecordDto(100001, 'Valódi Ügyfél', 'valodi.ugyfel@ugyfel.hu', 'Ügyfél Kft.', ['+36 30 555 1234'], [], 'Basic'),
        new ExternalRecordDto(100010, 'Másik Ügyfél', 'masik.ugyfel@ugyfel.hu', 'Ügyfél Kft.', [], [], 'Basic'),
    ];
}

/**
 * The container helper (docker/runtime.php) pointed at this checkout instead
 * of the image's /app; the copy lives in the system temp directory.
 */
function demoDataRuntimeHelper(): string
{
    $source = collect(['deploy/openhdid/repo/docker/runtime.php', 'docker/runtime.php'])
        ->map(fn (string $path): string => base_path($path))
        ->first(fn (string $path): bool => is_file($path));

    if ($source === null) {
        test()->markTestSkipped('The container runtime helper is not part of this tree.');
    }

    $patched = str_replace("const APP_DIR = '/app';", 'const APP_DIR = '.var_export(base_path(), true).';', (string) file_get_contents($source), $count);
    expect($count)->toBe(1, 'runtime.php no longer declares APP_DIR as expected');

    $copy = sys_get_temp_dir().'/openhdid-runtime-'.Str::random(12).'.php';
    file_put_contents($copy, $patched);

    return $copy;
}

it('stops the container start in production while the database holds the demo data', function (): void {
    $database = sys_get_temp_dir().'/openhdid-demo-'.Str::random(12).'.sqlite';
    touch($database);
    $helper = demoDataRuntimeHelper();
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'OPENHDID_DEMO_DATA' => 'false', 'APP_KEY' => config('app.key')];
    $artisan = fn (array $arguments) => Process::path(base_path())->env($env)->timeout(300)->run([PHP_BINARY, 'artisan', ...$arguments, '--no-interaction']);
    $startIn = fn (string $environment) => Process::path(base_path())->env(['APP_ENV' => $environment] + $env)->timeout(120)->run([PHP_BINARY, $helper, 'demo-data']);

    try {
        expect($artisan(['migrate', '--force'])->successful())->toBeTrue();

        // An empty production database starts.
        expect($startIn('production')->exitCode())->toBe(0);

        expect($artisan(['db:seed', '--class=Database\\Seeders\\DemoSeeder', '--force'])->successful())->toBeTrue();

        $production = $startIn('production');
        expect($production->exitCode())->toBe(64)
            ->and($production->errorOutput())->toContain('demo data')
            ->and($production->errorOutput())->toContain('APP_ENV=production')
            ->and($production->errorOutput())->toContain('docker compose down -v');

        // The message names what it found, so a real database with a stray .test address is told apart.
        expect($production->errorOutput())->toContain('found first: agent@example.test');

        // The demo itself keeps starting.
        expect($startIn('demo')->exitCode())->toBe(0);

        // Demo data is loaded only with APP_ENV=demo, never in staging.
        $staging = Process::path(base_path())->env(['APP_ENV' => 'staging', 'OPENHDID_DEMO_DATA' => 'true'] + $env)->timeout(120)->run([PHP_BINARY, $helper, 'demo-data']);
        expect($staging->exitCode())->toBe(64)
            ->and($staging->errorOutput())->toContain('OPENHDID_DEMO_DATA=true needs APP_ENV=demo');
    } finally {
        @unlink($database);
        @unlink($helper);
    }
});

it('shows a red demo data tile in production and leaves other environments informed only', function (): void {
    $tile = fn () => collect(app(HealthChecks::class)->all())->firstWhere('key', 'demo_data');

    app()->detectEnvironment(fn () => 'production');
    expect($tile()?->status)->toBe(CheckStatus::Ok);

    app()->detectEnvironment(fn () => 'testing');
    $this->seed(DemoSeeder::class);
    expect($tile()?->status)->toBe(CheckStatus::Info)
        ->and($tile()?->detail)->toContain('3 staff account(s)');

    app()->detectEnvironment(fn () => 'production');
    expect($tile()?->status)->toBe(CheckStatus::Fail)
        ->and($tile()?->hint)->toContain('docker compose down -v')
        ->and($tile()?->hint)->toContain('php artisan migrate --force');

    $this->getJson('/health')->assertStatus(503)->assertJsonFragment(['key' => 'demo_data', 'status' => 'fail']);
});

it('refuses the demo accounts at sign-in in production with its own reason', function (): void {
    $this->seed(DemoSeeder::class);
    $agent = User::query()->where('email', 'bence@example.test')->sole();
    $anna = Client::query()->where('email', 'anna@example.test')->sole();
    $login = app(AccountLogin::class);

    expect($login->assertEligible($agent, PrincipalType::User, 'password'))->toBe($agent);

    app()->detectEnvironment(fn () => 'production');

    foreach ([[$agent, PrincipalType::User], [$anna, PrincipalType::Client]] as [$account, $type]) {
        try {
            $login->assertEligible($account, $type, 'password');
            $this->fail('A demo account signed in in production.');
        } catch (LoginRejectedException $e) {
            expect($e->reason->value)->toBe('demo_account')
                ->and($e->getMessage())->toContain('demo account');
        }
    }

    expect(AuditLog::query()->where('event', 'login.rejected')->get()->pluck('context.reason')->all())->toBe(['demo_account', 'demo_account']);

    // A real account still signs in.
    $real = User::factory()->synced()->withRole(Role::Agent)->create(['email' => 'real.agent@example.org']);
    expect($login->assertEligible($real, PrincipalType::User, 'password'))->toBe($real);
});

it('never hands a demo record to the real person behind its id in production', function (): void {
    $this->seed(DemoSeeder::class);
    app(Settings::class)->set(SettingKey::SyncUserDomains, ['example.org']);
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['ugyfel.hu']);
    app()->detectEnvironment(fn () => 'production');

    $run = app(SyncExternalRecords::class)->run(demoDataEmdSource(demoDataRealRows()));

    $agent = User::query()->where('external_record_id', ExternalRecord::query()->where('external_id', 200001)->value('id'))->sole();
    $anna = Client::query()->where('external_record_id', ExternalRecord::query()->where('external_id', 100001)->value('id'))->sole();

    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and($run->stats['demo_conflicts'])->toBe(2)
        ->and($run->stats['email_conflicts'])->toBe(2)
        ->and(collect($run->stats['email_conflict_samples'])->pluck('kept', 'external_id')->all())->toBe([200001 => 'agent@example.test', 100001 => 'anna@example.test'])
        ->and(collect($run->stats['email_conflict_samples'])->every(fn (array $sample): bool => ($sample['demo'] ?? false) === true))->toBeTrue()
        ->and($agent->email)->toBe('agent@example.test')
        ->and(ExternalRecord::query()->where('external_id', 200001)->value('email'))->toBe('agent@example.test')
        ->and($anna->email)->toBe('anna@example.test')
        ->and(Client::query()->where('email', 'valodi.ugyfel@ugyfel.hu')->exists())->toBeFalse()
        ->and(Client::query()->where('email', 'masik.ugyfel@ugyfel.hu')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'sync.demo_conflict')->count())->toBe(2);

    // The demo records count as absent, so the demo accounts close like any missing record.
    expect(ExternalRecord::query()->where('external_id', 200001)->value('missed_runs'))->toBe(1);
});

it('keeps the sync unchanged for demo records outside production', function (): void {
    $this->seed(DemoSeeder::class);
    app(Settings::class)->set(SettingKey::SyncUserDomains, ['example.org']);
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['ugyfel.hu']);

    $run = app(SyncExternalRecords::class)->run(demoDataEmdSource(demoDataRealRows()));

    expect($run->stats['demo_conflicts'] ?? 0)->toBe(0)
        ->and($run->stats['email_conflicts'])->toBe(0)
        ->and(ExternalRecord::query()->where('external_id', 200001)->value('email'))->toBe('valodi.munkatars@example.org');
});

it('warns from hdid:make-admin in production while the demo data is loaded and refuses a demo address', function (): void {
    $this->seed(DemoSeeder::class);

    $this->artisan('hdid:make-admin', ['email' => 'admin@example.org', '--password' => 'a-long-password-1'])
        ->doesntExpectOutputToContain('demo data')
        ->assertSuccessful();

    app()->detectEnvironment(fn () => 'production');

    $this->artisan('hdid:make-admin', ['email' => 'chief@example.org', '--password' => 'a-long-password-2'])
        ->expectsOutputToContain('This database holds the demo data')
        ->assertSuccessful();

    // A demo address is refused in production: it could never sign in, and it would mark the database as demo data.
    $this->artisan('hdid:make-admin', ['email' => 'kezikonyv@example.test', '--password' => 'a-long-password-3'])
        ->expectsOutputToContain('reserved .test domain')
        ->assertFailed();
    expect(User::query()->where('email', 'kezikonyv@example.test')->exists())->toBeFalse();
});

it('signs a demo account out on its next request once the installation runs in production', function (): void {
    $this->seed(DemoSeeder::class);
    app(Settings::class)->set(SettingKey::UserLoginRememberEnabled, true);
    $bence = User::query()->where('email', 'bence@example.test')->sole();
    $anna = Client::query()->where('email', 'anna@example.test')->sole();
    $token = $anna->createToken('mobile')->plainTextToken;

    // Tokens and sessions from the demo period work outside production.
    $this->withToken($token)->getJson('/api/v1/client/me')->assertOk();
    Auth::forgetGuards();
    $this->actingAs($bence, 'web')->get('/admin')->assertOk();
    Auth::forgetGuards();
    $this->flushSession();

    app()->detectEnvironment(fn () => 'production');

    $this->withToken($token)->getJson('/api/v1/client/me')
        ->assertForbidden()
        ->assertJsonPath('reason', 'demo_account');
    expect($anna->fresh()->tokens()->count())->toBe(0, 'the token is revoked, not only refused');
    Auth::forgetGuards();

    $this->actingAs($bence, 'web')->get('/admin')->assertForbidden();
    Auth::forgetGuards();
    $this->flushSession();

    // A remember-me cookie from the demo period does not open the panel either.
    $bence->forceFill(['remember_token' => $remember = Str::random(60)])->saveQuietly();
    Auth::forgetGuards();
    $this->flushSession();
    $guard = Auth::guard('web');
    $name = $guard->getRecallerName();
    $value = $bence->getAuthIdentifier().'|'.$remember.'|'.$guard->hashPasswordForCookie($bence->getAuthPassword());
    Auth::forgetGuards();
    $this->call('GET', '/admin', [], [$name => encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$value, false)])
        ->assertForbidden();

    // A real account keeps working.
    $real = User::factory()->synced()->withRole(Role::Agent)->create(['email' => 'real.agent@example.org']);
    Auth::forgetGuards();
    $this->flushSession();
    $this->actingAs($real, 'web')->get('/admin')->assertOk();
});
