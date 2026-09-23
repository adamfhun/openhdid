@php
    $user = auth()->user();
    $tiers = $user?->handledTiers() ?? [];
    $active = $user?->activeTier();
    $premium = \App\Enums\ClientTier::Premium;
    $viewing = $active ?? (count($tiers) === 1 ? $tiers[0] : null);
    $isPremiumView = $viewing === $premium;
    $canSwitch = count($tiers) > 1;
@endphp

@if ($user && $tiers !== [])
    <style>
        .fi-topbar { box-shadow: inset 0 -3px 0 {{ $isPremiumView ? '#C9A227' : ($viewing === null ? 'color-mix(in oklab, #C9A227 50%, var(--brand-primary))' : 'var(--brand-primary)') }}; }
    </style>

    <div class="hdid-tier-switch" x-data="{ open: false }" @keydown.escape.window="open = false">
        <button
            type="button"
            class="hdid-tier-pill {{ $isPremiumView ? 'hdid-tier-pill-premium' : ($viewing === null ? 'hdid-tier-pill-all' : 'hdid-tier-pill-standard') }}"
            @if ($canSwitch) @click="open = !open" :aria-expanded="open" @else disabled @endif
            title="{{ $canSwitch ? __('Which calls and call queues you see; everything else follows your permissions.') : __('Level in use') }}"
        >
            <span class="hdid-tier-dot"></span>
            <span class="hdid-tier-label">
                <span class="hdid-tier-eyebrow">{{ __('You are viewing') }}</span>
                <span class="hdid-tier-name">
                    @if ($viewing === null) {{ __('All clients') }} @else {{ $viewing === $premium ? __('Premium clients') : __('Standard clients') }} @endif
                </span>
            </span>
            @if ($canSwitch)
                <x-filament::icon icon="heroicon-m-chevron-down" class="hdid-tier-chevron" />
            @endif
        </button>

        @if ($canSwitch)
            <div x-cloak x-show="open" x-transition @click.outside="open = false" class="hdid-tier-menu">
                @foreach ($tiers as $tier)
                    <form method="POST" action="{{ route('tier.switch', $tier->value) }}">
                        @csrf
                        <button type="submit" class="hdid-tier-option {{ $active === $tier ? 'is-current' : '' }} {{ $tier === $premium ? 'is-premium' : '' }}">
                            <span class="hdid-tier-dot"></span>
                            <span>{{ $tier === $premium ? __('Premium clients') : __('Standard clients') }}</span>
                            @if ($active === $tier)<x-filament::icon icon="heroicon-m-check" class="hdid-tier-check" />@endif
                        </button>
                    </form>
                @endforeach
                <form method="POST" action="{{ route('tier.switch', 'all') }}">
                    @csrf
                    <button type="submit" class="hdid-tier-option {{ $active === null ? 'is-current' : '' }}">
                        <span class="hdid-tier-dot"></span>
                        <span>{{ __('All clients') }}</span>
                        @if ($active === null)<x-filament::icon icon="heroicon-m-check" class="hdid-tier-check" />@endif
                    </button>
                </form>
            </div>
        @endif
    </div>
@endif
