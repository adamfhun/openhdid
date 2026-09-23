<?php

namespace App\Filament\Admin\Resources\AuditLogs\Pages;

use App\Filament\Actions\ExportTableAction;
use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use App\Models\AuditLog;
use App\Support\HuDate;
use Filament\Resources\Pages\ManageRecords;

class ManageAuditLogs extends ManageRecords
{
    protected static string $resource = AuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
}
