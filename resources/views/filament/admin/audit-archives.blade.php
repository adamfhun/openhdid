@php
    $state = $this->getState();
    $files = $this->getFiles();
    $format = fn (int $bytes): string => $bytes >= 1_048_576 ? number_format($bytes / 1_048_576, 1, ',', ' ').' MB' : number_format(max(1, $bytes / 1024), 0, ',', ' ').' kB';
@endphp

<x-filament-panels::page>
    <x-filament::section compact>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('The database keeps the audit entries of the last :days days (:n entries now, the oldest from :oldest); the nightly task moves every older day into one compressed JSON Lines file here and deletes it from the database. The files are kept for the general retention period and are part of the backups with the storage volume.', ['days' => $state['hot_days'], 'n' => number_format($state['rows'], 0, ',', ' '), 'oldest' => $state['oldest'] ?? '-']) }}
        </p>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            {{ __('Entries before :cutoff belong in the archive.', ['cutoff' => $state['cutoff']]) }}
            {{ $state['last_run'] ? __('Last archiving: :when.', ['when' => $state['last_run']]) : __('Archiving has not run yet.') }}
            {{ __('Every line of a file is one entry as JSON; the .sha256 file next to it verifies the download ("sha256sum -c").') }}
        </p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('Archive files') }}</x-slot>
        @if ($files === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No archive file yet.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-3 font-medium">{{ __('Day') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('File') }}</th>
                            <th class="py-2 pr-3 text-right font-medium">{{ __('Size') }}</th>
                            <th class="py-2 pr-3 font-medium">SHA-256</th>
                            <th class="py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($files as $file)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-3 whitespace-nowrap font-semibold">{{ \Carbon\Carbon::parse($file['day'])->format(\App\Support\HuDate::DATE) }}</td>
                                <td class="py-2 pr-3 font-mono text-xs">{{ $file['name'] }}</td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">{{ $format($file['bytes']) }}</td>
                                <td class="py-2 pr-3 font-mono text-xs break-all text-gray-500 dark:text-gray-400">{{ $file['sha256'] ?? '-' }}</td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    <x-filament::button size="xs" color="gray" icon="heroicon-o-arrow-down-tray" wire:click="download('{{ $file['name'] }}')">{{ __('Download') }}</x-filament::button>
                                    <x-filament::button size="xs" color="gray" wire:click="downloadChecksum('{{ $file['name'] }}')">.sha256</x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
