@php
    $client = $this->getIdentifiedClient();
    $session = $this->getSession();
    $call = $this->getCall();
    $tier = $client ? $this->tierOf($client) : null;
    $muted = 'text-gray-500 dark:text-gray-400';
    $faint = 'text-gray-400 dark:text-gray-500';
@endphp

<x-filament-panels::page>
    @if ($call)
        <x-filament::section>
            <div class="flex flex-wrap items-center gap-4 text-sm">
                <x-filament::badge color="warning">{{ __('Call') }}</x-filament::badge>
                <span>{{ $call->callerNumber() ?? __('withheld number') }}</span>
                <span class="{{ $muted }}">{{ __('arrived') }} {{ $call->arrived_at->format(\App\Support\HuDate::DATETIME) }} ({{ $call->arrived_at->diffForHumans() }})</span>
                <span class="{{ $muted }}">{{ __('The identified client will be attached to this call.') }}</span>
            </div>
        </x-filament::section>
    @endif

    @if ($client === null)
        <x-filament::section :heading="__('Code dictated by the caller')" :description="__('Ask the caller to read the dictated code shown in the client portal. A correct code identifies the client on its own.')">
            <form
                wire:submit="verify"
                class="space-y-4"
                x-data
                x-on:hdid-code-reset.window="$nextTick(() => { const digits = $el.querySelectorAll('.fi-one-time-code-input-digit'); digits.forEach((d) => (d.value = '')); digits[0]?.focus(); })"
            >
                {{ $this->form }}

                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::button type="submit" icon="heroicon-o-check-badge">{{ __('Verify') }}</x-filament::button>
                    @if ($this->failed)
                        <span class="text-sm text-danger-600 dark:text-danger-400">{{ __('Unknown or expired code. Ask the caller to generate a new one.') }}</span>
                    @endif
                </div>

                <p class="text-xs {{ $faint }}">{{ __('Codes requested by the mobile app are IVR codes: only the phone menu accepts them, this page does not.') }}</p>
            </form>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-4">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-400">
                        <x-filament::icon icon="heroicon-o-check-badge" class="h-7 w-7" />
                    </span>
                    <div>
                        <div class="text-xs uppercase tracking-wide text-success-600 dark:text-success-400">{{ __('Identified') }} · {{ $session->decided_at?->format(\App\Support\HuDate::DATETIME) }}</div>
                        <div class="text-2xl font-bold">{{ $client->name }}</div>
                        <div class="text-sm {{ $muted }}">{{ $client->email }}</div>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($tier)
                        <x-filament::badge :color="$tier === \App\Enums\ClientTier::Premium ? 'warning' : 'gray'" size="lg">{{ $tier->label() }}</x-filament::badge>
                    @endif
                    @if ($client->isClosed())
                        <x-filament::badge color="danger">{{ __('Account closed') }}</x-filament::badge>
                    @endif
                    <x-filament::badge :color="$this->usableAnswers($client) > 0 ? 'success' : 'gray'">{{ __(':n answers', ['n' => $this->usableAnswers($client)]) }}</x-filament::badge>
                    <x-filament::badge :color="$client->hasPin() ? 'success' : 'gray'">{{ $client->hasPin() ? __('PIN set') : __('No PIN') }}</x-filament::badge>
                </div>
            </div>

            @if ($client->notes)
                <div class="mt-4 rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                    <div class="text-xs uppercase tracking-wide">{{ __('Notes') }}</div>
                    <div class="mt-1 whitespace-pre-line">{{ $client->notes }}</div>
                </div>
            @endif

            <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="{{ $muted }}">{{ __('Phone numbers') }}</dt>
                    <dd>
                        @forelse ($client->phoneNumbers as $phone)
                            <div>{{ $phone->number_e164 }} <span class="text-xs {{ $faint }}">{{ $phone->source->label() }}@if ($phone->is_primary) · {{ __('primary') }}@endif</span></div>
                        @empty
                            <span class="{{ $faint }}">-</span>
                        @endforelse
                    </dd>
                </div>
                <div>
                    <dt class="{{ $muted }}">{{ __('Company') }}</dt>
                    <dd>{{ $client->externalRecord?->company ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="{{ $muted }}">{{ __('External id') }}</dt>
                    <dd>{{ $client->externalRecord?->external_id ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="{{ $muted }}">{{ __('Packages') }}</dt>
                    <dd>{{ $client->implicit_package ?? '-' }} / {{ $client->explicit_package ?? '-' }}</dd>
                </div>
            </dl>

            <div class="mt-6 flex flex-wrap gap-3">
                <x-filament::button tag="a" :href="$this->clientUrl($client)" icon="heroicon-o-user">{{ __('Open client') }}</x-filament::button>
                <x-filament::button tag="a" :href="$this->dashboardUrl()" color="gray" outlined icon="heroicon-o-phone-arrow-down-left">{{ __('Back to the call queue') }}</x-filament::button>
                <x-filament::button wire:click="startOver" color="gray" outlined icon="heroicon-o-arrow-path">{{ __('Check another code') }}</x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
