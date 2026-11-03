<?php

use App\Auth\Role;
use App\Enums\ApiKeyScope;
use App\Enums\IdSessionStatus;
use App\Enums\OneTimeCodePurpose;
use App\Enums\OutboundMessageStatus;
use App\Enums\PhoneNumberSource;
use App\Enums\SyncRunStatus;
use App\Filament\Admin\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Filament\Admin\Resources\IdSessions\Pages\ManageIdSessions;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Identification\ClientAnswers;
use App\Identification\PinService;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\Client;
use App\Models\ClientAnswer;
use App\Models\ClientPhoneNumber;
use App\Models\ExternalRecord;
use App\Models\IdSession;
use App\Models\OneTimeCode;
use App\Models\OutboundMessage;
use App\Models\Question;
use App\Models\SsoIdentity;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Support\HuDate;
use App\System\CheckStatus;
use App\System\HealthChecks;
use App\System\Retention;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

it('prunes business records after the general period and throwaway artefacts after the short-lived one', function (): void {
    Storage::fake('local');
    app(Settings::class)->set(SettingKey::RetentionGeneralYears, 5);
    app(Settings::class)->set(SettingKey::RetentionShortLivedYears, 2);
    $client = Client::factory()->create();

    $oldSession = IdSession::factory()->create(['client_id' => $client->id, 'started_at' => now()->subYears(6), 'status' => IdSessionStatus::Passed]);
    $freshSession = IdSession::factory()->create(['client_id' => $client->id, 'started_at' => now()->subYears(4)]);
    $oldCall = Call::factory()->create(['arrived_at' => now()->subYears(6)]);
    $oldCall->delete();
    $freshCall = Call::factory()->create(['arrived_at' => now()->subDays(2)]);
    $oldMessage = OutboundMessage::factory()->create(['created_at' => now()->subYears(6), 'status' => OutboundMessageStatus::Sent]);
    $freshMessage = OutboundMessage::factory()->create();
    $oldRun = SyncRun::factory()->create(['started_at' => now()->subYears(6)]);
    $oldAudit = AuditLog::query()->create(['event' => 'x', 'context' => []]);
    AuditLog::query()->whereKey($oldAudit->id)->update(['created_at' => now()->subYears(6)]);
    $code = fn ($expires) => OneTimeCode::query()->create(['client_id' => $client->id, 'purpose' => OneTimeCodePurpose::cases()[0], 'code_hash' => 'h', 'expires_at' => $expires, 'max_attempts' => 5]);
    $oldCode = $code(now()->subYears(3));
    $recentCode = $code(now()->subYear());
    Storage::disk('local')->put(Retention::UPLOAD_DIRECTORY.'/old.csv', 'a');
    touch(Storage::disk('local')->path(Retention::UPLOAD_DIRECTORY.'/old.csv'), now()->subYears(3)->getTimestamp());
    Storage::disk('local')->put(Retention::UPLOAD_DIRECTORY.'/new.csv', 'b');

    $removed = app(Retention::class)->prune();

    // The six-year-old audit entry left the table for an archive file before
    // the general-period delete ran, so the archive counts it, not the delete.
    expect($removed)->toMatchArray(['id_sessions' => 1, 'calls' => 1, 'outbound_messages' => 1, 'sync_runs' => 1, 'one_time_codes' => 1, 'sync_uploads' => 1, 'audit_log' => 0])
        ->and($removed['audit_archived'])->toBeGreaterThanOrEqual(1)
        ->and($removed['audit_archive_files'])->toBe(1, 'a six-year-old archive file is past the general period as well')
        ->and(IdSession::query()->withTrashed()->find($oldSession->id))->toBeNull()
        ->and(IdSession::query()->find($freshSession->id))->not->toBeNull()
        ->and(Call::query()->withTrashed()->find($oldCall->id))->toBeNull()
        ->and(Call::query()->find($freshCall->id))->not->toBeNull()
        ->and(OutboundMessage::query()->find($oldMessage->id))->toBeNull()
        ->and(OutboundMessage::query()->find($freshMessage->id))->not->toBeNull()
        ->and(SyncRun::query()->withTrashed()->find($oldRun->id))->toBeNull()
        ->and(AuditLog::query()->find($oldAudit->id))->toBeNull()
        ->and(OneTimeCode::query()->find($oldCode->id))->toBeNull()
        ->and(OneTimeCode::query()->find($recentCode->id))->not->toBeNull()
        ->and(Storage::disk('local')->exists(Retention::UPLOAD_DIRECTORY.'/old.csv'))->toBeFalse()
        ->and(Storage::disk('local')->exists(Retention::UPLOAD_DIRECTORY.'/new.csv'))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'retention.pruned')->exists())->toBeTrue()
        ->and(app(HealthChecks::class)->retention()->status)->toBe(CheckStatus::Ok);

    $this->artisan('hdid:prune')->assertSuccessful();
});

it('warns on the status page while the prune task has never run or is overdue', function (): void {
    $checks = app(HealthChecks::class);
    expect($checks->retention()->status)->toBe(CheckStatus::Warn);

    Cache::put(Retention::LAST_RUN_KEY, now()->subDays(4)->toIso8601String());
    expect($checks->retention()->status)->toBe(CheckStatus::Warn);

    Cache::put(Retention::LAST_RUN_KEY, now()->toIso8601String());
    expect($checks->retention()->status)->toBe(CheckStatus::Ok);
});

it('serves a machine-readable health endpoint with 503 on failure and no details', function (): void {
    config()->set('queue.default', 'database');

    $response = $this->getJson('/health');
    $response->assertStatus(503)->assertJsonPath('status', 'fail');
    expect(collect($response->json('checks'))->firstWhere('key', 'queue')['status'])->toBe('fail')
        ->and($response->json('checks.0'))->not->toHaveKey('detail');

    config()->set('queue.default', 'sync');
    Cache::put(HealthChecks::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String());
    config()->set('sso.dummy', null);
    $ok = $this->getJson('/health');
    expect($ok->json('status'))->toBe(collect($ok->json('checks'))->contains(fn ($c) => $c['status'] === 'fail') ? 'fail' : 'ok');
});

it('exposes the health checks as a command with a meaningful exit code', function (): void {
    config()->set('queue.default', 'database');
    $this->artisan('hdid:health')->assertExitCode(2);

    config()->set('queue.default', 'sync');
    Cache::put(HealthChecks::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String());
    Cache::put(Retention::LAST_RUN_KEY, now()->toIso8601String());
    $failing = collect(app(HealthChecks::class)->all())->filter(fn ($c) => $c->status === CheckStatus::Fail);
    $this->artisan('hdid:health', ['--json' => true])->assertExitCode($failing->isEmpty() ? 0 : 2);
});

it('fails the sms check on the log driver in production only', function (): void {
    config()->set('hdid.sms.driver', 'log');
    expect(app(HealthChecks::class)->sms()->status)->toBe(CheckStatus::Warn);

    app()->detectEnvironment(fn () => 'production');
    expect(app(HealthChecks::class)->sms()->status)->toBe(CheckStatus::Fail);
    app()->detectEnvironment(fn () => 'testing');
});

it('fails the sync check when no successful run happened within twice the expected interval', function (): void {
    app(Settings::class)->set(SettingKey::SyncExpectedIntervalHours, 24);
    SyncRun::factory()->create(['status' => 'completed', 'started_at' => now()->subDays(3), 'finished_at' => now()->subDays(3)]);
    expect(app(HealthChecks::class)->syncApi()->status)->toBe(CheckStatus::Fail);

    SyncRun::factory()->create(['status' => 'completed', 'started_at' => now()->subHours(3), 'finished_at' => now()->subHours(3)]);
    expect(app(HealthChecks::class)->syncApi()->status)->toBe(CheckStatus::Ok);

    SyncRun::factory()->create(['status' => 'completed', 'dry_run' => true, 'started_at' => now(), 'finished_at' => now()]);
    $this->travel(50)->hours();
    expect(app(HealthChecks::class)->syncApi()->status)->toBe(CheckStatus::Fail, 'a dry run is not a real run');
});

it('prints the signing secret with a mobile backend key created from the command line', function (): void {
    $this->artisan('hdid:api-key:create', ['name' => 'Mobil', 'scope' => 'mobile_backend'])
        ->expectsOutputToContain('hdid_')
        ->expectsOutputToContain('Signing secret')
        ->assertSuccessful();

    expect(ApiKey::query()->where('name', 'Mobil')->sole()->requiresSignature())->toBeTrue();
});

it('fails the api key check while a mobile backend key has no signing secret', function (): void {
    config()->set('hdid.callcenter.api_key', 'legacy');
    $old = ApiKey::factory()->scope(ApiKeyScope::MobileBackend)->create(['name' => 'Régi mobil']);

    $check = app(HealthChecks::class)->apiKeys();
    expect($check->status)->toBe(CheckStatus::Fail)
        ->and($check->detail)->toContain('Régi mobil');

    $old->rotateSigningSecret();
    expect(app(HealthChecks::class)->apiKeys()->status)->toBe(CheckStatus::Ok);
});

it('creates an api key from the command line and prints it once', function (): void {
    $this->artisan('hdid:api-key:create', ['name' => 'PBX', 'scope' => 'callcenter'])
        ->expectsOutputToContain('hdid_')
        ->assertSuccessful();
    $this->artisan('hdid:api-key:create', ['name' => 'PBX', 'scope' => 'nope'])->assertFailed();

    $key = ApiKey::query()->where('name', 'PBX')->first();
    expect($key->scope)->toBe(ApiKeyScope::CallCenter);

    // The use is stamped on the key only; an audit row per minute per key told nothing more.
    $key->touchLastUsed();
    $key->touchLastUsed();
    expect($key->fresh()->last_used_at)->not->toBeNull()
        ->and(AuditLog::query()->where('event', 'api_key.used')->count())->toBe(0);
});

it('sends smoke-test messages without touching a client', function (): void {
    $this->artisan('hdid:test-mail', ['address' => 'ops@example.com', '--now' => true])->assertSuccessful();
    $this->artisan('hdid:test-mail', ['address' => 'nope'])->assertFailed();
    $this->artisan('hdid:test-sms', ['number' => '06301234567', '--now' => true])->assertSuccessful();

    expect(OutboundMessage::query()->whereNull('client_id')->where('status', OutboundMessageStatus::Sent)->count())->toBe(2);
});

it('lets an admin export the audit log and the identification sessions', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->create();
    $admin->syncRoles([Role::SuperAdmin->value]);
    AuditLog::query()->create(['event' => 'demo.event', 'context' => ['k' => 'v']]);
    IdSession::factory()->create(['client_id' => Client::factory()->create()->id]);

    $this->actingAs($admin);
    Livewire::test(ManageAuditLogs::class)
        ->callAction('export')
        ->assertFileDownloaded();
    Livewire::test(ManageIdSessions::class)
        ->callAction('export')
        ->assertFileDownloaded();
});

it('deletes an EMD upload left for a day that no waiting or running run names, and fails a run nobody picked up', function (): void {
    Storage::fake('local');
    $disk = Storage::disk('local');
    $file = fn (string $char): string => Retention::UPLOAD_DIRECTORY.'/'.str_repeat($char, 40).'.csv';
    $disk->put($file('a'), 'orphan');
    touch($disk->path($file('a')), now()->subDays(2)->getTimestamp());
    $disk->put($file('b'), 'fresh');
    $disk->put($file('c'), 'running');
    touch($disk->path($file('c')), now()->subDays(2)->getTimestamp());
    SyncRun::factory()->create(['status' => SyncRunStatus::Running, 'started_at' => now()->subDays(2), 'file_name' => str_repeat('c', 40).'.csv']);
    $disk->put($file('d'), 'stale');
    touch($disk->path($file('d')), now()->subDays(2)->getTimestamp());
    $stale = SyncRun::factory()->create(['status' => SyncRunStatus::Queued, 'started_at' => now()->subDays(2), 'file_name' => str_repeat('d', 40).'.csv']);

    $removed = app(Retention::class)->prune();

    // The abandoned sync's upload goes with the abandon, the orphan with the upload sweep.
    expect($removed)->toMatchArray(['sync_runs_abandoned' => 1, 'sync_uploads' => 1])
        ->and($disk->exists($file('a')))->toBeFalse()
        ->and($disk->exists($file('b')))->toBeTrue()
        ->and($disk->exists($file('c')))->toBeTrue('a running worker reads it')
        ->and($disk->exists($file('d')))->toBeFalse()
        ->and($stale->fresh()->status)->toBe(SyncRunStatus::Failed);
});

it('clears the decryptable copy and the lookup of a code that can no longer be used, at the next prune', function (): void {
    $client = Client::factory()->create();
    $code = fn (array $attributes) => OneTimeCode::query()->forceCreate($attributes + [
        'client_id' => $client->id, 'purpose' => OneTimeCodePurpose::MobileOtp, 'code_hash' => 'h',
        'lookup' => bin2hex(random_bytes(32)), 'secret_encrypted' => encrypt('12345678'),
        'expires_at' => now()->addMinutes(5), 'max_attempts' => 5,
    ]);
    $expired = $code(['expires_at' => now()->subMinute()]);
    $exhausted = $code(['attempts' => 5]);
    $live = $code([]);

    $removed = app(Retention::class)->prune();

    expect($removed['one_time_code_copies'])->toBe(2)
        ->and(DB::table('one_time_codes')->where('id', $expired->id)->first())->secret_encrypted->toBeNull()->lookup->toBeNull()
        ->and(DB::table('one_time_codes')->where('id', $exhausted->id)->first())->secret_encrypted->toBeNull()->lookup->toBeNull()
        ->and(DB::table('one_time_codes')->where('id', $live->id)->first())->secret_encrypted->not->toBeNull()->lookup->not->toBeNull();
});

it('deletes API tokens past their expiry or lifetime at the next prune', function (): void {
    config()->set('sanctum.expiration', 43200);
    $client = Client::factory()->create();
    $fresh = $client->createToken('fresh')->accessToken;
    $old = $client->createToken('old')->accessToken;
    $old->forceFill(['created_at' => now()->subDays(31)])->save();
    $expired = $client->createToken('expired', expiresAt: now()->subMinute())->accessToken;

    expect(app(Retention::class)->prune()['api_tokens'])->toBe(2)
        ->and($client->tokens()->pluck('id')->all())->toBe([$fresh->id]);
});

it('deletes the company sign-in bindings of accounts that no longer exist, and keeps those of closed accounts', function (): void {
    $gone = SsoIdentity::factory()->create(['principal_id' => (string) Str::uuid7()]);
    $closed = SsoIdentity::factory()->forAccount(User::factory()->closed()->create())->create();
    $deleted = User::factory()->create();
    $softDeleted = SsoIdentity::factory()->forAccount($deleted)->create();
    $deleted->delete();

    expect(app(Retention::class)->prune()['sso_bindings'])->toBe(1)
        ->and(SsoIdentity::query()->pluck('id')->sort()->values()->all())->toBe(collect([$closed->id, $softDeleted->id])->sort()->values()->all());
});

it('deletes expired rows of the database cache store and keeps the live ones', function (): void {
    DB::table('cache')->insert([
        ['key' => 'hdid-test-expired', 'value' => 's:1:"x";', 'expiration' => now()->subMinute()->getTimestamp()],
        ['key' => 'hdid-test-live', 'value' => 's:1:"y";', 'expiration' => now()->addHour()->getTimestamp()],
    ]);

    expect(app(Retention::class)->prune()['cache_rows'])->toBeGreaterThanOrEqual(1)
        ->and(DB::table('cache')->where('key', 'hdid-test-expired')->exists())->toBeFalse()
        ->and(DB::table('cache')->where('key', 'hdid-test-live')->exists())->toBeTrue();
});

/**
 * A client with everything an account can carry, deleted the given years ago.
 */
function clientDeletedYearsAgo(int $years): Client
{
    $client = Client::factory()->synced()->withPin('482913')->withPhone()->create();
    app(ClientAnswers::class)->save($client, Question::factory()->create(), 'kék');
    OneTimeCode::query()->forceCreate(['client_id' => $client->id, 'purpose' => OneTimeCodePurpose::MobileOtp, 'code_hash' => 'h', 'expires_at' => now()->addMinutes(5), 'max_attempts' => 5]);
    SsoIdentity::factory()->forAccount($client)->create();
    $client->createToken('mobile');
    $client->delete();
    Client::withTrashed()->whereKey($client->id)->update(['deleted_at' => now()->subYears($years)]);

    return $client;
}

it('deletes for good a client deleted longer ago than the general period, with what it carried', function (): void {
    app(Settings::class)->set(SettingKey::RetentionGeneralYears, 5);
    $client = clientDeletedYearsAgo(6);
    $record = $client->externalRecord;

    $removed = app(Retention::class)->prune();

    expect($removed['deleted_clients'])->toBe(1)
        ->and(Client::withTrashed()->find($client->id))->toBeNull()
        ->and(ClientAnswer::withTrashed()->where('client_id', $client->id)->exists())->toBeFalse()
        ->and(ClientPhoneNumber::withTrashed()->where('client_id', $client->id)->exists())->toBeFalse()
        ->and(OneTimeCode::query()->where('client_id', $client->id)->exists())->toBeFalse()
        ->and(SsoIdentity::query()->where('principal_id', $client->id)->exists())->toBeFalse()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $client->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'client.force_deleted')->where('subject_id', $client->id)->exists())->toBeTrue()
        ->and(ExternalRecord::withTrashed()->find($record->id))->not->toBeNull('the EMD record is the directory\'s, not the account\'s');

    // The PIN is free again: the uniqueness check counted the deleted client until now.
    $other = Client::factory()->create();
    expect(Client::withTrashed()->whereKeyNot($other->id)->where('pin_lookup', app(PinService::class)->lookupOf('482913'))->exists())->toBeFalse();
});

it('keeps a client deleted within the general period restorable', function (): void {
    app(Settings::class)->set(SettingKey::RetentionGeneralYears, 5);
    $client = clientDeletedYearsAgo(4);

    expect(app(Retention::class)->prune()['deleted_clients'])->toBe(0)
        ->and(Client::onlyTrashed()->find($client->id))->not->toBeNull();
});

it('deletes for good a staff account deleted longer ago than the general period, with its roles and tokens', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(Settings::class)->set(SettingKey::RetentionGeneralYears, 5);
    $user = User::factory()->withRole(Role::Agent)->create();
    $user->createToken('x');
    SsoIdentity::factory()->forAccount($user)->create();
    $user->delete();
    User::withTrashed()->whereKey($user->id)->update(['deleted_at' => now()->subYears(6)]);

    expect(app(Retention::class)->prune()['deleted_users'])->toBe(1)
        ->and(User::withTrashed()->find($user->id))->toBeNull()
        ->and(DB::table('model_has_roles')->where('model_id', $user->id)->exists())->toBeFalse()
        ->and(SsoIdentity::query()->where('principal_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->exists())->toBeFalse();
});

it('deletes soft-deleted phone numbers and EMD records past the general period without touching a live PIN or a bound record', function (): void {
    app(Settings::class)->set(SettingKey::RetentionGeneralYears, 5);
    $client = Client::factory()->synced()->withPin('482913')->withPhone('+36301112233')->create();
    $old = $client->phoneNumbers()->create(['number_e164' => '+36304445566', 'source' => PhoneNumberSource::Admin]);
    $old->delete();
    ClientPhoneNumber::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subYears(6)]);
    $loose = ExternalRecord::factory()->create();
    $loose->delete();
    ExternalRecord::withTrashed()->whereKey($loose->id)->update(['deleted_at' => now()->subYears(6)]);
    $bound = $client->externalRecord;
    $bound->delete();
    ExternalRecord::withTrashed()->whereKey($bound->id)->update(['deleted_at' => now()->subYears(6)]);

    $removed = app(Retention::class)->prune();

    expect($removed)->toMatchArray(['deleted_phone_numbers' => 1, 'deleted_records' => 1])
        ->and(ClientPhoneNumber::withTrashed()->find($old->id))->toBeNull()
        ->and($client->fresh()->pin_hash)->not->toBeNull()
        ->and($client->fresh()->external_record_id)->toBe($bound->id)
        ->and(ExternalRecord::withTrashed()->find($loose->id))->toBeNull();
});

it('says in the delete dialogs until when a deleted account can be restored, and shows the day on the Deleted lists', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create());
    Filament::setCurrentPanel('admin');
    app(Settings::class)->set(SettingKey::RetentionGeneralYears, 5);
    $until = app(Retention::class)->purgeDateOf()->format(HuDate::DATE);
    $client = Client::factory()->create();
    $staff = User::factory()->withRole(Role::Agent)->create();

    Livewire::test(ListClients::class)->mountTableAction('delete', $client)->assertMountedActionModalSee($until);
    Livewire::test(ListUsers::class)->mountTableAction('delete', $staff)->assertMountedActionModalSee($until);

    $client->delete();
    Livewire::test(ListClients::class)->filterTable('trashed', false)
        ->assertSee(__('deleted for good on :date', ['date' => $until]));
});
