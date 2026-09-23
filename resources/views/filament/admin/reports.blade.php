<x-filament-panels::page>
    <form wire:submit="download" class="space-y-6">
        {{ $this->form }}

        <x-filament::actions :actions="$this->getFormActions()" />
    </form>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('Every download is written to the audit log with the period and the parts chosen. Ended calls leave the dashboard after the short retention; the Calls list and the reports keep them until the general retention period ends.') }}
    </p>
</x-filament-panels::page>
