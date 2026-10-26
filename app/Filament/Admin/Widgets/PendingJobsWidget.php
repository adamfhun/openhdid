<?php

namespace App\Filament\Admin\Widgets;

use App\Auth\Permission;
use App\Filament\Admin\Widgets\Concerns\AuthorizesEveryRequest;
use App\Filament\Concerns\LimitsSearchInput;
use App\Models\QueuedJob;
use App\Support\HuDate;
use App\System\QueueMaintenance;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The jobs table of the database queue on the Queues page: what waits for
 * a worker and what a worker is running right now. Waiting jobs can be
 * deleted one by one; reserved ones belong to their worker.
 */
class PendingJobsWidget extends TableWidget
{
    use AuthorizesEveryRequest;
    use LimitsSearchInput;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (auth()->user()?->can(Permission::SettingsManage->value) ?? false) && app(QueueMaintenance::class)->usesDatabase();
    }

    public function table(Table $table): Table
    {
        $maintenance = app(QueueMaintenance::class);
        $retryAfter = (int) ceil(((int) config('queue.connections.database.retry_after', 90)) / 60);

        return $table
            ->heading(__('Waiting jobs'))
            ->description(__('Waiting for a worker, or running right now. A job in progress belongs to its worker; if that worker died, the queue releases the job after :m minutes.', ['m' => $retryAfter]))
            ->query(fn (): Builder => QueuedJob::query())
            ->defaultSort('id')
            ->paginated([10, 25, 50])
            ->poll('30s')
            ->emptyStateHeading(__('No waiting jobs'))
            ->emptyStateDescription(__('The workers have picked up everything.'))
            ->columns([
                TextColumn::make('queue')->label(__('Queue'))->badge()->color('gray'),
                TextColumn::make('job')->label(__('Job'))->weight('semibold')
                    ->state(fn (QueuedJob $record): string => $maintenance->describe(is_array($record->payload) ? $record->payload : [])['label'])
                    ->description(fn (QueuedJob $record): ?string => FailedJobsWidget::messageLine($maintenance->describe(is_array($record->payload) ? $record->payload : [])['message_id'])),
                TextColumn::make('attempts')->label(__('Attempts'))->alignCenter(),
                TextColumn::make('created_at')->label(__('Queued'))->dateTime(),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->state(fn (QueuedJob $record): string => match (true) {
                        $record->isReserved() => __('in progress since :when', ['when' => $record->reserved_at->diffForHumans()]),
                        $record->available_at->isFuture() => __('waiting until :when', ['when' => $record->available_at->format(HuDate::DATETIME)]),
                        default => __('waiting'),
                    })
                    ->color(fn (QueuedJob $record): string => $record->isReserved() ? 'info' : 'warning'),
            ])
            ->recordActions([
                Action::make('delete')->label(__('Delete'))->icon('heroicon-o-trash')->color('danger')
                    ->authorize(fn (): bool => static::canView())
                    ->disabled(fn (QueuedJob $record): bool => $record->isReserved())
                    ->tooltip(fn (QueuedJob $record): ?string => $record->isReserved() ? __('A job in progress belongs to its worker; if the worker died, the queue releases it after :m minutes.', ['m' => $retryAfter]) : null)
                    ->requiresConfirmation()
                    ->modalDescription(__('The waiting job is deleted and never runs. A queued e-mail or SMS is not lost: the scheduler queues it again within minutes; an EMD sync has to be started again.'))
                    ->action(function (QueuedJob $record) use ($maintenance): void {
                        $deleted = $maintenance->deleteWaiting($record);
                        Notification::make()
                            ->title($deleted ? __('Waiting job deleted') : __('The job was already picked up by a worker'))
                            ->status($deleted ? 'success' : 'warning')
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('delete')->label(__('Delete selected'))->icon('heroicon-o-trash')->color('danger')
                    ->authorize(fn (): bool => static::canView())
                    ->requiresConfirmation()
                    ->modalDescription(fn (Collection $records): string => __(':n of the selected job(s) are waiting and will be deleted; jobs in progress are left alone.', ['n' => $records->reject(fn (QueuedJob $record): bool => $record->isReserved())->count()]))
                    ->modalSubmitActionLabel(__('Delete selected'))
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) use ($maintenance): void {
                        $deleted = $records->filter(fn (QueuedJob $record): bool => $maintenance->deleteWaiting($record))->count();
                        Notification::make()->title(__(':n waiting job(s) deleted', ['n' => $deleted]))->success()->send();
                    }),
            ]);
    }
}
