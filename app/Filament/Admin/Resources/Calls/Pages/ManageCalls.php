<?php

namespace App\Filament\Admin\Resources\Calls\Pages;

use App\Filament\Admin\Resources\Calls\CallResource;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageCalls extends ManageRecords
{
    protected static string $resource = CallResource::class;

    /** @return array<int, Tab> */
    public function getTabs(): array
    {
        $tabs = collect([15, 30, 60, 90, 120])->mapWithKeys(fn (int $days): array => [
            $days => Tab::make(__('Last :days days', ['days' => $days]))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereBetween('arrived_at', [today()->subDays($days - 1), today()->endOfDay()])),
        ])->all();

        // Without this the date filter could never reach a call older than the
        // widest tab, while the list promises the full retention period.
        $tabs['all'] = Tab::make(__('All'));

        return $tabs;
    }

    public function getDefaultActiveTab(): string
    {
        return '30';
    }

    protected function modifyQueryWithActiveTab(Builder $query, bool $isResolvingRecord = false): Builder
    {
        if (! array_key_exists($this->activeTab ?? '', $this->getCachedTabs())) {
            $this->activeTab = $this->getDefaultActiveTab();
        }

        return parent::modifyQueryWithActiveTab($query, $isResolvingRecord);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
