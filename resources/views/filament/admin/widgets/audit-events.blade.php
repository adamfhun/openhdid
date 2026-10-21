<x-filament-widgets::widget>
    <x-filament::section collapsible collapsed>
        <x-slot name="heading">{{ __('Events of the last :days days', ['days' => $days]) }}</x-slot>
        <x-slot name="description">{{ __(':total entries; the busiest event names first, refreshed every five minutes. A name that dominates the list is where the log can be made quieter.', ['total' => number_format($total, 0, ',', ' ')]) }}</x-slot>

        @if ($events === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No entry in this period.') }}</p>
        @else
            <div class="grid gap-x-8 gap-y-1 text-sm sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($events as $event)
                    <div class="flex items-center justify-between gap-3 border-b border-gray-100 py-1 dark:border-white/5">
                        <span class="font-mono text-xs">{{ $event['event'] }}</span>
                        <span class="whitespace-nowrap tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($event['n'], 0, ',', ' ') }}<span class="ml-1 text-xs text-gray-400">({{ $total > 0 ? round(100 * $event['n'] / $total) : 0 }}%)</span></span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
