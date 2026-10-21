{{-- The identified client's record (AZO-07): what was proven, on which call, and the way on. Its own
     buttons are deliberate moves, so the leave guard lets them through (data-hdid-no-guard). --}}
@php
    $call = $identification->call;
    $muted = 'text-gray-600 dark:text-gray-300';
@endphp

<div data-hdid-no-guard class="rounded-xl border border-success-200 bg-success-50 px-4 py-3 text-sm text-success-900 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-100">
    <div class="flex flex-wrap items-center gap-3">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-success-600 text-white">
            <x-filament::icon icon="heroicon-o-check-badge" class="h-5 w-5" />
        </span>
        <div class="min-w-0">
            <div class="font-semibold">
                {{ __('Identified') }}
                <span class="font-normal {{ $muted }}">· {{ __('via :method at :time', ['method' => $identification->method->label(), 'time' => $identification->decided_at?->format(\App\Support\HuDate::DATETIME)]) }}</span>
                @if ($identification->method === \App\Enums\IdMethod::Manual)
                    <x-filament::badge color="warning" size="sm">{{ __('recorded manually') }}</x-filament::badge>
                @endif
            </div>
            <div class="{{ $muted }}">
                @if ($call)
                    {{ __('Call') }}: {{ $call->callerNumber() ?? __('withheld') }} · {{ __('arrived') }} {{ $call->arrived_at->format(\App\Support\HuDate::TIME) }} · {{ __($call->status->value) }}
                    @if ($guarded)
                        · {{ __('Leaving this page asks first while the call is in progress; the Identified client button in the header leads back here.') }}
                    @endif
                @else
                    {{ __('Without a call; the Identified client button in the header leads back here for :minutes minutes.', ['minutes' => \App\Identification\CurrentIdentification::RECENT_MINUTES]) }}
                @endif
            </div>
        </div>
        <div class="ms-auto flex flex-wrap items-center gap-2">
            {{ $noteAction }}
            <x-filament::button tag="a" :href="$queueUrl" color="gray" outlined size="sm" icon="heroicon-o-phone-arrow-down-left">
                {{ __('Back to the call queue') }}
            </x-filament::button>
        </div>
    </div>
</div>

@if ($guarded)
    @include('filament.admin.identified-guard', [
        'heading' => __('Leave the identified client?'),
        'message' => __('The caller is identified and the call is still in progress. You can come back any time with the Identified client button in the header.'),
    ])
@endif
