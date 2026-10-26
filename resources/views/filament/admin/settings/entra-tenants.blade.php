{{-- Settings page, under the client Entra switch: the tenants of the installation and what the EMD sync makes of their domains. Display only. --}}
@php
    /** @var array{enabled: bool, emd_required: bool, problem: ?string, note: ?string, tenants: list<array{id: string, trusted: bool, domains: list<array{domain: string, color: string, text: string}>}>, unreachable: list<array{domain: string, records: int}>, legend: list<array{color: string, text: string}>} $overview */
    $icons = ['success' => 'heroicon-m-check-circle', 'warning' => 'heroicon-m-exclamation-triangle', 'danger' => 'heroicon-m-x-circle'];
@endphp

<div class="mt-1 w-full space-y-2 text-sm" data-entra-tenants>
    <div class="font-semibold">{{ __('Allowed tenants (CLIENT_ENTRA_TENANTS)') }}</div>

    @unless ($overview['enabled'])
        <p class="text-gray-500 dark:text-gray-400">{{ __('The switch above is off in the saved settings, so this list does not act now.') }}</p>
    @endunless

    @if ($overview['problem'] !== null)
        <p class="font-medium text-danger-600 dark:text-danger-400">{{ __('The tenant list cannot be used, so nobody signs in with Microsoft until it is fixed: :problem', ['problem' => $overview['problem']]) }}</p>
    @endif

    @if ($overview['note'] !== null)
        <p class="font-medium text-warning-600 dark:text-warning-400">{{ $overview['note'] }}</p>
    @endif

    @if ($overview['tenants'] === [] && $overview['problem'] === null)
        <p class="text-gray-500 dark:text-gray-400">{{ __('No tenant is set: give the directory (tenant) ids in CLIENT_ENTRA_TENANTS (a single tenant may also go in CLIENT_ENTRA_TENANT) in the .env file, then restart the services.') }}</p>
    @elseif ($overview['tenants'] === [])
        <p class="text-gray-500 dark:text-gray-400">{{ __('No usable tenant in the list.') }}</p>
    @else
        <ul class="space-y-2">
            @foreach ($overview['tenants'] as $tenant)
                <li class="space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="break-all font-mono text-xs">{{ $tenant['id'] }}</span>
                        @if ($tenant['trusted'])
                            <x-filament::badge color="gray" size="sm">{{ __('any domain (trusted tenant)') }}</x-filament::badge>
                        @endif
                    </div>

                    @unless ($tenant['trusted'])
                        <ul class="space-y-1 ps-4">
                            @foreach ($tenant['domains'] as $domain)
                                <li class="flex flex-wrap items-center gap-2">
                                    <x-filament::badge :color="$domain['color']" :icon="$icons[$domain['color']] ?? null" size="sm">{{ $domain['domain'] }}</x-filament::badge>
                                    <span class="text-gray-600 dark:text-gray-300">{{ $domain['text'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endunless
                </li>
            @endforeach
        </ul>

        <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
            <span>{{ __('Legend:') }}</span>
            @foreach ($overview['legend'] as $entry)
                <span class="inline-flex items-center gap-1">
                    <x-filament::badge :color="$entry['color']" :icon="$icons[$entry['color']] ?? null" size="sm" />
                    <span>{{ $entry['text'] }}</span>
                </span>
            @endforeach
        </div>
    @endif

    @if ($overview['unreachable'] !== [])
        <p class="text-gray-600 dark:text-gray-300">{{ __('No tenant allows these EMD client domains, so their clients cannot sign in with Microsoft: :list', ['list' => implode(', ', array_map(fn (array $item): string => $item['domain'].' ('.__(':count record(s)', ['count' => $item['records']]).')', $overview['unreachable']))]) }}</p>
    @endif
</div>
