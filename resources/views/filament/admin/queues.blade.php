@php
    $states = $this->getQueues();
    $maintenance = $this->getMaintenance();
    $sharedStore = $this->getSharedStoreWarning();
@endphp

<x-filament-panels::page>
    <div wire:poll.30s>
        <x-filament::section>
            <x-slot name="heading">{{ __('Worker queues') }}</x-slot>
            <x-slot name="description">{{ __('Connection ":connection". Every queue needs a running worker: the default queue carries the e-mails and SMS, the EMD sync queue the sync started from the panel. The scheduler sends a heartbeat to each configured queue every minute; a queue nobody listens to shows no worker.', ['connection' => $maintenance->connection()]) }}</x-slot>

            @if ($sharedStore !== null)
                <div class="mb-3 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-700 dark:bg-danger-500/10 dark:text-danger-400" data-hdid-shared-store>
                    {{ $sharedStore }} {{ __('The heartbeats below may come from that installation\'s workers; see System status.') }}
                </div>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-3 font-medium">{{ __('Queue') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('Worker') }}</th>
                            <th class="py-2 pr-3 text-right font-medium">{{ __('Waiting') }}</th>
                            <th class="py-2 pr-3 text-right font-medium">{{ __('In progress') }}</th>
                            <th class="py-2 pr-3 font-medium">{{ __('Oldest waiting') }}</th>
                            <th class="py-2 pr-3 text-right font-medium">{{ __('Failed') }}</th>
                            <th class="py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($states as $state)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-3 whitespace-nowrap">
                                    <span class="font-semibold">{{ $state->name }}</span>
                                    @unless ($state->configured)
                                        <span class="ml-1 text-xs text-gray-500 dark:text-gray-400">{{ __('no worker configured for this queue') }}</span>
                                    @endunless
                                </td>
                                <td class="py-2 pr-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($state->isAlive() && $sharedStore !== null)
                                            <x-filament::badge color="warning" :tooltip="__('A worker of the other installation may have written this heartbeat.')">{{ __('Seen (shared store)') }}</x-filament::badge>
                                        @elseif ($state->isAlive())
                                            <x-filament::badge color="success">{{ __('Alive') }}</x-filament::badge>
                                        @elseif ($state->heartbeat !== null)
                                            <x-filament::badge :color="$state->configured ? 'danger' : 'gray'">{{ __('Stale') }}</x-filament::badge>
                                        @else
                                            <x-filament::badge :color="$state->configured ? 'danger' : 'gray'">{{ __('No heartbeat') }}</x-filament::badge>
                                        @endif
                                        @if ($state->heartbeat !== null)
                                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('worker seen :when', ['when' => $state->heartbeat->diffForHumans()]) }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $state->waiting ?? '–' }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $state->reserved ?? '–' }}</td>
                                <td class="py-2 pr-3 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $state->oldestWaitingAt?->diffForHumans() ?? '-' }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums {{ $state->failed > 0 ? 'font-semibold text-danger-600 dark:text-danger-400' : '' }}">{{ $state->failed }}</td>
                                <td class="py-2 text-right whitespace-nowrap">{{ ($this->clearQueueAction)(['queue' => $state->name]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @unless ($maintenance->usesDatabase())
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('With the ":connection" queue driver the waiting jobs cannot be listed one by one; the counts above, the failed jobs and the clear action still work.', ['connection' => $maintenance->connection()]) }}</p>
            @endunless
        </x-filament::section>
    </div>
</x-filament-panels::page>
