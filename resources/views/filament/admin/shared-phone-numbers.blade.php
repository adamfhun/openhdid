<x-filament-panels::page>
    <x-filament::section compact>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('The phone menu recognises a caller by the number they call from. A number on file for more than one client recognises nobody: such callers are not asked for their PIN and have to identify with a one-time identification code or with an agent. Decide here whose number it is and remove it from the others; a number that Enterprise Master Data lists for several people has to be corrected in the directory.') }}
        </p>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
