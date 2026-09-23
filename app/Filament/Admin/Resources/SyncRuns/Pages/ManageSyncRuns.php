<?php

namespace App\Filament\Admin\Resources\SyncRuns\Pages;

use App\Auth\Permission;
use App\Filament\Admin\Resources\SyncRuns\SyncRunResource;
use App\Models\SyncRun;
use App\Sync\QueuedSyncs;
use App\Sync\ReaderFactory;
use App\Sync\SyncExternalRecords;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ManageSyncRuns extends ManageRecords
{
    protected static string $resource = SyncRunResource::class;

    protected ?Alignment $headerActionsAlignment = Alignment::End;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importFile')
                ->label(__('Import CSV / XLSX'))
                ->icon('heroicon-o-arrow-up-tray')
                ->schema([
                    FileUpload::make('file')
                        ->label(__('File'))
                        ->disk('local')
                        ->directory('sync-uploads')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->required(),
                    Toggle::make('dry_run')
                        ->label(__('Trial run only (nothing is written)'))
                        ->helperText(__('Reads the file and reports what would be created, updated and closed.'))
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    abort_unless(auth()->user()?->can(Permission::SyncManage->value), 403);
                    $path = Storage::disk('local')->path($data['file']);

                    try {
                        $this->runSync(fn (ReaderFactory $readers) => $readers->forFile($path, basename($data['file'])), (bool) ($data['dry_run'] ?? false));
                    } finally {
                        // The upload has served its purpose; it must not linger on disk.
                        Storage::disk('local')->delete($data['file']);
                    }
                }),
            Action::make('syncApiDryRun')
                ->label(__('Trial run from API'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->action(fn () => $this->queueApi(true)),
            Action::make('syncApi')
                ->label(__('EMD sync from API now'))
                ->icon('heroicon-o-cloud-arrow-down')
                ->requiresConfirmation()
                ->action(fn () => $this->queueApi(false)),
        ];
    }

    private function runSync(callable $reader, bool $dryRun): void
    {
        abort_unless(auth()->user()?->can(Permission::SyncManage->value), 403);
        try {
            $run = app(SyncExternalRecords::class)->run($reader(app(ReaderFactory::class)), auth()->user(), $dryRun);
            $notification = Notification::make()
                ->title($dryRun ? __('Trial run finished; nothing was written') : __('EMD sync completed'))
                ->body($this->summary($run));
            ($dryRun ? $notification->info() : $notification->success())->persistent()->send();
        } catch (Throwable $e) {
            Notification::make()->title(__('EMD sync failed'))->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    private function queueApi(bool $dryRun): void
    {
        abort_unless(auth()->user()?->can(Permission::SyncManage->value), 403);

        try {
            app(QueuedSyncs::class)->start(auth()->user(), $dryRun);
            Notification::make()->success()->persistent()
                ->title($dryRun ? __('Trial run queued') : __('EMD sync queued'))
                ->body(__('The file is downloaded and processed in the background. Open the run details when it finishes to see the counts and preview.'))
                ->send();
        } catch (Throwable $exception) {
            Notification::make()->danger()->title(__('EMD sync failed'))->body($exception->getMessage())->send();
        }
    }

    private function summary(SyncRun $run): string
    {
        $stats = $run->stats;
        $lines = [__(':n read, :c new, :u updated, :s skipped, :m missing, :x closed', [
            'n' => $stats['read'], 'c' => $stats['created'], 'u' => $stats['updated'], 's' => $stats['skipped'], 'm' => $stats['missing'], 'x' => $stats['closed'],
        ])];

        foreach (['created' => __('New'), 'closed' => __('Closed')] as $key => $label) {
            if ($sample = $stats['samples'][$key] ?? []) {
                $lines[] = $label.': '.implode(', ', array_slice($sample, 0, 5)).(count($sample) > 5 ? ' …' : '');
            }
        }

        return implode("\n", $lines);
    }
}
