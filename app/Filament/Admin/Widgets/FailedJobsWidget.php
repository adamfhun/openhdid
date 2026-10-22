<?php

namespace App\Filament\Admin\Widgets;

use App\Audit\Auditor;
use App\Auth\Permission;
use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use App\Models\FailedJob;
use App\Models\OutboundMessage;
use App\System\QueueMaintenance;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The failed_jobs table on the Queues page: what the worker gave up on,
 * with the error, and the console's retry, forget, flush and prune as
 * buttons. Everything goes through QueueMaintenance, so it is audited.
 */
class FailedJobsWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    public function table(Table $table): Table
    {
        $maintenance = app(QueueMaintenance::class);

        return $table
            ->heading(__('Failed jobs'))
            ->description(__('What the worker gave up on: fix the cause, then run again or delete. Older than :days days are removed nightly.', ['days' => QueueMaintenance::FAILED_JOB_DAYS]))
            ->query(fn (): Builder => FailedJob::query())
            ->defaultSort('failed_at', 'desc')
            ->paginated([10, 25, 50])
            ->poll('30s')
            ->emptyStateHeading(__('No failed jobs'))
            ->emptyStateDescription(__('Every job the worker picked up has finished.'))
            ->columns([
                TextColumn::make('failed_at')->label(__('Failed at'))->dateTime()->sortable(),
                TextColumn::make('queue')->label(__('Queue'))->badge()->color('gray'),
                TextColumn::make('job')->label(__('Job'))->weight('semibold')
                    ->state(fn (FailedJob $record): string => $maintenance->describe(is_array($record->payload) ? $record->payload : [])['label'])
                    ->description(fn (FailedJob $record): ?string => static::messageLine($maintenance->describe(is_array($record->payload) ? $record->payload : [])['message_id'])),
                TextColumn::make('exception')->label(__('Error'))->wrap()->extraAttributes(['class' => 'max-w-[60ch]'])
                    ->state(fn (FailedJob $record): string => Str::limit($record->exceptionSummary(), 200))
                    ->tooltip(fn (FailedJob $record): string => $record->exceptionSummary()),
            ])
            ->recordActions([
                Action::make('details')->label(__('Details'))->icon('heroicon-o-eye')->iconButton()->color('gray')
                    ->modalHeading(__('Error details'))
                    ->modalContent(fn (FailedJob $record): HtmlString => new HtmlString('<pre class="max-h-[60vh] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 text-xs text-gray-700 dark:bg-white/5 dark:text-gray-200">'.e($record->exception).'</pre>'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),
                Action::make('retry')->label(__('Run again'))->icon('heroicon-o-arrow-path')->color('warning')->iconButton()
                    ->authorize(fn (): bool => static::canView())
                    ->requiresConfirmation()
                    ->modalHeading(__('Run again'))
                    ->modalDescription(__('The job is put back on its queue and the worker runs it again: a message delivery sends the message, an EMD sync runs the sync.'))
                    ->modalSubmitActionLabel(__('Run again'))
                    ->action(function (FailedJob $record) use ($maintenance): void {
                        $done = $maintenance->retryFailed($record);
                        Notification::make()
                            ->title($done ? __('The job is back on its queue') : __('The job was no longer among the failed ones'))
                            ->status($done ? 'success' : 'warning')
                            ->send();
                    }),
                Action::make('delete')->label(__('Delete'))->icon('heroicon-o-trash')->color('danger')->iconButton()
                    ->authorize(fn (): bool => static::canView())
                    ->requiresConfirmation()
                    ->modalHeading(__('Delete'))
                    ->modalDescription(__('The failed job is deleted for good and never runs.'))
                    ->action(function (FailedJob $record) use ($maintenance): void {
                        $maintenance->forgetFailed($record);
                        Notification::make()->title(__('Failed job deleted'))->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('retry')->label(__('Run selected again'))->icon('heroicon-o-arrow-path')->color('warning')
                    ->authorize(fn (): bool => static::canView())
                    ->requiresConfirmation()
                    ->modalDescription(fn (Collection $records): string => __(':n failed job(s) are put back on their queues and run again.', ['n' => $records->count()]))
                    ->modalSubmitActionLabel(__('Run selected again'))
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) use ($maintenance): void {
                        $retried = $records->filter(fn (FailedJob $record): bool => $maintenance->retryFailed($record))->count();
                        Notification::make()->title(__(':n job(s) back on their queues', ['n' => $retried]))->success()->send();
                    }),
                BulkAction::make('delete')->label(__('Delete selected'))->icon('heroicon-o-trash')->color('danger')
                    ->authorize(fn (): bool => static::canView())
                    ->requiresConfirmation()
                    ->modalDescription(fn (Collection $records): string => __(':n failed job(s) are deleted for good.', ['n' => $records->count()]))
                    ->modalSubmitActionLabel(__('Delete selected'))
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) use ($maintenance): void {
                        $deleted = $records->filter(fn (FailedJob $record): bool => $maintenance->forgetFailed($record))->count();
                        Notification::make()->title(__(':n failed job(s) deleted', ['n' => $deleted]))->success()->send();
                    }),
            ])
            ->headerActions([
                Action::make('retryAll')->label(__('Run all again'))->icon('heroicon-o-arrow-path')->color('warning')
                    ->authorize(fn (): bool => static::canView())
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => __(':n failed job(s) are put back on their queues and run again.', ['n' => FailedJob::query()->count()]))
                    ->modalSubmitActionLabel(__('Run all again'))
                    ->action(function () use ($maintenance): void {
                        $retried = $maintenance->retryAllFailed();
                        Notification::make()->title(__(':n job(s) back on their queues', ['n' => $retried]))->success()->send();
                    }),
                Action::make('deleteAll')->label(__('Delete all'))->icon('heroicon-o-trash')->color('danger')
                    ->authorize(fn (): bool => static::canView())
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => __(':n failed job(s) are deleted for good.', ['n' => FailedJob::query()->count()]))
                    ->modalSubmitActionLabel(__('Delete all'))
                    ->action(function () use ($maintenance): void {
                        $deleted = $maintenance->flushFailed();
                        Notification::make()->title(__(':n failed job(s) deleted', ['n' => $deleted]))->success()->send();
                    }),
                Action::make('pruneOld')->label(__('Delete older than…'))->icon('heroicon-o-clock')->color('gray')
                    ->authorize(fn (): bool => static::canView())
                    ->modalHeading(__('Delete failed jobs older than a number of days'))
                    ->modalSubmitActionLabel(__('Delete'))
                    ->schema([
                        TextInput::make('days')->label(__('Days'))->numeric()->integer()->minValue(1)->maxValue(365)->default(7)->required(),
                    ])
                    ->action(function (array $data) use ($maintenance): void {
                        $days = (int) $data['days'];
                        $removed = $maintenance->pruneFailed($days);

                        if ($removed > 0) {
                            app(Auditor::class)->record('queue.failed_pruned', context: ['count' => $removed, 'days' => $days]);
                        }

                        Notification::make()->title(__(':n failed job(s) deleted', ['n' => $removed]))->success()->send();
                    }),
            ]);
    }

    /**
     * For a message delivery job: whom the message goes to and where it
     * stands now, with the premium-only hiding the message list applies.
     */
    public static function messageLine(?string $messageId): ?string
    {
        if ($messageId === null) {
            return null;
        }

        $message = OutboundMessage::query()->with('client')->find($messageId);

        if ($message === null) {
            return __('the message no longer exists');
        }

        return OutboundMessageResource::visibleRecipient($message).' · '.$message->status->label();
    }
}
