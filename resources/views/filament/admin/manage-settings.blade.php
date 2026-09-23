<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <x-filament::actions :actions="$this->getFormActions()" />
    </form>
</x-filament-panels::page>
