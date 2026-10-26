<?php

namespace App\Filament\Concerns;

use App\Support\InputLimits;

/**
 * Keeps the table search, the column searches and the text typed into the
 * table filters of a panel table within the search limit
 * (InputLimits::SEARCH, 255 characters). The box itself
 * takes more (Filament draws it with maxlength="1000"), and Filament
 * remembers whatever arrives in the session (persistSearchInSession), so a
 * typed value is cut as soon as it arrives: Livewire runs this trait hook
 * before Filament's own updatedTableSearch(), which stores it. A search
 * that came with the address (?search=…) is cut once the table is set up,
 * and the remembered value with it.
 */
trait LimitsSearchInput
{
    public function bootedLimitsSearchInput(): void
    {
        if (! $this->limitTableSearches()) {
            return;
        }

        $table = $this->getTable();

        if ($table->persistsSearchInSession()) {
            session()->put($this->getTableSearchSessionKey(), $this->tableSearch);
        }

        if ($table->persistsColumnSearchesInSession()) {
            session()->put($this->getTableColumnSearchesSessionKey(), $this->tableColumnSearches);
        }

        if ($table->persistsFiltersInSession()) {
            session()->put($this->getTableFiltersSessionKey(), $this->tableFilters);
        }
    }

    public function updatedLimitsSearchInput(string $property): void
    {
        if ($property === 'tableSearch' || str_starts_with($property, 'tableColumnSearches') || str_starts_with($property, 'tableFilters') || str_starts_with($property, 'tableDeferredFilters')) {
            $this->limitTableSearches();
        }
    }

    /**
     * Whether anything had to be cut.
     */
    private function limitTableSearches(): bool
    {
        $cut = false;

        if (is_string($this->tableSearch) && mb_strlen($this->tableSearch) > InputLimits::SEARCH) {
            $this->tableSearch = mb_substr($this->tableSearch, 0, InputLimits::SEARCH);
            $cut = true;
        }

        $limit = function (mixed &$search) use (&$cut): void {
            if (is_string($search) && mb_strlen($search) > InputLimits::SEARCH) {
                $search = mb_substr($search, 0, InputLimits::SEARCH);
                $cut = true;
            }
        };

        array_walk_recursive($this->tableColumnSearches, $limit);

        // The text filters (event, request id, a client's name) end up in the session too.
        if (is_array($this->tableFilters)) {
            array_walk_recursive($this->tableFilters, $limit);
        }

        if (is_array($this->tableDeferredFilters)) {
            array_walk_recursive($this->tableDeferredFilters, $limit);
        }

        return $cut;
    }
}
