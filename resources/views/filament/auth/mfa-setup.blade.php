@php
    $factors = $this->factors();
    $setting = \App\Enums\MfaMethod::tryFrom((string) $this->setting);
    $verified = $this->isVerified();
    $muted = 'text-gray-500 dark:text-gray-400';
@endphp

<x-filament-panels::page.simple>
    <div class="space-y-6">
        <ul class="divide-y divide-gray-200 rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10">
            @foreach ($factors as $factor)
                <li class="flex flex-wrap items-center justify-between gap-3 p-3">
                    <div>
                        <div class="font-semibold">{{ $factor['method']->label() }}</div>
                        <div class="text-xs {{ $muted }}">
                            @if ($factor['enrolled'])
                                {{ __('Set up') }}@if ($factor['method'] === \App\Enums\MfaMethod::Sms && $this->smsNumberLabel()) · {{ $this->smsNumberLabel() }}@endif
                            @else
                                {{ $factor['blocker'] ?? __('Not set up') }}
                            @endif
                        </div>
                    </div>
                    @if ($factor['enrolled'])
                        <x-filament::button wire:click="remove('{{ $factor['method']->value }}')" wire:confirm="{{ __('Remove this second factor? You can set it up again later.') }}" color="danger" size="sm" outlined :disabled="! $verified" :tooltip="$verified ? null : __('Verify your sign-in with a second factor first.')">{{ __('Remove') }}</x-filament::button>
                    @else
                        <x-filament::button wire:click="start('{{ $factor['method']->value }}')" size="sm" :disabled="$factor['blocker'] !== null" :tooltip="$factor['blocker']">{{ __('Set up') }}</x-filament::button>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($setting)
            <div class="space-y-4 rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                <div class="font-semibold">{{ __('Setting up: :method', ['method' => $setting->label()]) }}</div>

                @if ($setting === \App\Enums\MfaMethod::App)
                    <p class="text-sm {{ $muted }}">{{ __('Scan the code with an authenticator app (for example Google Authenticator or Microsoft Authenticator), or type the key by hand; then type the six-digit code the app shows.') }}</p>
                    @if ($this->qr)
                        <img src="{{ $this->qr }}" alt="{{ __('QR code for the authenticator app') }}" class="mx-auto h-48 w-48 rounded bg-white p-2">
                    @endif
                    <div class="text-center font-mono text-sm tracking-widest select-all">{{ $this->secret }}</div>
                    @if ($this->needsBootstrap())
                        <div class="space-y-2">
                            <p class="text-sm {{ $muted }}">{{ __('To prove the account is yours, we also send a code to your e-mail address; a password alone is not enough to register an app.') }}</p>
                            <div class="flex flex-wrap items-center gap-3">
                                <x-filament::button wire:click="sendBootstrapCode" wire:loading.attr="disabled" wire:target="sendBootstrapCode" color="gray" size="sm" icon="heroicon-o-envelope">{{ $this->bootstrapSentTo ? __('Send a new code') : __('Send the e-mail code') }}</x-filament::button>
                                @if ($this->bootstrapSentTo)
                                    <span class="text-sm {{ $muted }}">{{ __('Sent to :to', ['to' => $this->bootstrapSentTo]) }}</span>
                                @endif
                            </div>
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" wire:model="emailCode" inputmode="numeric" autocomplete="one-time-code" maxlength="{{ \App\Filament\Pages\Auth\MfaChallenge::CODE_INPUT_MAX_LENGTH }}" :placeholder="__('Code from the e-mail')" />
                            </x-filament::input.wrapper>
                            @error('emailCode')
                                <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif
                @else
                    @if ($setting === \App\Enums\MfaMethod::Sms)
                        <label class="block text-sm">
                            <span class="{{ $muted }}">{{ __('Mobile number from your EMD record') }}</span>
                            <x-filament::input.wrapper class="mt-1">
                                <x-filament::input.select wire:model="smsNumber">
                                    @foreach ($this->smsOptions() as $number => $masked)
                                        <option value="{{ $number }}">{{ $masked }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </label>
                    @endif
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::button wire:click="sendCode" wire:loading.attr="disabled" wire:target="sendCode" color="gray" size="sm" icon="heroicon-o-paper-airplane">{{ $this->sentTo ? __('Send a new code') : __('Send the code') }}</x-filament::button>
                        @if ($this->sentTo)
                            <span class="text-sm {{ $muted }}">{{ __('Sent to :to', ['to' => $this->sentTo]) }}</span>
                        @endif
                    </div>
                @endif

                <form wire:submit="confirm" class="space-y-3">
                    <x-filament::input.wrapper :valid="! $errors->has('code')">
                        <x-filament::input type="text" wire:model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="{{ \App\Filament\Pages\Auth\MfaChallenge::CODE_INPUT_MAX_LENGTH }}" :placeholder="__('Code')" />
                    </x-filament::input.wrapper>
                    @error('code')
                        <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror
                    <div class="flex gap-3">
                        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="confirm">{{ __('Confirm') }}</x-filament::button>
                        <x-filament::button wire:click="cancel" color="gray" outlined>{{ __('Cancel') }}</x-filament::button>
                    </div>
                </form>
            </div>
        @endif

        <x-filament::button wire:click="continue" class="w-full" :disabled="! $verified">{{ __('Continue to the panel') }}</x-filament::button>

        <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
            @csrf
            <x-filament::link tag="button" type="submit" color="gray" size="sm">{{ __('Sign out') }}</x-filament::link>
        </form>
    </div>
</x-filament-panels::page.simple>
