@php
    $methods = $this->methods();
    $chosen = $this->chosen();
    $muted = 'text-gray-500 dark:text-gray-400';
@endphp

<x-filament-panels::page.simple>
    <div class="space-y-6">
        @if (count($methods) > 1)
            <div class="flex flex-wrap gap-2">
                @foreach ($methods as $method)
                    <x-filament::button wire:click="choose('{{ $method->value }}')" :color="$chosen === $method ? 'primary' : 'gray'" size="sm">
                        {{ $method->label() }}
                    </x-filament::button>
                @endforeach
            </div>
        @endif

        @if ($chosen)
            @if ($chosen->isSent())
                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::button wire:click="sendCode" wire:loading.attr="disabled" wire:target="sendCode" color="gray" icon="heroicon-o-paper-airplane">
                        {{ $this->sentTo ? __('Send a new code') : __('Send the code') }}
                    </x-filament::button>
                    @if ($this->sentTo)
                        <span class="text-sm {{ $muted }}">{{ __('Sent to :to; valid for :minutes minutes.', ['to' => $this->sentTo, 'minutes' => \App\Auth\Mfa\StaffMfa::CODE_TTL_MINUTES]) }}</span>
                    @endif
                </div>
            @else
                <p class="text-sm {{ $muted }}">{{ __('Type the six-digit code your authenticator app shows now.') }}</p>
            @endif

            <form wire:submit="verify" class="space-y-3">
                <x-filament::input.wrapper :valid="! $errors->has('code')">
                    <x-filament::input type="text" wire:model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="{{ \App\Filament\Pages\Auth\MfaChallenge::CODE_INPUT_MAX_LENGTH }}" :placeholder="__('Code')" x-init="$nextTick(() => $el.focus())" />
                </x-filament::input.wrapper>
                @error('code')
                    <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                @enderror
                <x-filament::button type="submit" class="w-full" wire:loading.attr="disabled" wire:target="verify">{{ __('Verify') }}</x-filament::button>
            </form>
        @else
            <p class="text-sm {{ $muted }}">{{ __('Choose one of your second factors.') }}</p>
        @endif

        <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
            @csrf
            <x-filament::link tag="button" type="submit" color="gray" size="sm">{{ __('Sign out') }}</x-filament::link>
        </form>
    </div>
</x-filament-panels::page.simple>
