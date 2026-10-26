<?php

namespace App\Filament\Admin\Pages;

use App\Auth\Permission;
use App\Filament\Admin\Clusters\System;
use App\Filament\Admin\Widgets\FailedJobsWidget;
use App\Filament\Admin\Widgets\OutboundMessagesWidget;
use App\Filament\Admin\Widgets\PendingJobsWidget;
use App\System\HealthChecks;
use App\System\QueueMaintenance;
use App\System\QueueState;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;

/**
 * The queue without a server console: every worker queue with its heartbeat
 * and load, the failed and the waiting jobs, and the outbound messages, each
 * with the actions an administrator needs (run again, delete, clear, resend).
 */
class Queues extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $cluster = System::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'queues';

    protected string $view = 'filament.admin.queues';

    public static function getNavigationLabel(): string
    {
        return __('Queues');
    }

    public function getTitle(): string
    {
        return __('Queues');
    }

    public function getSubheading(): ?string
    {
        return __('Background jobs and outbound messages, with the actions the server console would otherwise be needed for.');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    /**
     * Another installation in the same Valkey/Redis (HealthChecks): its
     * workers write the same heartbeats, so "alive" here would say nothing.
     */
    public function getSharedStoreWarning(): ?string
    {
        return app(HealthChecks::class)->sharedStoreProblem();
    }

    public function getMaintenance(): QueueMaintenance
    {
        return app(QueueMaintenance::class);
    }

    /**
     * @return list<QueueState>
     */
    public function getQueues(): array
    {
        return $this->getMaintenance()->overview();
    }

    public function retryAfterMinutes(): int
    {
        return (int) ceil(((int) config('queue.connections.database.retry_after', 90)) / 60);
    }

    public function clearQueueAction(): Action
    {
        return Action::make('clearQueue')
            ->label(__('Clear queue'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->size(Size::ExtraSmall)
            ->authorize(fn (): bool => static::canAccess())
            ->disabled(fn (array $arguments): bool => ! $this->getMaintenance()->isClearable() || $this->pendingOf((string) ($arguments['queue'] ?? '')) === 0)
            ->tooltip(fn (array $arguments): ?string => match (true) {
                ! $this->getMaintenance()->isClearable() => __('The "sync" queue driver runs jobs inside the request; there is nothing to clear.'),
                $this->pendingOf((string) ($arguments['queue'] ?? '')) === 0 => __('The queue is empty.'),
                default => null,
            })
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => __('Clear the ":queue" queue?', ['queue' => $arguments['queue'] ?? '']))
            ->modalDescription(fn (array $arguments): string => __('Every waiting and in-progress job of the queue is deleted: :n job(s) right now. Queued e-mails and SMS are not lost, the scheduler queues them again within minutes; an EMD sync waiting here stays "queued" on the runs list and has to be started again.', ['n' => $this->pendingOf((string) ($arguments['queue'] ?? ''))]))
            ->modalSubmitActionLabel(__('Clear queue'))
            ->action(function (array $arguments): void {
                $queue = (string) ($arguments['queue'] ?? '');
                abort_unless($queue !== '' && in_array($queue, $this->getMaintenance()->queues(), true), 422);

                $count = $this->getMaintenance()->clearQueue($queue);

                Notification::make()->title(__(':n job(s) removed from the ":queue" queue', ['n' => $count, 'queue' => $queue]))->success()->send();
            });
    }

    private function pendingOf(string $queue): int
    {
        foreach ($this->getQueues() as $state) {
            if ($state->name === $queue) {
                return $state->pending();
            }
        }

        return 0;
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')->label(__('Refresh'))->icon('heroicon-o-arrow-path')->action(fn () => null),
        ];
    }

    /**
     * @return array<int, class-string>
     */
    protected function getFooterWidgets(): array
    {
        return array_values(array_filter([
            FailedJobsWidget::class,
            // Only the database driver can list waiting jobs one by one.
            $this->getMaintenance()->usesDatabase() ? PendingJobsWidget::class : null,
            OutboundMessagesWidget::class,
        ]));
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }
}
