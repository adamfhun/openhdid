<?php

use App\Auth\Role;
use App\Enums\SyncRunStatus;
use App\Filament\Admin\Resources\SyncRuns\Pages\ManageSyncRuns;
use App\Jobs\RunDirectorySync;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\QueuedSyncs;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\Mime\MimeTypes;

const SYNC_UPLOAD_ARCHIVE_CANARY = 'audit-archive/2026/audit-2026-05-01.jsonl.gz';

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['client.hu']);
    Queue::fake();
    Storage::fake('local');
    Http::preventStrayRequests();
    config()->set(['queue.default' => 'database', 'queue.connections.database.retry_after' => 1260]);
    $this->admin = User::factory()->withRole(Role::Admin)->create();

    // Files of the local disk outside what the import itself uploaded.
    foreach ([SYNC_UPLOAD_ARCHIVE_CANARY, SYNC_UPLOAD_ARCHIVE_CANARY.'.sha256', 'sync-uploads/notes.csv'] as $canary) {
        Storage::disk('local')->put($canary, 'x');
    }
});

/**
 * @return list<string>
 */
function tamperedUploadPaths(): array
{
    return [SYNC_UPLOAD_ARCHIVE_CANARY, SYNC_UPLOAD_ARCHIVE_CANARY.'.sha256', 'sync-uploads/notes.csv', 'sync-uploads/../'.SYNC_UPLOAD_ARCHIVE_CANARY, 'sync-uploads/'.str_repeat('a', 40).".csv\n"];
}

it('refuses a file path typed into the import instead of an upload and keeps the file it names', function (bool $anotherSyncQueued, string $path): void {
    $this->actingAs($this->admin);
    if ($anotherSyncQueued) {
        SyncRun::factory()->create(['status' => SyncRunStatus::Queued, 'started_at' => now(), 'finished_at' => null]);
    }

    $page = Livewire::test(ManageSyncRuns::class)
        ->mountAction('importFile')
        ->set('mountedActions.0.data.file', ['x' => $path])
        ->callMountedAction();

    expect(Storage::disk('local')->exists(SYNC_UPLOAD_ARCHIVE_CANARY))->toBeTrue()
        ->and(Storage::disk('local')->exists(SYNC_UPLOAD_ARCHIVE_CANARY.'.sha256'))->toBeTrue()
        ->and(Storage::disk('local')->exists('sync-uploads/notes.csv'))->toBeTrue()
        ->and(SyncRun::query()->count())->toBe($anotherSyncQueued ? 1 : 0);
    Queue::assertNothingPushed();
    $page->assertHasActionErrors(['file' => __('The file has to be uploaded here; a file path cannot be given.')]);
})->with([
    'while another sync waits' => [true],
    'with no other sync' => [false],
])->with([SYNC_UPLOAD_ARCHIVE_CANARY, SYNC_UPLOAD_ARCHIVE_CANARY.'.sha256', 'sync-uploads/notes.csv']);

it('refuses to queue an import of a path that is not an upload of the import, and deletes nothing', function (string $path): void {
    expect(fn () => app(QueuedSyncs::class)->start($this->admin, false, $path))
        ->toThrow(InvalidArgumentException::class, __('The import accepts only a file uploaded on the EMD sync runs page.'));

    expect(SyncRun::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists(SYNC_UPLOAD_ARCHIVE_CANARY))->toBeTrue()
        ->and(Storage::disk('local')->exists(SYNC_UPLOAD_ARCHIVE_CANARY.'.sha256'))->toBeTrue()
        ->and(Storage::disk('local')->exists('sync-uploads/notes.csv'))->toBeTrue();
    Queue::assertNothingPushed();
})->with(tamperedUploadPaths());

it('neither reads nor deletes a path that is not an upload when the queued job runs or fails', function (string $path): void {
    $run = app(QueuedSyncs::class)->start($this->admin, false);
    $job = new RunDirectorySync($run->id, $path);

    expect(fn () => $job->handle(app(QueuedSyncs::class)))->toThrow(InvalidArgumentException::class);
    $job->failed(null);

    expect($run->fresh()->status)->toBe(SyncRunStatus::Failed)
        ->and($run->fresh()->error)->toBe(__('The import accepts only a file uploaded on the EMD sync runs page.'))
        ->and(Storage::disk('local')->exists(SYNC_UPLOAD_ARCHIVE_CANARY))->toBeTrue()
        ->and(Storage::disk('local')->exists(SYNC_UPLOAD_ARCHIVE_CANARY.'.sha256'))->toBeTrue()
        ->and(Storage::disk('local')->exists('sync-uploads/notes.csv'))->toBeTrue();
    Http::assertNothingSent();
})->with(tamperedUploadPaths());

it('still queues a real upload and removes only that upload once read', function (): void {
    $this->actingAs($this->admin);
    $file = UploadedFile::fake()->createWithContent('clients.csv', "external_user_id,name,email\n1,Anna,anna@client.hu\n");

    Livewire::test(ManageSyncRuns::class)->callAction('importFile', ['file' => $file])->assertHasNoActionErrors();

    Queue::assertPushed(RunDirectorySync::class, function (RunDirectorySync $job): bool {
        expect(QueuedSyncs::isUpload($job->upload))->toBeTrue()
            ->and(Storage::disk('local')->exists($job->upload))->toBeTrue();
        $job->handle(app(QueuedSyncs::class));
        expect(Storage::disk('local')->exists($job->upload))->toBeFalse();

        return true;
    });
    expect(SyncRun::query()->sole()->status)->toBe(SyncRunStatus::Completed)
        ->and(Storage::disk('local')->exists(SYNC_UPLOAD_ARCHIVE_CANARY))->toBeTrue()
        ->and(Storage::disk('local')->exists('sync-uploads/notes.csv'))->toBeTrue();
});

it('imports a semicolon CSV, the format of the Hungarian Excel and of our own export, whatever type its content looks like', function (string $content): void {
    $this->actingAs($this->admin);
    config()->set('hdid.sync.csv_delimiter', ';');
    // Control: outside the tests the upload's own name comes from the type its content
    // looks like, and for a semicolon CSV that is plain text, so it was stored as .txt.
    expect((new FinfoMimeTypeDetector)->detectMimeTypeFromBuffer($content))->toBe('text/plain')
        ->and(MimeTypes::getDefault()->getExtensions('text/plain')[0] ?? null)->toBe('txt');
    $file = UploadedFile::fake()->createWithContent('clients.csv', $content);

    Livewire::test(ManageSyncRuns::class)->callAction('importFile', ['file' => $file])->assertHasNoActionErrors();

    Queue::assertPushed(RunDirectorySync::class, function (RunDirectorySync $job): bool {
        expect($job->upload)->toEndWith('.csv');
        $job->handle(app(QueuedSyncs::class));

        return true;
    });
    $run = SyncRun::query()->sole();
    expect($run->status)->toBe(SyncRunStatus::Completed, (string) $run->error)
        ->and($run->stats['read'] ?? null)->toBe(1);
})->with([
    'semicolons' => ["external_user_id;name;email\n1;Anna;anna@client.hu\n"],
    'semicolons with a BOM' => ["\u{FEFF}external_user_id;name;email\n1;Anna;anna@client.hu\n"],
]);

it('stores an XLSX upload as XLSX', function (): void {
    $this->actingAs($this->admin);
    $path = sys_get_temp_dir().'/hdid-upload-'.uniqid().'.xlsx';
    SimpleExcelWriter::create($path)->addRow(['external_user_id' => 1, 'name' => 'Anna', 'email' => 'anna@client.hu'])->close();
    $file = UploadedFile::fake()->createWithContent('clients.xlsx', (string) file_get_contents($path));
    @unlink($path);

    Livewire::test(ManageSyncRuns::class)->callAction('importFile', ['file' => $file])->assertHasNoActionErrors();

    Queue::assertPushed(RunDirectorySync::class, function (RunDirectorySync $job): bool {
        expect($job->upload)->toEndWith('.xlsx');
        $job->handle(app(QueuedSyncs::class));

        return true;
    });
    expect(SyncRun::query()->sole()->status)->toBe(SyncRunStatus::Completed);
});

it('names a stored upload after the extension the user chose, not after the type its content looks like', function (string $original, string $looksLike, string $stored): void {
    $this->actingAs($this->admin);
    $page = Livewire::test(ManageSyncRuns::class)->mountAction('importFile');
    $field = $page->instance()->getSchemaComponent('mountedActionSchema0.file');

    // Outside the tests the default name is the content type's: a semicolon CSV is plain text (.txt).
    $upload = Mockery::mock(TemporaryUploadedFile::class);
    $upload->allows('getClientOriginalExtension')->andReturn($original);
    $upload->allows('getClientOriginalName')->andReturn('clients.'.$original);
    $upload->allows('hashName')->andReturn(str_repeat('a', 40).'.'.$looksLike);
    $upload->allows('extension')->andReturn($looksLike);

    $name = $field->getUploadedFileNameForStorage($upload);

    expect($name)->toMatch('/\A[A-Za-z0-9]{40}\.'.$stored.'\z/')
        ->and(QueuedSyncs::isUpload('sync-uploads/'.$name))->toBeTrue();
})->with([
    'semicolon CSV' => ['csv', 'txt', 'csv'],
    'CSV in capitals' => ['CSV', 'txt', 'csv'],
    'XLSX' => ['xlsx', 'zip', 'xlsx'],
    'a .txt file' => ['txt', 'txt', 'csv'],
]);

it('accepts only CSV and XLSX upload paths', function (): void {
    expect(QueuedSyncs::isUpload('sync-uploads/'.str_repeat('a', 40).'.csv'))->toBeTrue()
        ->and(QueuedSyncs::isUpload('sync-uploads/'.str_repeat('a', 40).'.xlsx'))->toBeTrue()
        ->and(QueuedSyncs::isUpload('sync-uploads/'.str_repeat('a', 40).'.txt'))->toBeFalse()
        ->and(QueuedSyncs::isUpload('sync-uploadsX'.str_repeat('a', 40).'.csv'))->toBeFalse();
});

it('queues nothing for a non-text file field value: the server-side shape check is the guard there', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(ManageSyncRuns::class)
        ->mountAction('importFile')
        ->set('mountedActions.0.data.file', ['x' => 123])
        ->callMountedAction()
        ->assertNotified(__('EMD sync failed'));

    expect(SyncRun::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists(SYNC_UPLOAD_ARCHIVE_CANARY))->toBeTrue()
        ->and(Storage::disk('local')->exists('sync-uploads/notes.csv'))->toBeTrue();
    Queue::assertNothingPushed();
});

it('tells nothing about a file of the disk whose path was typed into the field', function (): void {
    $this->actingAs($this->admin);

    $page = Livewire::test(ManageSyncRuns::class)
        ->mountAction('importFile')
        ->set('mountedActions.0.data.file', ['x' => SYNC_UPLOAD_ARCHIVE_CANARY]);

    expect($page->instance()->getSchemaComponent('mountedActionSchema0.file')->getUploadedFiles())->toBe(['x' => null]);
});
