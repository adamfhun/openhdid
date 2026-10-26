<?php

use App\Auth\AccountLogin;
use App\Auth\Role;
use App\Enums\PrincipalType;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSource;
use App\Filament\Admin\Resources\SyncRuns\Pages\ManageSyncRuns;
use App\Identification\PinService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\Contracts\SourceReader;
use App\Sync\ExternalRecordDto;
use App\Sync\SyncExternalRecords;
use App\System\CheckStatus;
use App\System\HealthChecks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Pdo\Sqlite;

/**
 * The MariaDB collation (utf8mb4_unicode_ci) is accent- and case-insensitive:
 * a directory row with a look-alike address ("víctim@…") finds the account of
 * "victim@…". The sync has to match the address exactly, like the login does,
 * and report the look-alike as a conflict instead of taking the account over.
 * SQLite compares bytes, so the loaded account is altered to stand for the
 * look-alike match (the row and the stored address differ after loading).
 *
 * @param  list<ExternalRecordDto>  $rows
 */
function exactEmailReader(array $rows): SourceReader
{
    return new class($rows) implements SourceReader
    {
        /**
         * @param  list<ExternalRecordDto>  $rows
         */
        public function __construct(private array $rows) {}

        public function source(): SyncSource
        {
            return SyncSource::Csv;
        }

        public function label(): ?string
        {
            return 'test';
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
 * @param  list<string>  $phones
 */
function exactEmailRow(int $id, string $email, array $phones = []): ExternalRecordDto
{
    return new ExternalRecordDto($id, 'Name '.$id, $email, 'Acme', $phones, [], 'Basic', null);
}

/**
 * Makes the given account look, once loaded, as if it carried the other
 * address: what MariaDB returns for a look-alike query.
 */
function loadedAs(Model $account, string $email): Closure
{
    return function (Model $found) use ($account, $email): void {
        if ($found->getKey() === $account->getKey()) {
            $found->setRawAttributes(['email' => $email] + $found->getAttributes(), sync: true);
        }
    };
}

beforeEach(function (): void {
    app(Settings::class)->set(SettingKey::SyncUserDomains, ['staff.hu']);
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['x.hu']);
});

it('does not hand a client to a new directory record whose address only the database collation equates with it', function (): void {
    $sync = app(SyncExternalRecords::class);
    $sync->run(exactEmailReader([exactEmailRow(1001, 'victim@x.hu', ['+36301111111'])]));
    $victim = Client::query()->where('email', 'victim@x.hu')->sole();
    $victimRecordId = $victim->external_record_id;
    app(PinService::class)->setPin($victim, '123456');
    Log::spy();

    Client::retrieved(loadedAs($victim, 'víctim@x.hu'));
    try {
        $run = $sync->run(exactEmailReader([exactEmailRow(1002, 'victim@x.hu', ['+36302222222'])]));
    } finally {
        Client::flushEventListeners();
    }

    $victim = $victim->fresh();
    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and($victim->external_record_id)->toBe($victimRecordId, 'the account stays with its own record')
        ->and($victim->email)->toBe('victim@x.hu')
        ->and($victim->hasPin())->toBeTrue()
        ->and($victim->phoneNumbers()->pluck('number_e164')->all())->toBe(['+36301111111'])
        ->and(ExternalRecord::query()->where('external_id', 1002)->sole()->client)->toBeNull()
        ->and(Client::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'account.rebound')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'account.access_revoked')->exists())->toBeFalse('the owner is not signed out by a stranger\'s row')
        ->and($run->stats['email_conflicts'])->toBe(1)
        ->and($run->stats['email_conflict_samples'][0])->toMatchArray(['external_id' => 1002, 'kind' => 'client', 'email' => 'victim@x.hu'])
        ->and(AuditLog::query()->where('event', 'sync.email_conflict')->where('subject_id', $victim->id)->exists())->toBeTrue()
        ->and(app(AccountLogin::class)->findByEmail(PrincipalType::Client, 'victim@x.hu')?->getKey())->toBe($victim->id);
    Log::shouldHaveReceived('warning')->once();
});

it('does not bind the bootstrapped administrator to a directory row whose address only the database collation equates with it', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->artisan('hdid:make-admin', ['email' => 'root@staff.hu', '--password' => 'a-long-password-1'])->assertSuccessful();
    $admin = User::query()->where('email', 'root@staff.hu')->sole();
    $localRecordId = $admin->external_record_id;

    User::retrieved(loadedAs($admin, 'róot@staff.hu'));
    try {
        $run = app(SyncExternalRecords::class)->run(exactEmailReader([exactEmailRow(7001, 'root@staff.hu')]));
    } finally {
        User::flushEventListeners();
    }

    $admin = $admin->fresh();
    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and($admin->email)->toBe('root@staff.hu')
        ->and($admin->external_record_id)->toBe($localRecordId, 'the local bootstrap record stays')
        ->and(ExternalRecord::query()->where('external_id', 7001)->sole()->user)->toBeNull()
        ->and(AuditLog::query()->where('event', 'account.email_changed')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'account.rebound')->exists())->toBeFalse()
        ->and($run->stats['email_conflicts'])->toBe(1)
        ->and($run->stats['email_conflict_samples'][0])->toMatchArray(['external_id' => 7001, 'kind' => 'user', 'email' => 'root@staff.hu'])
        ->and(AuditLog::query()->where('event', 'sync.email_conflict')->where('subject_id', $admin->id)->exists())->toBeTrue()
        ->and(app(AccountLogin::class)->findByEmail(PrincipalType::User, 'root@staff.hu')?->getKey())->toBe($admin->id);
});

it('still links and adopts accounts whose address differs from the row only in letter case', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->artisan('hdid:make-admin', ['email' => 'root@staff.hu', '--password' => 'a-long-password-1'])->assertSuccessful();
    $admin = User::query()->where('email', 'root@staff.hu')->sole();
    $client = Client::factory()->create(['email' => 'a@x.hu']);

    User::retrieved(loadedAs($admin, 'Root@Staff.hu'));
    Client::retrieved(loadedAs($client, 'A@x.hu'));
    try {
        $run = app(SyncExternalRecords::class)->run(exactEmailReader([exactEmailRow(7001, 'root@staff.hu'), exactEmailRow(1001, 'a@x.hu')]));
    } finally {
        User::flushEventListeners();
        Client::flushEventListeners();
    }

    expect($run->stats['email_conflicts'])->toBe(0)
        ->and($admin->fresh()->externalRecord->external_id)->toBe(7001)
        ->and($client->fresh()->externalRecord->external_id)->toBe(1001);
});

/**
 * Puts a collation as lenient as MariaDB's utf8mb4_unicode_ci (letter case,
 * accents and width folded) on the account e-mail columns, so every lookup
 * and the unique index equate look-alike addresses, as in production.
 */
function useLenientEmailCollation(): void
{
    $fold = fn (string $value): string => (string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize(mb_strtolower($value), Normalizer::FORM_KD));
    $compare = fn (string $a, string $b): int => strcmp($fold($a), $fold($b));
    $pdo = DB::connection()->getPdo();
    $pdo instanceof Sqlite ? $pdo->createCollation('unicode_ci', $compare) : $pdo->sqliteCreateCollation('unicode_ci', $compare);

    foreach (['clients', 'users', 'external_records'] as $table) {
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->string('email')->collation('unicode_ci')->change());
    }
}

it('keeps the owner\'s client and record in either row order under a collation as lenient as MariaDB\'s', function (array $order): void {
    useLenientEmailCollation();
    $sync = app(SyncExternalRecords::class);
    $sync->run(exactEmailReader([exactEmailRow(1001, 'victim@x.hu', ['+36301111111'])]));
    $victim = Client::query()->where('email', 'victim@x.hu')->sole();
    expect(Client::query()->where('email', 'víctim@x.hu')->value('id'))->toBe($victim->id, 'control: the collation equates the two addresses');

    $rows = ['owner' => exactEmailRow(1001, 'victim@x.hu', ['+36301111111']), 'look-alike' => exactEmailRow(1002, 'víctim@x.hu', ['+36302222222'])];
    $runs = [];
    foreach ([1, 2] as $ignored) {
        $runs[] = $sync->run(exactEmailReader(array_map(fn (string $key): ExternalRecordDto => $rows[$key], $order)));
    }

    $victim = $victim->fresh();
    foreach ($runs as $run) {
        expect($run->status)->toBe(SyncRunStatus::Completed)
            ->and($run->stats['email_conflicts'])->toBe(1)
            ->and($run->stats['incoming']['duplicate_email'])->toBe(0, 'the owner\'s row is never pushed out of the run');
    }
    expect($victim->externalRecord->external_id)->toBe(1001)
        ->and($victim->externalRecord->missed_runs)->toBe(0)
        ->and($victim->email)->toBe('victim@x.hu')
        ->and($victim->phoneNumbers()->pluck('number_e164')->all())->toBe(['+36301111111'])
        ->and(ExternalRecord::query()->where('external_id', 1002)->sole()->client)->toBeNull()
        ->and(Client::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'account.rebound')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'account.access_revoked')->exists())->toBeFalse()
        ->and(app(HealthChecks::class)->syncApi()->status)->toBe(CheckStatus::Warn)
        ->and(app(HealthChecks::class)->syncApi()->detail)->toContain('possibly written differently (accents, full-width characters, look-alike domain)');
})->with([
    'owner first' => [['owner', 'look-alike']],
    'look-alike first' => [['look-alike', 'owner']],
]);

it('links the bootstrapped administrator only to the row with its own address under a collation as lenient as MariaDB\'s', function (array $order): void {
    useLenientEmailCollation();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->artisan('hdid:make-admin', ['email' => 'root@staff.hu', '--password' => 'a-long-password-1'])->assertSuccessful();
    $admin = User::query()->where('email', 'root@staff.hu')->sole();

    $rows = ['owner' => exactEmailRow(7002, 'root@staff.hu'), 'look-alike' => exactEmailRow(7001, 'róot@staff.hu')];
    $run = app(SyncExternalRecords::class)->run(exactEmailReader(array_map(fn (string $key): ExternalRecordDto => $rows[$key], $order)));

    $admin = $admin->fresh();
    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and($run->stats['email_conflicts'])->toBe(1)
        ->and($admin->email)->toBe('root@staff.hu')
        ->and($admin->externalRecord->external_id)->toBe(7002)
        ->and(AuditLog::query()->where('event', 'account.email_changed')->exists())->toBeFalse()
        ->and(app(AccountLogin::class)->findByEmail(PrincipalType::User, 'root@staff.hu')?->getKey())->toBe($admin->id);
})->with([
    'owner first' => [['owner', 'look-alike']],
    'look-alike first' => [['look-alike', 'owner']],
]);

it('keeps the bootstrapped administrator off a directory row with a look-alike address that was already there', function (): void {
    useLenientEmailCollation();
    $this->seed(RolesAndPermissionsSeeder::class);
    app(SyncExternalRecords::class)->run(exactEmailReader([exactEmailRow(7001, 'róot@staff.hu')]));
    expect(ExternalRecord::query()->where('email', 'root@staff.hu')->value('external_id'))->toBe(7001, 'control: the collation equates the two addresses');

    $this->artisan('hdid:make-admin', ['email' => 'root@staff.hu', '--password' => 'a-long-password-1'])
        ->expectsOutputToContain('róot@staff.hu')
        ->assertSuccessful();
    $admin = User::query()->get()->sole();
    expect($admin->email)->toBe('root@staff.hu')
        ->and($admin->externalRecord->isLocal())->toBeTrue('a local record of its own, not the look-alike one');

    $run = app(SyncExternalRecords::class)->run(exactEmailReader([exactEmailRow(7001, 'róot@staff.hu'), exactEmailRow(7002, 'root@staff.hu')]));

    $admin = $admin->fresh();
    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and($admin->email)->toBe('root@staff.hu')
        ->and($admin->externalRecord->external_id)->toBe(7002)
        ->and(AuditLog::query()->where('event', 'account.email_changed')->exists())->toBeFalse()
        ->and(app(AccountLogin::class)->findByEmail(PrincipalType::User, 'root@staff.hu')?->getKey())->toBe($admin->id);
});

it('refuses hdid:make-admin for an address that only the database collation equates with an existing account', function (): void {
    useLenientEmailCollation();
    $this->seed(RolesAndPermissionsSeeder::class);
    $other = User::factory()->withRole(Role::Agent)->create(['email' => 'róot@staff.hu', 'password' => 'their-own-password-1']);

    $this->artisan('hdid:make-admin', ['email' => 'root@staff.hu', '--password' => 'a-long-password-1'])
        ->expectsOutputToContain('róot@staff.hu')
        ->assertFailed();

    $other = $other->fresh();
    expect(User::query()->count())->toBe(1)
        ->and($other->hasRole(Role::SuperAdmin->value))->toBeFalse()
        ->and(Hash::check('their-own-password-1', $other->password))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'account.admin_made_by_command')->exists())->toBeFalse();
});

it('names on the run page why each e-mail conflict happened', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');
    $run = SyncRun::factory()->create(['stats' => ['email_conflicts' => 3, 'email_conflict_samples' => [
        ['external_id' => 1, 'kind' => 'client', 'email' => 'new@x.hu', 'kept' => 'old@x.hu'],
        ['external_id' => 2, 'kind' => 'client', 'email' => 'víctim@x.hu', 'kept' => 'victim@x.hu', 'look_alike' => true],
        ['external_id' => 3, 'kind' => 'user', 'email' => 'real@corp.hu', 'kept' => 'bence@example.test', 'demo' => true],
    ]]]);

    $page = Livewire::test(ManageSyncRuns::class)->mountAction(TestAction::make('view')->table($run))->instance();
    $samples = collect($page->getSchema($page->getMountedActionSchemaName())->getFlatComponents(withHidden: true))
        ->first(fn ($component): bool => $component instanceof RepeatableEntry && $component->getName() === 'stats.email_conflict_samples');

    expect($samples->isVisible())->toBeTrue()
        ->and(collect($samples->getItems())->map(fn (FilamentSchema $item): mixed => $item->getComponent(fn ($component): bool => $component instanceof Entry && $component->getName() === 'case')?->getState())->values()->all())
        ->toBe([
            __('Renamed to an address another account holds'),
            __('New record with an address already on another account'),
            __('Demo record, not handed over in production'),
        ]);
});

it('names on the console why each e-mail conflict happened', function (): void {
    $run = SyncRun::factory()->create(['stats' => ['email_conflicts' => 3, 'email_conflict_samples' => [
        ['external_id' => 1, 'kind' => 'client', 'email' => 'new@x.hu', 'kept' => 'old@x.hu'],
        ['external_id' => 2, 'kind' => 'client', 'email' => 'víctim@x.hu', 'kept' => 'victim@x.hu', 'look_alike' => true],
        ['external_id' => 3, 'kind' => 'user', 'email' => 'real@corp.hu', 'kept' => 'bence@example.test', 'demo' => true],
    ]]]);
    $this->mock(SyncExternalRecords::class)->shouldReceive('run')->once()->andReturn($run);

    $this->artisan('hdid:emd-sync', ['--file' => 'unused.csv'])
        ->expectsOutputToContain('external id 1 wants new@x.hu, kept old@x.hu (another account holds the new address)')
        ->expectsOutputToContain('external id 2 wants víctim@x.hu, kept victim@x.hu (another account holds this address, possibly written differently)')
        ->expectsOutputToContain('external id 3 wants real@corp.hu, kept bence@example.test (a demo record, not handed over in production)')
        ->assertSuccessful();
});
