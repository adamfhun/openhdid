@php
    $call = $this->getCall();
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

    <x-filament::section :heading="__('One-time identification code told by the caller')" :description="__('Ask the caller to read out the one-time identification code shown in the client portal. A correct code identifies the client on its own.')">
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
            <p class="text-xs {{ $faint }}">{{ __('A correct code opens the client\'s record with an Identified bar; the Identified client button in the header leads back to it from any page.') }}</p>
        </form>
    </x-filament::section>
</x-filament-panels::page>
