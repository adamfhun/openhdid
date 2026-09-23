@php
    $checks = $this->getChecks();
    $summary = collect($checks)->groupBy(fn ($c) => $c->status->value)->map->count();
@endphp

<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-3 text-sm">
        <x-filament::badge color="success" size="lg">{{ __(':n OK', ['n' => $summary->get('ok', 0)]) }}</x-filament::badge>
        <x-filament::badge color="warning" size="lg">{{ __(':n warnings', ['n' => $summary->get('warn', 0)]) }}</x-filament::badge>
        <x-filament::badge color="danger" size="lg">{{ __(':n failed', ['n' => $summary->get('fail', 0)]) }}</x-filament::badge>
        <span class="text-gray-500">{{ __('Checked at :time', ['time' => now()->format('H:i:s')]) }}</span>
    </div>

    <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
        @foreach ($checks as $check)
            <x-filament::section compact>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold">{{ $check->label }}</div>
                        <div class="mt-1 break-words text-sm text-gray-500 dark:text-gray-400">{{ $check->detail }}</div>
                        @if ($check->hint)
                            <details class="mt-2 text-sm" @if ($check->status !== \App\System\CheckStatus::Ok) open @endif>
                                <summary class="cursor-pointer select-none text-primary-600 hover:underline dark:text-primary-400">{{ __('What to do if this fails') }}</summary>
                                <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $check->hint }}</p>
                            </details>
                        @endif
                    </div>
                    <x-filament::badge :color="$check->status->color()" class="shrink-0 whitespace-nowrap">{{ $check->status->label() }}</x-filament::badge>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
