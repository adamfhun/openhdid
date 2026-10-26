<?php

namespace App\Filament\Admin\Resources\SyncRuns\Pages;

use App\Auth\Permission;
use App\Filament\Admin\Resources\SyncRuns\SyncRunResource;
use App\Filament\Concerns\LimitsSearchInput;
use App\Sync\ApiTokens;
use App\Sync\QueuedSyncs;
use App\System\Retention;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class ManageSyncRuns extends ManageRecords
{
    use LimitsSearchInput;

    /**
     * The largest file the import takes, in kilobytes: the container's web
     * server and PHP refuse a request above 10 MB with a bare error page, so
     * the field says it first, with a translated message.
     */
    public const UPLOAD_MAX_KILOBYTES = 10240;

    protected static string $resource = SyncRunResource::class;

    protected ?Alignment $headerActionsAlignment = Alignment::End;

    /**
     * The token state next to the run buttons, so nobody starts a sync blind.
     */
    public function getSubheading(): ?string
    {
        return app(ApiTokens::class)->describe();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importFile')
                ->label(__('Import CSV / XLSX'))
                ->icon('heroicon-o-arrow-up-tray')
                ->schema([
                    // Only a file uploaded here: a path in the form state is the
                    // browser's to change, and the import deletes what it read.
                    FileUpload::make('file')
                        ->label(__('File'))
                        ->disk('local')
                        ->directory(Retention::UPLOAD_DIRECTORY)
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->preventFilePathTampering()
                        // Named after the extension chosen, not after the type the content
                        // looks like: a semicolon CSV (the Hungarian Excel's and our own export)
                        // reads as plain text, and a .txt name would fail at the reader.
                        ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => Str::random(40).'.'.(mb_strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv'))
                        ->validationMessages(['tampered' => __('The file has to be uploaded here; a file path cannot be given.')])
                        ->maxSize(self::UPLOAD_MAX_KILOBYTES)
                        ->helperText(__('CSV or XLSX, at most :size MB.', ['size' => self::UPLOAD_MAX_KILOBYTES / 1024]))
                        ->required(),
                    Toggle::make('dry_run')
                        ->label(__('Trial run only (nothing is written)'))
                        ->helperText(__('Reads the file and reports what would be created, updated and closed.'))
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    // The worker reads the upload: a request has a time limit a
                    // large file would hit. The upload is removed once it was read
                    // (or could not be queued at all).
                    $this->queue((bool) ($data['dry_run'] ?? false), $data['file']);
                }),
            Action::make('syncApiDryRun')
                ->label(__('Trial run from API'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->action(fn () => $this->queue(true)),
            Action::make('syncApi')
                ->label(__('EMD sync from API now'))
                ->icon('heroicon-o-cloud-arrow-down')
                ->requiresConfirmation()
                ->modalHeading(__('Run the EMD sync now?'))
                ->modalDescription(__('The live sync downloads the export and creates, updates and closes accounts within the configured safeguards; it runs in the background and the run details show the result. A trial run shows the same counts without writing anything.'))
                ->action(fn () => $this->queue(false)),
        ];
    }

    private function queue(bool $dryRun, ?string $upload = null): void
    {
        abort_unless(auth()->user()?->can(Permission::SyncManage->value), 403);

        try {
            app(QueuedSyncs::class)->start(auth()->user(), $dryRun, $upload);
            Notification::make()->success()->persistent()
                ->title($dryRun ? __('Trial run queued') : __('EMD sync queued'))
                ->body($upload === null
                    ? __('The file is downloaded and processed in the background. Open the run details when it finishes to see the counts and preview.')
                    : __('The file is processed in the background. Open the run details when it finishes to see the counts and preview.'))
                ->send();
        } catch (Throwable $exception) {
            app(QueuedSyncs::class)->discardUpload($upload);
            Notification::make()->danger()->title(__('EMD sync failed'))->body($exception->getMessage())->send();
        }
    }
}
