@php
    $client = $this->getClient();
    $call = $this->getCall();
    $session = $this->getSession();
    $step = $this->method === 'qa' ? $this->getCurrentStep() : null;
    $answer = $step ? $this->getRevealedAnswer() : null;
    $blind = (bool) $session?->blind_check;
    $lastCheck = $this->method === 'qa' ? $this->getLastCheck() : null;
    $history = $this->getHistory();
    $passed = $this->getCurrentPassed();
    $outcome = $passed ?? $this->getLatestOutcome();
    $methods = $this->availableMethods();
    $shared = $this->getSharedNumbers();
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
            const map = document.querySelector('[data-hdid-blind]') ? { '3': 'undecided' } : { '1': 'accepted', '2': 'rejected', '3': 'undecided' };
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
                                <div>
                                    {{ $phone->number_e164 }}
                                    <span class="text-xs {{ $faint }}">{{ $phone->source->label() }}@if ($phone->is_primary) · {{ __('primary') }}@endif · {{ $phone->verificationLabel() }}</span>
                                    @if (in_array($phone->number_e164, $shared, true))
                                        <x-filament::badge color="warning" size="sm" :tooltip="__('Also on file for another client: the phone menu cannot recognise the caller from this number, so no PIN was asked there.')">{{ __('shared') }}</x-filament::badge>
                                    @endif
                                </div>
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
                        @if ($client->hasPin() && $client->phoneNumbers->isEmpty())
                            <x-filament::badge color="warning" :tooltip="__('The phone menu recognises the caller by a registered number before it asks for the PIN; without a number the PIN cannot be used there.')">{{ __('PIN set, no phone number') }}</x-filament::badge>
                        @else
                            <x-filament::badge :color="$client->hasPin() ? 'success' : 'gray'">{{ $client->hasPin() ? __('PIN set') : __('No PIN') }}</x-filament::badge>
                        @endif
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
                        <span class="ml-auto flex flex-wrap gap-2" data-hdid-no-guard>
                            @if ($outcome->status === \App\Enums\IdSessionStatus::Passed)
                                <x-filament::button tag="a" :href="$this->clientRecordUrl()" size="sm" icon="heroicon-o-user">
                                    {{ __('Open the client record') }}
                                </x-filament::button>
                            @endif
                            <x-filament::button tag="a" :href="$this->dashboardUrl()" color="gray" outlined size="sm" icon="heroicon-o-phone-arrow-down-left">
                                {{ __('Back to the call queue') }}
                            </x-filament::button>
                        </span>
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
                    <p class="mt-3 text-sm {{ $muted }}">{{ __('No automatic method is available for this client. Check a one-time identification code, or record a manual identification with a reason.') }}</p>
                @endif
            </x-filament::section>

            @if ($this->method === 'qa')
                <x-filament::section :heading="__('Question & answer')">
                    @if ($session === null || ! $session->isOpen())
                        @if ($session !== null)
                            <div class="mb-4 flex flex-wrap items-center gap-3">
                                <x-filament::badge :color="$statusColor($session->status)" size="lg">{{ __($session->status->value) }}</x-filament::badge>
                                <span class="text-sm {{ $muted }}">{{ __(':a accepted, :r rejected, :u undecided', ['a' => $session->accepted_count, 'r' => $session->rejected_count, 'u' => $session->undecided_count]) }}</span>
                            </div>
                        @endif

                        <x-filament::button wire:click="startQa" :disabled="! $this->isEligibleForQa() || $client->isClosed()" icon="heroicon-o-play">
                            {{ $session === null ? __('Start Q-A session') : __('Start a new Q-A session') }}
                        </x-filament::button>
                    @elseif ($step)
                        @php
                            // Read afresh: the current question may have been created after the session was loaded.
                            $steps = $session->steps()->orderBy('shown_at')->orderBy('id')->get();
                            $near = $blind && $lastCheck && $lastCheck['step']->is($step);
                            $previous = $blind && $lastCheck && ! $near ? $lastCheck : null;
                        @endphp
                        <div class="space-y-5" data-hdid-judge @if ($blind) data-hdid-blind @endif>
                            {{-- Progress: one dot per question, the goal in words. --}}
                            <div class="flex items-center justify-between gap-3 text-sm {{ $muted }}">
                                <div class="flex items-center gap-1.5" aria-hidden="true">
                                    @foreach ($steps as $done)
                                        <span @class([
                                            'h-2.5 w-2.5 rounded-full',
                                            'bg-success-500' => $done->verdict === \App\Enums\StepVerdict::Accepted,
                                            'bg-danger-500' => $done->verdict === \App\Enums\StepVerdict::Rejected,
                                            'bg-gray-400' => $done->verdict === \App\Enums\StepVerdict::Undecided,
                                            'bg-primary-600 ring-2 ring-primary-600/30' => $done->isPending(),
                                        ])></span>
                                    @endforeach
                                    @for ($i = $steps->count(); $i < $session->max_questions; $i++)
                                        <span class="h-2.5 w-2.5 rounded-full border border-gray-300 dark:border-white/20"></span>
                                    @endfor
                                </div>
                                <span>{{ __(':n. question', ['n' => $session->askedCount() + 1]) }} · {{ __(':a of :req accepted', ['a' => $session->accepted_count, 'req' => $session->required_accepted]) }}</span>
                            </div>

                            @if ($previous)
                                @php $result = $previous['step']->check_result; @endphp
                                <div @class([
                                    'flex items-center gap-2 rounded-lg px-3 py-2 text-sm',
                                    'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $result === \App\Enums\AnswerCheckResult::Matched,
                                    'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' => $result === \App\Enums\AnswerCheckResult::Mismatch,
                                    'bg-gray-50 text-gray-700 dark:bg-white/5 dark:text-gray-300' => $result === \App\Enums\AnswerCheckResult::Near,
                                ]) data-hdid-last-check>
                                    @if ($result === \App\Enums\AnswerCheckResult::Matched)
                                        <x-filament::icon icon="heroicon-m-check-circle" class="h-5 w-5 shrink-0" />
                                        <span>{{ trans_choice('{0} The previous answer matched: :answer|{1} The previous answer matched with 1 typo: :answer|[2,*] The previous answer matched with :n typos: :answer', (int) $previous['step']->check_distance, ['n' => (int) $previous['step']->check_distance, 'answer' => $previous['answer'] ?? '–']) }}</span>
                                    @elseif ($result === \App\Enums\AnswerCheckResult::Mismatch)
                                        <x-filament::icon icon="heroicon-m-x-circle" class="h-5 w-5 shrink-0" />
                                        <span>{{ __('The previous answer did not match.') }}</span>
                                    @else
                                        <x-filament::icon icon="heroicon-m-minus-circle" class="h-5 w-5 shrink-0" />
                                        <span>{{ __('The previous question stayed undecided.') }}</span>
                                    @endif
                                </div>
                            @endif

                            <div>
                                <div class="text-xl font-semibold leading-snug">{{ $step->questionVersion?->text ?? $step->question->text }}</div>
                                @if ($step->questionVersion?->hint)
                                    <div class="mt-1 text-sm {{ $muted }}">{{ $step->questionVersion->hint }}</div>
                                @endif
                            </div>

                            @if ($blind)
                                <form wire:submit="checkAnswer" class="flex gap-3">
                                    <x-filament::input.wrapper class="flex-1">
                                        <x-filament::input type="text" wire:model="givenAnswer" autocomplete="off" maxlength="255" :placeholder="__('What did the caller say?')" x-init="$nextTick(() => $el.focus())" />
                                    </x-filament::input.wrapper>
                                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="checkAnswer">{{ __('Check') }}</x-filament::button>
                                </form>

                                @if ($near)
                                    <div class="flex items-center gap-2 rounded-lg bg-warning-50 px-3 py-2 text-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                                        <x-filament::icon icon="heroicon-m-arrow-path" class="h-5 w-5 shrink-0" />
                                        <span>{{ __('Almost: ask the caller to say it again. One more try.') }}</span>
                                    </div>
                                @endif

                                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm" x-data="{ rules: false }">
                                    <x-filament::link tag="button" wire:click="judge('undecided')" color="gray" size="sm" icon="heroicon-m-question-mark-circle">{{ __('Cannot decide') }}</x-filament::link>
                                    <x-filament::link tag="button" x-on:click="rules = ! rules" color="gray" size="sm" icon="heroicon-m-information-circle">{{ __('What counts as a match?') }}</x-filament::link>
                                    <x-filament::link tag="button" wire:click="cancelQa" color="gray" size="sm" class="ml-auto">{{ __('Cancel session') }}</x-filament::link>
                                    <ul x-show="rules" x-cloak class="w-full list-disc space-y-1 rounded-lg bg-gray-50 py-2 pl-8 pr-3 text-xs {{ $muted }} dark:bg-white/5">
                                        <li>{{ __('Case, accents, punctuation and spaces do not count.') }}</li>
                                        <li>{{ trans_choice('{0} No typo is allowed.|{1} One typo is allowed: a letter different, missing, extra, or swapped with its neighbour.|[2,*] :n typos are allowed: a letter different, missing, extra, or swapped with its neighbour.', (int) $session->typo_tolerance, ['n' => (int) $session->typo_tolerance]) }}</li>
                                        <li>{{ __('Digits, and answers shorter than :short characters, must match exactly.', ['short' => \App\Identification\AnswerMatcher::EXACT_BELOW]) }}</li>
                                        <li>{{ __('A close answer gets one more try; the stored answer shows only after a match.') }}</li>
                                    </ul>
                                </div>
                            @else
                                <div class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-white/5">
                                    <span class="text-sm {{ $muted }}">{{ __('Stored answer') }}:</span>
                                    <span class="ml-1 font-mono text-lg">{{ $answer }}</span>
                                </div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <x-filament::button wire:click="judge('accepted')" wire:loading.attr="disabled" wire:target="judge" color="success" icon="heroicon-o-check" :badge="1" badge-color="gray">{{ __('Accept') }}</x-filament::button>
                                    <x-filament::button wire:click="judge('rejected')" wire:loading.attr="disabled" wire:target="judge" color="danger" icon="heroicon-o-x-mark" :badge="2" badge-color="gray">{{ __('Reject') }}</x-filament::button>
                                    <x-filament::button wire:click="judge('undecided')" wire:loading.attr="disabled" wire:target="judge" color="gray" icon="heroicon-o-question-mark-circle" :badge="3" badge-color="gray">{{ __('Cannot decide') }}</x-filament::button>
                                    <x-filament::link tag="button" wire:click="cancelQa" color="gray" size="sm" class="ml-auto">{{ __('Cancel session') }}</x-filament::link>
                                </div>
                            @endif
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
                    <p class="mt-3 text-xs {{ $faint }}">{{ __('The phone menu asks for the PIN only when it recognised the caller by a registered number; here you check the PIN the caller tells you, regardless of the number.') }}</p>
                </x-filament::section>
            @endif

            <x-filament::section compact>
                <p class="text-sm {{ $muted }}">
                    {{ __('Did the caller generate a one-time identification code in the portal? Check it on the') }}
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

    @if ($this->isGuarded())
        @include('filament.admin.identified-guard', $session?->isOpen()
            ? ['heading' => __('Leave the identification in progress?'), 'message' => __('A question-and-answer session is open. It stays open until it expires, and only this page can continue it; the call queue leads back here.')]
            : ['heading' => __('Leave the identified client?'), 'message' => __('The caller is identified and the call is still in progress. You can come back any time with the Identified client button in the header.')])
    @endif
</x-filament-panels::page>
