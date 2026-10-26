<?php

namespace App\Filament\Admin\Resources\SyncRunChanges\Pages;

use App\Filament\Actions\ExportTableAction;
use App\Filament\Admin\Resources\SyncRunChanges\SyncRunChangeResource;
use App\Filament\Concerns\LimitsSearchInput;
use App\Models\SyncRunChange;
use App\Support\HuDate;
use Filament\Resources\Pages\ManageRecords;

class ManageSyncRunChanges extends ManageRecords
{
    use LimitsSearchInput;

    protected static string $resource = SyncRunChangeResource::class;

    public function getSubheading(): ?string
    {
        return __('What each EMD sync run changed, one row per directory record: the record, the account and the phone numbers it drove. Kept for the short-lived retention period; the audit log holds one summary entry per run and the account closures and reopenings.');
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportTableAction::make('emd-changes', fn (SyncRunChange $change): array => [
                __('Time') => $change->created_at?->format(HuDate::DATETIME_SECONDS),
                __('Run') => $change->run?->started_at?->format(HuDate::DATETIME),
                __('External id') => $change->external_id,
                __('Name') => $change->name,
                __('Email') => $change->email,
                __('Change') => $change->kind->label(),
                __('Changed fields') => SyncRunChangeResource::fieldSummary($change),
                __('Details') => json_encode($change->changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]),
        ];
    }
}
