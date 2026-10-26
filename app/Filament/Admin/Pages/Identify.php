<?php

namespace App\Filament\Admin\Pages;

use App\Audit\Auditor;
use App\Auth\Permission;
use App\CallCenter\CallCenterService;
use App\Clients\ClientPhones;
use App\Clients\ClientTiers;
use App\Enums\ClientTier;
use App\Enums\IdChannel;
use App\Enums\IdMethod;
use App\Enums\IdSessionStatus;
use App\Enums\StepVerdict;
use App\Filament\Actions\WrapUpNoteAction;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Identification\ClientAnswers;
use App\Identification\CurrentIdentification;
use App\Identification\IdentificationException;
use App\Identification\PinService;
use App\Identification\QaRetryLimit;
use App\Identification\QaSessionEngine;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\IdSessionStep;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The agent's identification screen for one client: Q-A session flow,
 * optional agent PIN entry, manual identification with a reason, and the
 * history of attempts on the current call. One-time codes are checked on
 * the separate "Verify code" page, because the code alone identifies the
 * client. A pass sends the agent on to the client's record, where every
 * detail of the identified client is at hand (AZO-07).
 */
class Identify extends Page
{
    protected static ?string $slug = 'identify/{client}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.admin.identify';

    #[Locked]
    public string $clientId;

    #[Url]
    public ?string $call = null;

    #[Locked]
    public ?string $sessionId = null;

    /** Chosen identification method: qa | pin */
    #[Url]
    public string $method = 'qa';

    public string $pin = '';

    /** Blind check: what the caller answered, typed by the agent; never stored. */
    public string $givenAnswer = '';

    /** Blind check: the step last compared, to show its outcome. */
    #[Locked]
    public ?string $checkedStepId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::IdentificationRun->value) ?? false;
    }

    public function mount(string $client): void
    {
        $this->clientId = ClientResource::getEloquentQuery()->findOrFail($client)->id;

        // A call id is a UUID; anything else in the address names no call
        // (and would not even compare with the UUID column on MariaDB).
        if ($this->call !== null && ! Str::isUuid($this->call)) {
            $this->call = null;
        }

        if ($this->call !== null) {
            $call = Call::query()->whereKey($this->call)->first();

            if ($call === null) {
                $this->call = null;
            } elseif (! $this->isHandledCall($call)) {
                throw new HttpException(403, __('This call belongs to a service level you do not handle.'));
            } elseif ($call->client_id !== $this->clientId) {
                $this->reassignCall($call);
            }
        }

        $this->sessionId = IdSession::query()->open()
            ->where('client_id', $this->clientId)
            ->where('agent_user_id', auth()->id())
            ->value('id');

        if (! in_array($this->method, array_keys($this->availableMethods()), true)) {
            $this->method = 'qa';
        }
    }

    /**
     * The call arrived with (or was matched to) another client: move it to
     * this one and drop the open Q-A session the agent had with the other.
     * Only calls of the agent's own service level can be moved, and only by
     * the agent holding the call, or on a missed call nobody handled yet. A
     * ringing call nobody holds is still in the queue: moving it from a stale
     * address would point the phone menu's PIN check at the wrong account.
     */
    private function reassignCall(Call $call): void
    {
        $agent = $this->agent();

        if (! $this->isHandledCall($call)) {
            throw new HttpException(403, __('This call belongs to a service level you do not handle.'));
        }

        try {
            app(CallCenterService::class)->reassignToClient($call, $this->getClient(), $agent);
        } catch (AuthorizationException $e) {
            throw new HttpException(403, $e->getMessage());
        }
    }

    /**
     * Methods the agent can use right now, with the reason when one is not usable.
     *
     * @return array<string, array{label: string, enabled: bool, note: ?string}>
     */
    public function availableMethods(): array
    {
        $client = $this->getClient();

        return [
            'qa' => [
                'label' => __('Question & answer'),
                'enabled' => $this->isEligibleForQa(),
                'note' => $this->isEligibleForQa() ? null : __('The client has not answered enough questions yet (:n / :r).', ['n' => $this->getUsableAnswerCount(), 'r' => $this->getRequiredAnswerCount()]),
            ],
            'pin' => [
                'label' => __('PIN'),
                'enabled' => $this->agentPinEnabled() && $client->hasPin(),
                'note' => ! $this->agentPinEnabled() ? __('Agents may not verify PINs; the caller has to use the phone menu.') : ($client->hasPin() ? null : __('The client has no PIN.')),
            ],
        ];
    }

    public function chooseMethod(string $method): void
    {
        if (array_key_exists($method, $this->availableMethods())) {
            $this->method = $method;
        }
    }

    public function getTitle(): string
    {
        return __('Identify :name', ['name' => $this->getClient()->name]);
    }

    public function getClient(): Client
    {
        return ClientResource::getEloquentQuery()->with(['phoneNumbers', 'externalRecord'])->findOrFail($this->clientId);
    }

    /**
     * The call named in the URL, but only when the agent may see it: it is
     * of a service level the agent handles and belongs to this client (or
     * to nobody yet). Anything else reads as "no call".
     */
    public function getCall(): ?Call
    {
        if (! $this->call || ! Str::isUuid($this->call)) {
            return null;
        }

        $call = $this->handledCalls()->whereKey($this->call)->first();

        if ($call === null || ($call->client_id !== null && $call->client_id !== $this->clientId)) {
            return null;
        }

        return $call;
    }

    /**
     * The current call when this agent may work on it: the call they hold,
     * or a missed call nobody handled yet (reached from the missed-calls
     * list); only such a call may be changed or attached to an identification.
     */
    public function getHeldCall(): ?Call
    {
        $call = $this->getCall();

        return $call !== null && $call->isAttachableBy($this->agent()) ? $call : null;
    }

    public function getSession(): ?IdSession
    {
        if (! $this->sessionId) {
            return null;
        }

        return IdSession::query()
            ->with('steps')
            ->whereKey($this->sessionId)
            ->where('client_id', $this->clientId)
            ->where('agent_user_id', auth()->id())
            ->first();
    }

    /**
     * Calls of the service levels the agent handles.
     *
     * @return Builder<Call>
     */
    private function handledCalls(): Builder
    {
        return Call::query()->whereIn('tier', array_map(fn (ClientTier $tier) => $tier->value, $this->agent()->handledTiers()));
    }

    private function isHandledCall(Call $call): bool
    {
        return $this->handledCalls()->whereKey($call->id)->exists();
    }

    private function agent(): User
    {
        return auth()->user();
    }

    public function getCurrentStep(): ?IdSessionStep
    {
        $session = $this->getSession();

        if ($session === null || ! $session->isOpen()) {
            return null;
        }

        try {
            return app(QaSessionEngine::class)->next($session, $this->agent());
        } catch (IdentificationException) {
            return null;
        }
    }

    public function getRevealedAnswer(): ?string
    {
        $step = $this->getCurrentStep();

        return $step === null ? null : app(QaSessionEngine::class)->revealAnswer($step);
    }

    /**
     * Blind check: the step last compared in this session, with the stored
     * answer only when the typed one matched it.
     *
     * @return array{step: IdSessionStep, answer: ?string}|null
     */
    public function getLastCheck(): ?array
    {
        $session = $this->getSession();
        $step = $this->checkedStepId === null || $session === null ? null : $session->steps->firstWhere('id', $this->checkedStepId);

        if ($step === null || $step->check_result === null) {
            return null;
        }

        $step->setRelation('session', $session);

        return ['step' => $step, 'answer' => app(QaSessionEngine::class)->revealAnswer($step)];
    }

    /**
     * Numbers of this client that another client also carries: the phone
     * menu cannot recognise the caller from them, which is why a PIN check
     * there never happened.
     *
     * @return list<string>
     */
    public function getSharedNumbers(): array
    {
        return app(ClientPhones::class)->sharedNumbersOf($this->getClient());
    }

    public function getUsableAnswerCount(): int
    {
        return app(ClientAnswers::class)->usableCount($this->getClient());
    }

    public function getRequiredAnswerCount(): int
    {
        return app(ClientAnswers::class)->requiredCount();
    }

    public function isEligibleForQa(): bool
    {
        return app(ClientAnswers::class)->isEligible($this->getClient());
    }

    /**
     * The PIN tab, the button and the server-side guard all read this: the
     * setting has to allow agent verification AND the user has to hold the
     * permission, otherwise revoking the permission changed nothing.
     */
    public function agentPinEnabled(): bool
    {
        return app(PinService::class)->agentVerificationEnabled()
            && (auth()->user()?->can(Permission::IdentificationPinVerify->value) ?? false);
    }

    public function canIdentifyManually(): bool
    {
        return auth()->user()?->can(Permission::IdentificationManual->value) ?? false;
    }

    /**
     * How often this client was tried lately: unsuccessful attempts by any
     * method on any call (the history below shows the current call only),
     * the question-and-answer sessions that count toward the client's limit,
     * and why a new one cannot start (null while it can).
     *
     * @return array{recent: int, count: int, limit: int, hours: int, refusal: ?string}
     */
    public function getQaRetry(): array
    {
        $limit = app(QaRetryLimit::class);
        $client = $this->getClient();

        return [
            'recent' => $limit->recentUnsuccessfulAttempts($client),
            'count' => $limit->unsuccessfulCount($client),
            'limit' => $limit->limit(),
            'hours' => $limit->windowHours(),
            'refusal' => $limit->refusal($client),
        ];
    }

    /**
     * Lifting the question-and-answer lock takes the client management
     * permission, like lifting the client's login lock.
     */
    public function canUnlockQa(): bool
    {
        return auth()->user()?->can(Permission::ClientsManage->value) ?? false;
    }

    /**
     * Shown only while the client is locked out of question and answer: the
     * lock line next to it says why and until when.
     */
    public function unlockQaAction(): Action
    {
        return Action::make('unlockQa')
            ->label(__('Unlock question and answer'))
            ->icon('heroicon-o-lock-open')
            ->color('warning')
            ->size('sm')
            ->visible(fn (): bool => $this->canUnlockQa() && app(QaRetryLimit::class)->lockedUntil($this->getClient()) !== null)
            ->authorize(fn (): bool => $this->canUnlockQa())
            ->requiresConfirmation()
            ->modalHeading(__('Unlock question and answer for this client?'))
            ->modalDescription(__('The client had too many unsuccessful question-and-answer sessions lately (failed, undecided, or cancelled or expired with a shown question that was not accepted). Unlock only when you are sure the caller is the client (for example after a call back to a registered number): the sessions so far stop counting, and a new session can start at once. The unlock is recorded in the audit log with your name.'))
            ->modalSubmitActionLabel(__('Unlock'))
            ->action(function (): void {
                $client = $this->getClient();
                abort_unless($this->canUnlockQa() && app(QaRetryLimit::class)->lockedUntil($client) !== null, 403);

                if (! app(QaRetryLimit::class)->unlock($client, $this->agent())) {
                    Notification::make()->title(__('The lock is still in place'))->body(__('The unlock mark was not kept by the cache (CACHE_STORE), so the sessions still count. Check the cache on the System status page.'))->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title(__('Question and answer unlocked'))->success()->send();
            });
    }

    /**
     * @return Collection<int, IdSession>
     */
    public function getHistory(): Collection
    {
        $call = $this->getCall();

        return IdSession::query()
            ->where('client_id', $this->clientId)
            ->when($call !== null, fn ($q) => $q->where('call_id', $call->id))
            ->with(['agent', 'steps:id,id_session_id,verdict'])
            ->latest('id')
            ->limit(10)
            ->get();
    }

    /**
     * The line under an attempt in the history that tells why a cancelled
     * or expired question-and-answer session still counts as unsuccessful.
     */
    protected function getAttemptNote(IdSession $attempt): ?string
    {
        if (! app(QaRetryLimit::class)->closedEarlyAfterUnacceptedQuestion($attempt)) {
            return null;
        }

        return $attempt->status === IdSessionStatus::Expired
            ? __('Unsuccessful attempt: expired after a question was shown and not accepted.')
            : __('Unsuccessful attempt: cancelled after a question was shown and not accepted.');
    }

    /**
     * The successful identification that counts for THIS conversation: one
     * made on the current call, or, without a call, one this agent made in
     * the last few minutes. Older passes are history, not proof.
     */
    public function getCurrentPassed(): ?IdSession
    {
        return app(CurrentIdentification::class)->forClient($this->agent(), $this->getClient(), $this->getCall());
    }

    /**
     * Whether leaving this page should ask first: the caller is identified
     * and the call is still in the agent's hands, or a question-and-answer
     * session is open, which the agent can only continue from here.
     */
    public function isGuarded(): bool
    {
        if ($this->getSession()?->isOpen()) {
            return true;
        }

        $passed = $this->getCurrentPassed();

        return $passed !== null && app(CurrentIdentification::class)->guards($passed, $this->agent());
    }

    public function clientRecordUrl(): string
    {
        return ClientResource::getUrl('view', ['record' => $this->clientId]);
    }

    /**
     * The latest outcome of this conversation (passed, failed or undecided),
     * shown with the way back to the queue.
     */
    public function getLatestOutcome(): ?IdSession
    {
        $call = $this->getCall();

        return IdSession::query()
            ->where('client_id', $this->clientId)
            ->where('agent_user_id', auth()->id())
            ->whereNot('status', IdSessionStatus::Open)
            ->when($call !== null, fn ($q) => $q->where('call_id', $call->id), fn ($q) => $q->where('decided_at', '>=', now()->subMinutes(CurrentIdentification::RECENT_MINUTES)))
            ->latest('decided_at')
            ->first();
    }

    public function dashboardUrl(): string
    {
        return Dashboard::getUrl();
    }

    public function startQa(): void
    {
        try {
            $session = app(QaSessionEngine::class)->start($this->getClient(), $this->agent(), $this->getHeldCall());
            $this->sessionId = $session->id;
            $this->checkedStepId = null;
        } catch (IdentificationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function judge(string $verdict): void
    {
        $step = $this->getCurrentStep();

        if ($step === null) {
            return;
        }

        try {
            $session = app(QaSessionEngine::class)->decide($step, StepVerdict::from($verdict), $this->agent());
        } catch (IdentificationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->notifyOutcome($session);
    }

    /**
     * Blind check: the system compares the typed answer; the outcome shows
     * under the question, the stored answer only on a match.
     */
    public function checkAnswer(): void
    {
        $step = $this->getCurrentStep();
        $given = $this->givenAnswer;
        $this->givenAnswer = '';

        if ($step === null) {
            return;
        }

        try {
            $checked = app(QaSessionEngine::class)->check($step, $given, $this->agent());
        } catch (IdentificationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->checkedStepId = $checked->id;

        // A close answer stays on the same question; the page says so under the field.
        if ($checked->isPending()) {
            return;
        }

        $session = $this->getSession();
        if ($session !== null) {
            $this->notifyOutcome($session);
        }
    }

    public function cancelQa(): void
    {
        $session = $this->getSession();

        if ($session?->isOpen()) {
            try {
                app(QaSessionEngine::class)->cancel($session, agent: $this->agent());
            } catch (IdentificationException) {
                // Already expired or finished meanwhile; nothing left to cancel.
            }
        }
    }

    public function verifyPin(): void
    {
        if (! $this->agentPinEnabled()) {
            return;
        }

        // A PIN is digits only, never shorter or longer than PinService allows
        // (a shorter one cannot exist): any other input is a typing slip,
        // refused before a session is opened or an attempt is counted against
        // the agent or the client.
        if (! ctype_digit($this->pin) || strlen($this->pin) < PinService::MIN_ALLOWED_LENGTH || strlen($this->pin) > PinService::MAX_ALLOWED_LENGTH) {
            $this->pin = '';
            Notification::make()->title(__('A PIN is made of digits only, at least :min and at most :max of them.', ['min' => PinService::MIN_ALLOWED_LENGTH, 'max' => PinService::MAX_ALLOWED_LENGTH]))->danger()->send();

            return;
        }

        try {
            $session = app(PinService::class)->verify($this->getClient(), $this->pin, IdChannel::Manual, $this->agent(), $this->getHeldCall());
        } catch (IdentificationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->pin = '';
        $this->notifyOutcome($session);
    }

    /**
     * Identification by other means (document, callback, supervisor
     * approval…): allowed with its own permission, always with a reason,
     * recorded as a passed session so it appears in every list and log.
     */
    public function manualIdentifyAction(): Action
    {
        return Action::make('manualIdentify')
            ->label(__('Manual identification'))
            ->icon('heroicon-o-hand-raised')
            ->color('warning')
            ->outlined()
            ->visible(fn () => $this->canIdentifyManually() && ! $this->getClient()->isClosed())
            // Like every other method: a client whose package no longer counts
            // cannot be identified, and the button says so instead of hiding.
            ->disabled(fn () => ! app(ClientTiers::class)->isEntitled($this->getClient()))
            ->tooltip(fn () => app(ClientTiers::class)->isEntitled($this->getClient()) ? null : __('The client\'s account is closed or no longer entitled; it cannot be identified.'))
            ->modalHeading(fn () => __('Identify :name manually', ['name' => $this->getClient()->name]))
            ->modalDescription(__('Use this only when the caller could be identified another way (for example a callback to a registered number or a supervisor\'s approval). The reason is recorded in the audit log with your name.'))
            ->schema([
                Textarea::make('reason')->label(__('Reason'))->required()->minLength(10)->maxLength(500)->rows(3)
                    ->placeholder(__('How was the caller identified, and who approved it?')),
            ])
            ->modalSubmitActionLabel(__('Record as identified'))
            ->action(function (array $data): void {
                try {
                    $session = $this->recordManualIdentification(trim($data['reason']));
                } catch (IdentificationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                $this->notifyOutcome($session);
            });
    }

    private function recordManualIdentification(string $reason): IdSession
    {
        $client = $this->getClient();
        $call = $this->getHeldCall();

        if ($client->isClosed() || ! app(ClientTiers::class)->isEntitled($client)) {
            throw new IdentificationException(__('The client\'s account is closed or no longer entitled; it cannot be identified.'));
        }

        $session = IdSession::query()->create([
            'client_id' => $client->id,
            'agent_user_id' => auth()->id(),
            'call_id' => $call?->id,
            'channel' => IdChannel::Manual,
            'method' => IdMethod::Manual,
            'status' => IdSessionStatus::Open,
            'started_at' => now(),
        ]);
        $session->forceFill(['status' => IdSessionStatus::Passed, 'outcome_reason' => 'manual:'.$reason, 'decided_at' => now()])->save();

        app(Auditor::class)->record('id_session.manual', $session, ['client_id' => $client->id, 'call_id' => $call?->id, 'reason' => $reason], auth()->user());
        app(ClientPhones::class)->verifyFromIdentifiedCall($session);

        return $session;
    }

    public function wrapUpNoteAction(): Action
    {
        return WrapUpNoteAction::for(fn (): ?Call => $this->getHeldCall());
    }

    /**
     * Hand the call back to the queue: the agent took it by mistake or
     * someone else will continue it.
     */
    public function releaseCallAction(): Action
    {
        return Action::make('releaseCall')
            ->label(__('Release call'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->outlined()
            // A plain release is one click; only a question-and-answer session in
            // progress is worth a confirmation, because the caller would start over.
            ->requiresConfirmation(fn (): bool => $this->getSession() !== null)
            ->modal(fn (): bool => $this->getSession() !== null)
            ->modalHeading(__('Release this call?'))
            ->modalDescription(__("The call goes back to the queue without an agent; the question-and-answer session in progress closes and the caller starts over, the attempts made so far stay recorded. The closed session counts as unsuccessful in the client's question-and-answer limit, since a question was already shown and not accepted."))
            ->visible(fn () => ($call = $this->getHeldCall()) !== null && ! $call->status->isOver())
            ->disabled(fn () => $this->getHeldCall()?->isIdentified() ?? false)
            ->tooltip(fn () => ($this->getHeldCall()?->isIdentified() ?? false) ? __('The caller is identified: the call stays with the agent who handled it.') : null)
            ->action(function (): void {
                $call = $this->getHeldCall() ?? abort(403);

                try {
                    app(CallCenterService::class)->release($call, $this->agent());
                } catch (ValidationException $e) {
                    Notification::make()->title($e->validator->errors()->first())->warning()->send();

                    return;
                }

                app(QaSessionEngine::class)->cancelOpenSessions($this->getClient(), $this->agent(), 'call_released');

                $this->redirect($this->dashboardUrl());
            });
    }

    private function notifyOutcome(IdSession $session): void
    {
        if ($session->isOpen()) {
            return;
        }

        $notification = Notification::make()->title(__('Identification: :status', ['status' => __($session->status->value)]));

        match ($session->status) {
            IdSessionStatus::Passed => $notification->success(),
            IdSessionStatus::Failed => $notification->danger(),
            default => $notification->warning(),
        };

        $notification->send();

        // Identified: on to the client's record, where every detail is at hand.
        if ($session->status === IdSessionStatus::Passed) {
            $this->redirect($this->clientRecordUrl());
        }
    }
}
