@php
    $client = $this->getClient();
    $call = $this->getCall();
    $session = $this->getSession();
    $step = $this->method === 'qa' ? $this->getCurrentStep() : null;
    $answer = $step ? $this->getRevealedAnswer() : null;
    $history = $this->getHistory();
    $passed = $this->getCurrentPassed();
    $outcome = $passed ?? $this->getLatestOutcome();
    $methods = $this->availableMethods();
    $statusColor = fn ($status) => match ($status) {
        \App\Enums\IdSessionStatus::Passed => 'success',
        \App\Enums\IdSessionStatus::Failed => 'danger',
        \App\Enums\IdSessionStatus::Open => 'info',
        default => 'warning',
    };
    $muted = 'text-gray-500 dark:text-gray-400';
    $faint = 'text-gray-400 dark:text-gray-500';
@endphp

<x-filament-panels::page>
    {{-- Keyboard: 1 / 2 / 3 judge the current question when no field has focus. --}}
    <div
        class="grid gap-6 lg:grid-cols-3"
        x-data="{ judging: false }"
        x-on:keydown.window="
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) || document.activeElement?.isContentEditable) return;
            if (document.querySelector('.fi-modal-open, [role=dialog][aria-modal=true]')) return;
            if (! document.querySelector('[data-hdid-judge]')) return;
            const map = { '1': 'accepted', '2': 'rejected', '3': 'undecided' };
            if (! map[$event.key]) return;
            $event.preventDefault();
            if (judging) return;
            judging = true;
            $wire.judge(map[$event.key]).finally(() => { judging = false; });
        "
    >
        <div class="space-y-6 lg:col-span-1">
            <x-filament::section :heading="__('Client')">
                <dl class="space-y-2 text-sm">
                    <div><dt class="{{ $muted }}">{{ __('Name') }}</dt><dd class="font-semibold">{{ $client->name }}</dd></div>
                    <div><dt class="{{ $muted }}">{{ __('E-mail') }}</dt><dd>{{ $client->email }}</dd></div>
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
                    @if ($client->externalRecord)
                        <div><dt class="{{ $muted }}">{{ __('Company') }}</dt><dd>{{ $client->externalRecord->company }} · {{ $client->externalRecord->external_id }}</dd></div>
                    @endif
                    @if ($client->implicit_package || $client->explicit_package)
                        <div><dt class="{{ $muted }}">{{ __('Packages') }}</dt><dd>{{ $client->implicit_package ?? '-' }} / {{ $client->explicit_package ?? '-' }}</dd></div>
                    @endif
                    @if ($sponsor = $client->sponsor())
                        <div><dt class="{{ $muted }}">{{ __('Linked to') }}</dt><dd>{{ $sponsor->name }}</dd></div>
                    @endif
                    @if ($client->notes)
                        <div class="rounded-lg bg-warning-50 p-3 text-warning-800 dark:bg-warning-500/10 dark:text-warning-300"><dt class="text-xs uppercase tracking-wide">{{ __('Notes') }}</dt><dd class="mt-1 whitespace-pre-line">{{ $client->notes }}</dd></div>
                    @endif
                    <div class="flex flex-wrap gap-2 pt-2">
                        @if ($tier = app(\App\Clients\ClientTiers::class)->tierFor($client))
                            <x-filament::badge :color="$tier === \App\Enums\ClientTier::Premium ? 'warning' : 'gray'" size="lg">{{ $tier->label() }}</x-filament::badge>
                        @endif
                        @if ($client->isClosed())
                            <x-filament::badge color="danger">{{ __('Account closed') }}</x-filament::badge>
                        @endif
                        <x-filament::badge :color="$this->isEligibleForQa() ? 'success' : 'gray'">
                            {{ __(':n / :r answers', ['n' => $this->getUsableAnswerCount(), 'r' => $this->getRequiredAnswerCount()]) }}
                        </x-filament::badge>
                        <x-filament::badge :color="$client->hasPin() ? 'success' : 'gray'">{{ $client->hasPin() ? __('PIN set') : __('No PIN') }}</x-filament::badge>
                    </div>
                </dl>
            </x-filament::section>

            @if ($call)
                <x-filament::section :heading="__('Call')">
                    <dl class="space-y-1 text-sm">
                        <div><dt class="{{ $muted }}">{{ __('Number') }}</dt><dd>{{ $call->callerNumber() ?? __('withheld') }}</dd></div>
                        <div><dt class="{{ $muted }}">{{ __('Arrived') }}</dt><dd>{{ $call->arrived_at->format(\App\Support\HuDate::DATETIME) }} <span class="{{ $faint }}">({{ $call->arrived_at->diffForHumans() }})</span></dd></div>
                        <div><dt class="{{ $muted }}">{{ __('Status') }}</dt><dd>{{ __($call->status->value) }}</dd></div>
                        @if (filled($call->wrap_up_note))
                            <div><dt class="{{ $muted }}">{{ __('Call note') }}</dt><dd class="whitespace-pre-line">{{ $call->wrap_up_note }}</dd></div>
                        @endif
                    </dl>
                    <div class="mt-4 flex flex-wrap gap-2 border-t border-gray-100 pt-3 dark:border-white/5">
                        {{ $this->wrapUpNoteAction }}
                        {{ $this->releaseCallAction }}
                    </div>
                    <div class="mt-4 border-t border-gray-100 pt-3 dark:border-white/5">
                        <p class="mb-2 text-xs {{ $muted }}">{{ __('Someone else on the line? Pick the real caller from the client list; the call moves to them.') }}</p>
                        <x-filament::button tag="a" :href="\App\Filament\Admin\Pages\SearchClients::getUrl(['call' => $call->id])" color="gray" outlined size="sm" icon="heroicon-o-arrow-path">
                            {{ __('Not this client') }}
                        </x-filament::button>
                    </div>
                </x-filament::section>
            @endif
        </div>

        <div class="space-y-6 lg:col-span-2">
            @if ($outcome)
                <x-filament::section>
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::badge :color="$statusColor($outcome->status)" size="lg">{{ $outcome->status === \App\Enums\IdSessionStatus::Passed ? __('Identified') : __('Identification: :status', ['status' => __($outcome->status->value)]) }}</x-filament::badge>
                        <span class="text-sm">{{ __('via :method at :time', ['method' => $outcome->method->label(), 'time' => $outcome->decided_at?->format(\App\Support\HuDate::DATETIME)]) }}</span>
                        @if ($outcome->method === \App\Enums\IdMethod::Manual)
                            <x-filament::badge color="warning">{{ __('recorded manually') }}</x-filament::badge>
                        @endif
                        <x-filament::button tag="a" :href="$this->dashboardUrl()" color="gray" outlined size="sm" icon="heroicon-o-phone-arrow-down-left" class="ml-auto">
                            {{ __('Back to the call queue') }}
                        </x-filament::button>
                    </div>
                </x-filament::section>
            @endif

            <x-filament::section :heading="__('Identification method')">
                <div class="flex flex-wrap gap-2">
                    @foreach ($methods as $key => $m)
                        <x-filament::button
                            wire:click="chooseMethod('{{ $key }}')"
                            :color="$this->method === $key ? 'primary' : 'gray'"
                            :outlined="$this->method !== $key"
                            :disabled="! $m['enabled']"
                        >
                            {{ $m['label'] }}
                        </x-filament::button>
                    @endforeach
                    <span class="ml-auto">{{ $this->manualIdentifyAction }}</span>
                </div>
                @if ($methods[$this->method]['note'])
                    <p class="mt-3 text-sm {{ $muted }}">{{ $methods[$this->method]['note'] }}</p>
                @endif
                @if (! $methods['qa']['enabled'] && ! $methods['pin']['enabled'] && $this->canIdentifyManually())
                    <p class="mt-3 text-sm {{ $muted }}">{{ __('No automatic method is available for this client. Check a dictated code, or record a manual identification with a reason.') }}</p>
                @endif
            </x-filament::section>

            @if ($this->method === 'qa')
                <x-filament::section :heading="__('Question & answer')">
                    @if ($session === null || ! $session->isOpen())
                        @if ($session !== null)
                            <div class="mb-4 flex items-center gap-3">
                                <x-filament::badge :color="$statusColor($session->status)" size="lg">{{ __($session->status->value) }}</x-filament::badge>
                                <span class="text-sm {{ $muted }}">
                                    {{ __(':a accepted, :r rejected, :u undecided', ['a' => $session->accepted_count, 'r' => $session->rejected_count, 'u' => $session->undecided_count]) }}
                                    · {{ __($session->outcome_reason) }}
                                </span>
                            </div>
                        @endif

                        <x-filament::button wire:click="startQa" :disabled="! $this->isEligibleForQa() || $client->isClosed()" icon="heroicon-o-play">
                            {{ $session === null ? __('Start Q-A session') : __('Start a new Q-A session') }}
                        </x-filament::button>
                    @elseif ($step)
                        <div class="space-y-4" data-hdid-judge>
                            <div class="text-sm {{ $muted }}">
                                {{ __('Question :n of :max', ['n' => $session->askedCount() + 1, 'max' => $session->max_questions]) }}
                                · {{ __(':a accepted, need :req', ['a' => $session->accepted_count, 'req' => $session->required_accepted]) }}
                                · {{ __(':r rejected, max :max', ['r' => $session->rejected_count, 'max' => $session->max_rejected]) }}
                            </div>
                            <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                                <div class="text-xs uppercase tracking-wide {{ $muted }}">{{ __('Ask the caller') }}</div>
                                <div class="mt-1 text-lg font-semibold">{{ $step->questionVersion?->text ?? $step->question->text }}</div>
                                @if ($step->questionVersion?->hint)
                                    <div class="text-sm {{ $muted }}">{{ $step->questionVersion->hint }}</div>
                                @endif
                            </div>
                            <div class="hdid-answer-card p-4">
                                <div class="text-xs uppercase tracking-wide {{ $muted }}">{{ __('Stored answer') }}</div>
                                <div class="mt-1 text-lg font-mono">{{ $answer }}</div>
                            </div>
                            <div class="flex flex-wrap gap-3">
                                <x-filament::button wire:click="judge('accepted')" wire:loading.attr="disabled" wire:target="judge" color="success" icon="heroicon-o-check" :badge="1" badge-color="gray">{{ __('Accept') }}</x-filament::button>
                                <x-filament::button wire:click="judge('rejected')" wire:loading.attr="disabled" wire:target="judge" color="danger" icon="heroicon-o-x-mark" :badge="2" badge-color="gray">{{ __('Reject') }}</x-filament::button>
                                <x-filament::button wire:click="judge('undecided')" wire:loading.attr="disabled" wire:target="judge" color="gray" icon="heroicon-o-question-mark-circle" :badge="3" badge-color="gray">{{ __('Cannot decide') }}</x-filament::button>
                                <x-filament::button wire:click="cancelQa" color="gray" outlined class="ml-auto">{{ __('Cancel session') }}</x-filament::button>
                            </div>
                            <p class="text-xs {{ $faint }}">{{ __('Keyboard: 1 accept, 2 reject, 3 cannot decide.') }}</p>
                        </div>
                    @endif
                </x-filament::section>
            @elseif ($this->method === 'pin')
                <x-filament::section :heading="__('PIN told by the caller')">
                    <form wire:submit="verifyPin" class="flex gap-3">
                        <x-filament::input.wrapper class="flex-1">
                            <x-filament::input type="password" wire:model="pin" inputmode="numeric" autocomplete="off" placeholder="••••" autofocus x-init="$nextTick(() => $el.focus())" :disabled="! $methods['pin']['enabled']" />
                        </x-filament::input.wrapper>
                        <x-filament::button type="submit" :disabled="! $methods['pin']['enabled']">{{ __('Verify') }}</x-filament::button>
                    </form>
                </x-filament::section>
            @endif

            <x-filament::section compact>
                <p class="text-sm {{ $muted }}">
                    {{ __('Did the caller generate a dictated code in the portal? Check it on the') }}
                    <a href="{{ \App\Filament\Admin\Pages\VerifyCode::getUrl(array_filter(['call' => $this->call])) }}" class="font-semibold text-primary-600 hover:underline dark:text-primary-400">{{ __('Verify code') }}</a>
                    {{ __('page; the code alone identifies the client.') }}
                </p>
            </x-filament::section>

            <x-filament::section :heading="__('Attempts')" collapsible>
                <ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
                    @forelse ($history as $attempt)
                        <li class="flex flex-wrap items-center gap-3 py-2">
                            <x-filament::badge :color="$statusColor($attempt->status)">{{ __($attempt->status->value) }}</x-filament::badge>
                            <span>{{ $attempt->method->label() }}</span>
                            @if ($attempt->method === \App\Enums\IdMethod::Manual)
                                <x-filament::badge color="warning" size="sm">{{ __('recorded manually') }}</x-filament::badge>
                            @endif
                            <span class="{{ $muted }}">{{ __($attempt->channel->value) }}</span>
                            <span class="{{ $muted }}">{{ $attempt->agent?->name }}</span>
                            <span class="ml-auto {{ $faint }}">{{ $attempt->started_at->format(\App\Support\HuDate::DATETIME_SECONDS) }}</span>
                            @if ($attempt->method === \App\Enums\IdMethod::Manual && $attempt->outcome_reason)
                                <span class="basis-full text-xs {{ $muted }}">{{ \Illuminate\Support\Str::after($attempt->outcome_reason, 'manual:') }}</span>
                            @endif
                        </li>
                    @empty
                        <li class="py-2 {{ $muted }}">{{ __('No attempts yet.') }}</li>
                    @endforelse
                </ul>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
