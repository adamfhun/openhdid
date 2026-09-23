<?php

namespace App\Filament\Admin\Resources\IdSessions\Pages;

use App\Filament\Actions\ExportTableAction;
use App\Filament\Admin\Resources\IdSessions\IdSessionResource;
use App\Models\IdSession;
use App\Support\HuDate;
use Filament\Resources\Pages\ManageRecords;

class ManageIdSessions extends ManageRecords
{
    protected static string $resource = IdSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExportTableAction::make('identification-sessions', fn (IdSession $session): array => [
                __('Started') => $session->started_at?->format(HuDate::DATETIME_SECONDS),
                __('Decided') => $session->decided_at?->format(HuDate::DATETIME_SECONDS),
                __('Client') => $session->client?->name,
                __('E-mail') => $session->client?->email,
                __('Agent') => $session->agent?->name,
                __('Call') => $session->call?->external_call_id,
                __('Method') => $session->method->label(),
                __('Channel') => __($session->channel->value),
                __('Status') => __($session->status->value),
                __('Outcome') => $session->outcome_reason ? __($session->outcome_reason) : '',
                __('OK') => $session->accepted_count,
                __('Rejected') => $session->rejected_count,
            ]),
        ];
    }
}
