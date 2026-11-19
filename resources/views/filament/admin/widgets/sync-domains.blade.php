<x-filament-widgets::widget>
    <x-filament::section collapsible persist-collapsed id="sync-domains">
        <x-slot name="heading">{{ __('E-mail domains of the sync') }}</x-slot>
        <x-slot name="description">{{ __('For information: the domains set under Settings › EMD, and how many records the sync holds of each. Only records of a set domain are taken over.') }}</x-slot>

        @if ($rows === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No domain is set and no record has been taken over yet.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="py-1 pr-4 font-medium">{{ __('Domain') }}</th>
                            <th scope="col" class="py-1 pr-4 font-medium">{{ __('Classified as') }}</th>
                            <th scope="col" class="py-1 pr-4 font-medium">{{ __('Set') }}</th>
                            <th scope="col" class="py-1 pr-4 text-right font-medium">{{ __('Records present') }}</th>
                            <th scope="col" class="py-1 text-right font-medium">{{ __('Missing from EMD') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5" data-hdid-sync-domain="{{ $row['kind']->value }}:{{ $row['domain'] }}">
                                <td class="py-1.5 pr-4 font-mono text-xs">{{ $row['domain'] }}</td>
                                <td class="py-1.5 pr-4">{{ $row['kind']->label() }}</td>
                                <td class="py-1.5 pr-4">
                                    @if ($row['configured'] && $row['present'] === 0)
                                        <x-filament::badge color="warning">{{ __('set, no record came') }}</x-filament::badge>
                                    @elseif ($row['configured'])
                                        <x-filament::badge color="success">{{ __('set') }}</x-filament::badge>
                                    @else
                                        <x-filament::badge color="gray">{{ __('not set any more') }}</x-filament::badge>
                                    @endif
                                </td>
                                <td class="py-1.5 pr-4 text-right tabular-nums">{{ number_format($row['present'], 0, ',', ' ') }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ number_format($row['missing'], 0, ',', ' ') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
