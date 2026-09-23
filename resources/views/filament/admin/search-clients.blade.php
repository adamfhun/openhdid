<x-filament-panels::page>
    @if ($this->callRecord)
        <x-filament::section>
            <div class="flex flex-wrap items-center gap-4 text-sm">
                <x-filament::badge color="warning">{{ __('Call') }}</x-filament::badge>
                <span>{{ $this->callRecord->callerNumber() ?? __('withheld number') }}</span>
                <span class="text-gray-500 dark:text-gray-400">{{ __('arrived') }} {{ $this->callRecord->arrived_at->format(\App\Support\HuDate::DATETIME) }} ({{ $this->callRecord->arrived_at->diffForHumans() }})</span>
                <span class="text-gray-500 dark:text-gray-400">{{ __('Pick the caller below to attach them to this call.') }}</span>
            </div>
        </x-filament::section>
    @endif

    <div class="relative">
        <x-filament::input.wrapper>
            <x-filament::input
                type="search"
                wire:model.live.debounce.300ms="q"
                placeholder="{{ __('Name, e-mail, company, phone number or external id…') }}"
                autofocus
            />
        </x-filament::input.wrapper>
        <div wire:loading.flex wire:target="q" class="pointer-events-none absolute inset-y-0 right-3 items-center">
            <x-filament::loading-indicator class="h-5 w-5 text-gray-400" />
        </div>
    </div>

    <div class="space-y-3" wire:loading.class="opacity-50" wire:target="q">
        @forelse ($this->results as $client)
            <x-filament::section compact>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="font-semibold">{{ $client->name }}</div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $client->email }}
                            @foreach ($client->phoneNumbers as $phone)
                                · {{ $phone->number_e164 }}
                            @endforeach
                            @if ($client->externalRecord)
                                · {{ $client->externalRecord->company }} ({{ $client->externalRecord->external_id }})
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        @if ($this->tierOf($client))
                            <x-filament::badge :color="$this->tierOf($client) === \App\Enums\ClientTier::Premium ? 'warning' : 'gray'">{{ $this->tierOf($client)->label() }}</x-filament::badge>
                        @endif
                        @if ($client->isClosed())
                            <x-filament::badge color="danger">{{ __('Closed') }}</x-filament::badge>
                        @endif
                        <x-filament::badge :color="$this->usableAnswers($client) > 0 ? 'success' : 'gray'">
                            {{ __(':n answers', ['n' => $this->usableAnswers($client)]) }}
                        </x-filament::badge>
                        <x-filament::badge :color="$client->hasPin() ? 'success' : 'gray'">{{ $client->hasPin() ? __('PIN set') : __('No PIN') }}</x-filament::badge>
                        <x-filament::button tag="a" :href="$this->identifyUrl($client)" size="sm" :disabled="$client->isClosed()">
                            {{ __('Identify') }}
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @empty
            @if (mb_strlen(trim($q)) >= 2)
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No client matches.') }}</p>
            @elseif (trim($q) !== '')
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Type at least two characters.') }}</p>
            @endif
        @endforelse

        @if ($this->isTruncated)
            <p class="text-sm text-warning-600 dark:text-warning-400">{{ __('Only the first :n matches are shown; narrow the search.', ['n' => \App\Filament\Admin\Pages\SearchClients::LIMIT]) }}</p>
        @endif
    </div>
</x-filament-panels::page>
