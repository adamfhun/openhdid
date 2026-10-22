@php
    use App\System\CheckGroup;
    use App\System\CheckStatus;

    $groups = $this->getGroupedChecks();
@endphp

<x-filament-panels::page>
    @foreach ($groups as $groupKey => $checks)
        @php
            $group = CheckGroup::from($groupKey);
            $summary = collect($checks)->groupBy(fn ($c) => $c->status->value)->map->count();
        @endphp
        <div class="space-y-4 {{ $loop->first ? '' : 'border-t border-gray-200 pt-6 dark:border-white/10' }}">
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <h2 class="text-base font-semibold">{{ $group->label() }}</h2>
                <x-filament::badge color="success" size="lg">{{ __(':n OK', ['n' => $summary->get('ok', 0)]) }}</x-filament::badge>
                @if ($group === CheckGroup::Modes || $summary->get('info', 0) > 0)
                    <x-filament::badge color="info" size="lg">{{ __(':n information', ['n' => $summary->get('info', 0)]) }}</x-filament::badge>
                @endif
                <x-filament::badge color="warning" size="lg">{{ __(':n warnings', ['n' => $summary->get('warn', 0)]) }}</x-filament::badge>
                @if ($group === CheckGroup::Operation || $summary->get('fail', 0) > 0)
                    <x-filament::badge color="danger" size="lg">{{ __(':n failed', ['n' => $summary->get('fail', 0)]) }}</x-filament::badge>
                @endif
                @if ($loop->first)
                    <span class="text-gray-500">{{ __('Checked at :time', ['time' => now()->format('H:i:s')]) }}</span>
                @endif
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $group->description() }}</p>

            <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                @foreach ($checks as $check)
                    @php $link = $this->linkFor($check); @endphp
                    <x-filament::section compact>
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="font-semibold">{{ $check->label }}</div>
                                <div class="mt-1 break-words text-sm text-gray-500 dark:text-gray-400">{{ $check->detail }}</div>
                                @if ($check->hint)
                                    <details class="mt-2 text-sm" @if (! in_array($check->status, [CheckStatus::Ok, CheckStatus::Info], true)) open @endif>
                                        <summary class="cursor-pointer select-none text-primary-600 hover:underline dark:text-primary-400">{{ __('What to do if this fails') }}</summary>
                                        <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $check->hint }}</p>
                                    </details>
                                @endif
                                @if ($link)
                                    <div class="mt-2">
                                        <x-filament::link :href="$link['url']" size="sm" icon="heroicon-m-arrow-right">{{ $link['label'] }}</x-filament::link>
                                    </div>
                                @endif
                            </div>
                            <x-filament::badge :color="$check->status->color()" class="shrink-0 whitespace-nowrap">{{ $check->status->label() }}</x-filament::badge>
                        </div>
                    </x-filament::section>
                @endforeach
            </div>
        </div>
    @endforeach
</x-filament-panels::page>
