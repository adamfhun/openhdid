{{-- The way back to the identified client (AZO-07): on every staff page while the agent's last pass is
     current (the call in progress in their hands, or :minutes minutes without a call). --}}
@php
    $identification = auth()->user() ? app(\App\Identification\CurrentIdentification::class)->forAgent(auth()->user()) : null;
@endphp

@if ($identification)
    <a
        href="{{ \App\Filament\Admin\Resources\Clients\ClientResource::getUrl('view', ['record' => $identification->client_id]) }}"
        class="hdid-tier-pill hdid-ident-pill"
        data-hdid-no-guard
        data-hdid-identified-client
        title="{{ $identification->call !== null && ! $identification->call->status->isOver()
            ? __('The caller you identified; the call is in progress. Opens the client\'s record.')
            : __('The client you identified in the last :minutes minutes. Opens the client\'s record.', ['minutes' => \App\Identification\CurrentIdentification::RECENT_MINUTES]) }}"
    >
        <x-filament::icon icon="heroicon-m-check-badge" class="hdid-ident-icon" />
        <span class="hdid-tier-label">
            <span class="hdid-tier-eyebrow">{{ __('Identified client') }}</span>
            <span class="hdid-tier-name">{{ $identification->client->name }} <span class="hdid-ident-time">· {{ $identification->decided_at?->format(\App\Support\HuDate::TIME) }}</span></span>
        </span>
    </a>
@endif
