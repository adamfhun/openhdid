<?php

use App\Audit\AuditArchive;
use App\Auth\Role;
use App\Filament\Admin\Pages\AuditArchives;
use App\Filament\Admin\Pages\ManageSettings;
use App\Filament\Admin\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Filament\Admin\Widgets\AuditEventsWidget;
use App\Models\AuditLog;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\System\CheckStatus;
use App\System\HealthChecks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('local');
});

function auditEntryOn(string $event, int $daysAgo): AuditLog
{
    $entry = AuditLog::query()->create(['event' => $event, 'context' => ['days_ago' => $daysAgo]]);
    AuditLog::query()->whereKey($entry->id)->update(['created_at' => now()->subDays($daysAgo)->setTime(10, 0)]);

    return $entry->fresh();
}

it('moves the audit days older than the hot window into daily compressed files with checksums and out of the table', function (): void {
    app(Settings::class)->set(SettingKey::RetentionAuditHotDays, 150);
    auditEntryOn('old.one', 200);
    auditEntryOn('old.two', 200);
    auditEntryOn('old.three', 160);
    $recent = auditEntryOn('recent.one', 10);

    $archive = app(AuditArchive::class);
    $result = $archive->archive();

    expect($result['days'])->toBe(2)
        ->and($result['rows'])->toBe(3)
        ->and(AuditLog::query()->whereIn('event', ['old.one', 'old.two', 'old.three'])->count())->toBe(0)
        ->and(AuditLog::query()->find($recent->id))->not->toBeNull();

    $day = now()->subDays(200)->toDateString();
    $path = AuditArchive::DIRECTORY.'/'.substr($day, 0, 4).'/audit-'.$day.'.jsonl.gz';
    expect(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(Storage::disk('local')->exists($path.'.sha256'))->toBeTrue();

    $lines = array_filter(explode("\n", (string) gzdecode((string) Storage::disk('local')->get($path))));
    expect($lines)->toHaveCount(2)
        ->and(json_decode($lines[0], true))->toMatchArray(['event' => 'old.one', 'context' => ['days_ago' => 200]])
        ->and(json_decode($lines[0], true)['created_at'])->toContain($day);

    [$sum, $name] = preg_split('/\s+/', trim((string) Storage::disk('local')->get($path.'.sha256')));
    expect($sum)->toBe(hash('sha256', (string) Storage::disk('local')->get($path)))
        ->and($name)->toBe('audit-'.$day.'.jsonl.gz');

    // One summary entry per run, and nothing left to do on the next run.
    $summary = AuditLog::query()->where('event', 'audit.archived')->sole();
    expect($summary->context)->toMatchArray(['days' => 2, 'rows' => 3, 'hot_days' => 150])
        ->and($archive->archive()['days'])->toBe(0)
        ->and($archive->lastRunAt())->not->toBeNull();

    $files = $archive->files();
    expect($files)->toHaveCount(2)
        ->and($files[0]['day'])->toBe(now()->subDays(160)->toDateString(), 'newest first')
        ->and($files[0]['sha256'])->toHaveLength(64)
        ->and($archive->summary()['files'])->toBe(2);
});

it('archives a bounded number of days per run and continues on the next one', function (): void {
    $rows = [];
    for ($i = 0; $i < AuditArchive::MAX_DAYS_PER_RUN + 1; $i++) {
        $rows[] = ['id' => (string) Str::uuid7(), 'event' => 'bulk', 'context' => '{}', 'created_at' => now()->subDays(200 + $i)];
    }
    AuditLog::query()->insert($rows);

    $archive = app(AuditArchive::class);
    expect($archive->archive()['days'])->toBe(AuditArchive::MAX_DAYS_PER_RUN)
        ->and($archive->archive()['days'])->toBe(1)
        ->and(AuditLog::query()->where('event', 'bulk')->count())->toBe(0);
});

it('deletes the archive files past the general retention period', function (): void {
    $disk = Storage::disk('local');
    foreach (['2018-01-05', now()->subDays(200)->toDateString()] as $day) {
        $disk->put(AuditArchive::DIRECTORY.'/'.substr($day, 0, 4).'/audit-'.$day.'.jsonl.gz', 'x');
        $disk->put(AuditArchive::DIRECTORY.'/'.substr($day, 0, 4).'/audit-'.$day.'.jsonl.gz.sha256', 'y');
    }
    $disk->put(AuditArchive::DIRECTORY.'/notes.txt', 'ignored');

    $archive = app(AuditArchive::class);
    expect($archive->files())->toHaveCount(2)
        ->and($archive->pruneFiles(now()->subYears(5)))->toBe(1)
        ->and($disk->exists(AuditArchive::DIRECTORY.'/2018/audit-2018-01-05.jsonl.gz'))->toBeFalse()
        ->and($disk->exists(AuditArchive::DIRECTORY.'/2018/audit-2018-01-05.jsonl.gz.sha256'))->toBeFalse()
        ->and($archive->files())->toHaveCount(1);
});

it('warns on the status page while entries older than the hot window wait for archiving', function (): void {
    $checks = app(HealthChecks::class);
    expect($checks->auditLog()->status)->toBe(CheckStatus::Ok);

    auditEntryOn('old.one', 200);
    expect($checks->auditLog()->status)->toBe(CheckStatus::Warn)
        ->and($checks->auditLog()->detail)->toContain('waiting to be archived');

    app(AuditArchive::class)->archive();
    expect($checks->auditLog()->status)->toBe(CheckStatus::Ok)
        ->and($checks->auditLog()->detail)->toContain('1 archive files');

    // Entries inside the window never warn, whatever the task did.
    auditEntryOn('warm', 100);
    expect($checks->auditLog()->status)->toBe(CheckStatus::Ok);
});

it('lists and downloads the archive files on the panel page, auditing every download', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    auditEntryOn('old.one', 200);
    app(AuditArchive::class)->archive();
    $file = app(AuditArchive::class)->files()[0];

    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin);
    $this->get('/admin/system/audit-archive')->assertOk()->assertSee($file['name'])->assertSee($file['sha256']);

    Livewire::test(AuditArchives::class)
        ->call('download', $file['name'])
        ->assertFileDownloaded($file['name']);
    Livewire::test(AuditArchives::class)
        ->call('downloadChecksum', $file['name'])
        ->assertFileDownloaded($file['name'].'.sha256');
    Livewire::test(AuditArchives::class)
        ->call('download', '../.env')
        ->assertNotFound();

    expect(AuditLog::query()->where('event', 'audit.archive_downloaded')->where('actor_id', $admin->id)->sole()->context)
        ->toMatchArray(['file' => $file['name'], 'sha256' => $file['sha256']]);

    // The audit log page tells where the older entries went, and counts the events.
    Livewire::test(ManageAuditLogs::class)->assertSee('older days are in daily archive files');
    Livewire::test(AuditEventsWidget::class)->assertSee('audit.archived');

    $this->flushSession();
    $this->actingAs(User::factory()->withRole(Role::Agent)->create())->get('/admin/system/audit-archive')->assertForbidden();
});

it('refuses a hot window shorter than the minimum on the settings page', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());

    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::RetentionAuditHotDays) => AuditArchive::MIN_HOT_DAYS - 1])
        ->call('save')
        ->assertHasFormErrors([ManageSettings::fieldName(SettingKey::RetentionAuditHotDays)]);

    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::RetentionAuditHotDays) => 200])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(AuditArchive::class)->hotDays())->toBe(200);
});
