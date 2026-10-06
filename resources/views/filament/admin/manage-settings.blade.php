<x-filament-panels::page>
    <form wire:submit="mountAction('save')" class="space-y-6">
        {{ $this->form }}

        <x-filament::actions :actions="[$this->saveAction]" />
    </form>
</x-filament-panels::page>
