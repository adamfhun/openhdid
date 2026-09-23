<?php

use App\Auth\Role;
use App\Enums\ApiKeyScope;
use App\Enums\IdSessionStatus;
use App\Enums\OneTimeCodePurpose;
use App\Enums\OutboundMessageStatus;
use App\Filament\Admin\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Filament\Admin\Resources\IdSessions\Pages\ManageIdSessions;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\OneTimeCode;
use App\Models\OutboundMessage;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\System\CheckStatus;
use App\System\HealthChecks;
use App\System\Retention;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

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

    expect($removed)->toMatchArray(['id_sessions' => 1, 'calls' => 1, 'outbound_messages' => 1, 'sync_runs' => 1, 'one_time_codes' => 1, 'sync_uploads' => 1])
        ->and($removed['audit_log'])->toBeGreaterThanOrEqual(1)
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

it('creates an api key from the command line and prints it once', function (): void {
    $this->artisan('hdid:api-key:create', ['name' => 'PBX', 'scope' => 'callcenter'])
        ->expectsOutputToContain('hdid_')
        ->assertSuccessful();
    $this->artisan('hdid:api-key:create', ['name' => 'PBX', 'scope' => 'nope'])->assertFailed();

    $key = ApiKey::query()->where('name', 'PBX')->first();
    expect($key->scope)->toBe(ApiKeyScope::CallCenter);

    $key->touchLastUsed('api.v1.callcenter.lookup');
    $key->touchLastUsed('api.v1.callcenter.lookup');
    expect(AuditLog::query()->where('event', 'api_key.used')->where('subject_id', $key->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'api_key.used')->first()->context)->toMatchArray(['scope' => 'callcenter', 'route' => 'api.v1.callcenter.lookup']);
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
    Livewire\Livewire::test(ManageAuditLogs::class)
        ->callAction('export')
        ->assertFileDownloaded();
    Livewire\Livewire::test(ManageIdSessions::class)
        ->callAction('export')
        ->assertFileDownloaded();
});
