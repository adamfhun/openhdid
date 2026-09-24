<?php

namespace App\Filament\Admin\Pages;

use App\Audit\Auditor;
use App\Auth\Permission;
use App\CallCenter\CallCenterService;
use App\Enums\ClientTier;
use App\Enums\IdChannel;
use App\Enums\IdMethod;
use App\Enums\IdSessionStatus;
use App\Enums\StepVerdict;
use App\Identification\ClientAnswers;
use App\Identification\IdentificationException;
use App\Identification\PinService;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The agent's identification screen for one client: Q-A session flow,
 * optional agent PIN entry, manual identification with a reason, and the
 * history of attempts on the current call. Dictated codes are checked on
 * the separate "Verify code" page, because the code alone identifies the
 * client.
 */
class Identify extends Page
{
    /** A passed session without a call counts as current for this long. */
    public const RECENT_MINUTES = 30;

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

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::IdentificationRun->value) ?? false;
    }

    public function mount(string $client): void
    {
        $this->clientId = Client::query()->findOrFail($client)->id;

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
     * the agent holding the call (or anyone, while nobody holds it).
     */
    private function reassignCall(Call $call): void
    {
        $agent = $this->agent();

        if (! $this->isHandledCall($call)) {
            throw new HttpException(403, __('This call belongs to a service level you do not handle.'));
        }

        if ($call->isHeldBySomeoneElse($agent)) {
            throw new HttpException(403, __('This call is being handled by another agent.'));
        }

        if ($call->client_id !== null) {
            $previous = $call->client;

            if ($previous !== null) {
                app(QaSessionEngine::class)->cancelOpenSessions($previous, $agent, 'call_reassigned');
                app(Auditor::class)->record('call.client_reassigned', $call, ['from_client_id' => $previous->id, 'to_client_id' => $this->clientId], $agent);
            }
        }

        app(CallCenterService::class)->attachClient($call, $this->getClient(), $agent);
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
        return Client::query()->with(['phoneNumbers', 'externalRecord'])->findOrFail($this->clientId);
    }

    /**
     * The call named in the URL, but only when the agent may see it: it is
     * of a service level the agent handles and belongs to this client (or
     * to nobody yet). Anything else reads as "no call".
     */
    public function getCall(): ?Call
    {
        if (! $this->call) {
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
     * @return Collection<int, IdSession>
     */
    public function getHistory(): Collection
    {
        $call = $this->getCall();

        return IdSession::query()
            ->where('client_id', $this->clientId)
            ->when($call !== null, fn ($q) => $q->where('call_id', $call->id))
            ->with('agent')
            ->latest('id')
            ->limit(10)
            ->get();
    }

    /**
     * The successful identification that counts for THIS conversation: one
     * made on the current call, or, without a call, one this agent made in
     * the last few minutes. Older passes are history, not proof.
     */
    public function getCurrentPassed(): ?IdSession
    {
        $query = IdSession::query()
            ->where('client_id', $this->clientId)
            ->where('status', IdSessionStatus::Passed)
            ->latest('decided_at');

        if (($call = $this->getCall()) !== null) {
            return $query->where('call_id', $call->id)->first();
        }

        return $query
            ->where('agent_user_id', auth()->id())
            ->where('decided_at', '>=', now()->subMinutes(self::RECENT_MINUTES))
            ->first();
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
            ->when($call !== null, fn ($q) => $q->where('call_id', $call->id), fn ($q) => $q->where('decided_at', '>=', now()->subMinutes(self::RECENT_MINUTES)))
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
            ->modalHeading(fn () => __('Identify :name manually', ['name' => $this->getClient()->name]))
            ->modalDescription(__('Use this only when the caller could be identified another way (for example a callback to a registered number or a supervisor\'s approval). The reason is recorded in the audit log with your name.'))
            ->schema([
                Textarea::make('reason')->label(__('Reason'))->required()->minLength(10)->maxLength(500)->rows(3)
                    ->placeholder(__('How was the caller identified, and who approved it?')),
            ])
            ->modalSubmitActionLabel(__('Record as identified'))
            ->action(function (array $data): void {
                $session = $this->recordManualIdentification(trim($data['reason']));
                $this->notifyOutcome($session);
            });
    }

    private function recordManualIdentification(string $reason): IdSession
    {
        $client = $this->getClient();
        $call = $this->getHeldCall();

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

        return $session;
    }

    /**
     * A short note on the call for whoever looks at it later.
     */
    public function wrapUpNoteAction(): Action
    {
        return Action::make('wrapUpNote')
            ->label(fn () => filled($this->getHeldCall()?->wrap_up_note) ? __('Edit call note') : __('Call note'))
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->outlined()
            ->visible(fn () => $this->getHeldCall() !== null)
            ->modalHeading(__('Note on this call'))
            ->schema([
                Textarea::make('note')->label(__('Note'))->maxLength(1000)->rows(4)
                    ->default(fn () => $this->getHeldCall()?->wrap_up_note)
                    ->placeholder(__('What was the call about, what is left to do?')),
            ])
            ->modalSubmitActionLabel(__('Save'))
            ->action(function (array $data): void {
                $call = $this->getHeldCall() ?? abort(403);

                $note = trim((string) ($data['note'] ?? ''));
                $call->forceFill(['wrap_up_note' => $note === '' ? null : $note])->save();
                Notification::make()->title(__('Call note saved'))->success()->send();
            });
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
            ->requiresConfirmation()
            ->modalHeading(__('Release this call?'))
            ->modalDescription(__('The call goes back to the queue without an agent; the identification attempts made so far stay recorded.'))
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
    }
}
