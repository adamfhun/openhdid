<?php

namespace App\Filament\Admin\Resources\AuditLogs\Pages;

use App\Audit\AuditArchive;
use App\Filament\Actions\ExportTableAction;
use App\Filament\Admin\Pages\AuditArchives;
use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use App\Filament\Admin\Widgets\AuditEventsWidget;
use App\Models\AuditLog;
use App\Support\HuDate;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;

class ManageAuditLogs extends ManageRecords
{
    protected static string $resource = AuditLogResource::class;

    /**
     * The list shows the hot window only; the rest is in the archive files.
     */
    public function getSubheading(): ?string
    {
        return __('The entries of the last :days days; older days are in daily archive files on the Audit archive page.', ['days' => app(AuditArchive::class)->hotDays()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('archive')->label(__('Audit archive'))->icon('heroicon-o-archive-box')->color('gray')->url(AuditArchives::getUrl()),
            ExportTableAction::make('audit-log', fn (AuditLog $log): array => [
                __('Time') => $log->created_at?->format(HuDate::DATETIME_SECONDS),
                __('Event') => $log->event,
                __('Actor') => AuditLogResource::actorLabel($log) ?? '',
                __('Actor id') => $log->actor_id,
                __('Subject') => AuditLogResource::subjectLabel($log) ?? '',
                __('Subject id') => $log->subject_id,
                __('IP address') => $log->ip_address,
                __('Details') => json_encode($log->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [AuditEventsWidget::class];
    }
}
