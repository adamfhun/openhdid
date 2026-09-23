<?php

use App\Auth\Role;
use App\Enums\SyncRunStatus;
use App\Filament\Admin\Resources\SyncRuns\Pages\ManageSyncRuns;
use App\Jobs\RunDirectorySync;
use App\Models\Client;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\QueuedSyncs;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['client.hu']);
    Queue::fake();
    Storage::fake('local');
    Http::preventStrayRequests();
    config()->set([
        'queue.default' => 'database', 'queue.connections.database.retry_after' => 1260,
        'hdid.sync.driver' => 'spreadsheet', 'hdid.sync.export_format' => 'csv',
        'hdid.sync.api_url' => 'https://directory.test/export',
        'hdid.sync.login_url' => 'https://directory.test/login',
        'hdid.sync.refresh_url' => 'https://directory.test/refresh',
        'hdid.sync.username' => 'u', 'hdid.sync.password' => 'p',
    ]);
    app(Settings::class)->set(SettingKey::SyncExportPayload, '{"ids":"1"}');
});

it('queues a panel trial run and shows the completed counts and escaped preview', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin);
    Http::fake([
        'https://directory.test/login' => Http::response(['access_token' => 'a', 'refresh_token' => 'r']),
        'https://directory.test/export' => Http::response("external_user_id,name,email,phone\n1,<script>alert(1)</script>,one@client.hu,hibás szám\n", 200, ['Content-Type' => 'text/csv']),
    ]);

    Livewire::test(ManageSyncRuns::class)->callAction('syncApiDryRun');
    $run = SyncRun::query()->sole();
    expect($run->status)->toBe(SyncRunStatus::Queued)->and($run->dry_run)->toBeTrue();
    Http::assertNothingSent();
    Queue::assertPushed(RunDirectorySync::class, fn (RunDirectorySync $job) => $job->runId === $run->id);

    (new RunDirectorySync($run->id))->handle(app(QueuedSyncs::class));
    $run->refresh();
    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and($run->stats['current'])->toBe(['users' => 1, 'clients' => 0])
        ->and($run->stats['incoming']['clients'])->toBe(1)
        ->and($run->stats['invalid_phones'])->toBe(1)
        ->and(Client::query()->count())->toBe(0);

    $component = Livewire::test(ManageSyncRuns::class)->mountAction(TestAction::make('view')->table($run));
    $page = $component->instance();
    $html = $page->getSchema($page->getMountedActionSchemaName())->toHtml();
    expect($html)->toContain(__('Current active accounts'), __('Incoming records after domain filtering'), __('Invalid phone numbers (first 50)'), 'hibás szám', 'one@client.hu')
        ->not->toContain('<script>alert(1)</script>');
    Http::assertSentCount(2);
});

it('rejects overlapping queued syncs', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create();
    app(QueuedSyncs::class)->start($admin, true);

    expect(fn () => app(QueuedSyncs::class)->start($admin, false))->toThrow(RuntimeException::class);
    Queue::assertPushed(RunDirectorySync::class, 1);
    expect(SyncRun::query()->count())->toBe(1);
});

it('puts the panel sync on the configured queue so a long run does not hold up messages', function (?string $queue, string $expected): void {
    config()->set('hdid.sync.queue', $queue ?? 'default');
    app(QueuedSyncs::class)->start(User::factory()->withRole(Role::Admin)->create(), true);

    Queue::assertPushedOn($expected, RunDirectorySync::class);
})->with([
    'dedicated sync queue' => ['sync', 'sync'],
    'single-worker default' => [null, 'default'],
]);

it('rejects users without sync permission at dispatch and after permission is revoked', function (): void {
    $agent = User::factory()->withRole(Role::Agent)->create();
    expect(fn () => app(QueuedSyncs::class)->start($agent, true))
        ->toThrow(HttpException::class);
    Queue::assertNothingPushed();

    $admin = User::factory()->withRole(Role::Admin)->create();
    $run = app(QueuedSyncs::class)->start($admin, true);
    $admin->syncRoles([Role::Agent->value]);

    expect(fn () => app(QueuedSyncs::class)->execute($run->id))
        ->toThrow(HttpException::class);
    expect($run->fresh()->status)->toBe(SyncRunStatus::Failed);
    Http::assertNothingSent();
});

it('refuses synchronous queues and insufficient reservation timeouts', function (string $driver, int $retryAfter): void {
    config()->set(['queue.connections.database.driver' => $driver, 'queue.connections.database.retry_after' => $retryAfter]);
    $admin = User::factory()->withRole(Role::Admin)->create();

    expect(fn () => app(QueuedSyncs::class)->start($admin, true))->toThrow(RuntimeException::class);
    Queue::assertNothingPushed();
    expect(SyncRun::query()->count())->toBe(0);
})->with([['sync', 1260], ['database', 90]]);

it('marks an interrupted worker run as failed and does not repeat a completed run', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $run = app(QueuedSyncs::class)->start($admin, true);
    (new RunDirectorySync($run->id))->failed(null);
    expect($run->fresh()->status)->toBe(SyncRunStatus::Failed);

    $run->forceFill(['status' => SyncRunStatus::Completed])->save();
    app(QueuedSyncs::class)->execute($run->id);
    (new RunDirectorySync($run->id))->failed(null);
    expect($run->fresh()->status)->toBe(SyncRunStatus::Completed);
    Http::assertNothingSent();
});
