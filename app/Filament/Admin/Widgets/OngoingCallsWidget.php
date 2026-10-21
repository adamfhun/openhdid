<?php

namespace App\Filament\Admin\Widgets;

use App\Auth\Permission;
use App\CallCenter\CallAlreadyClaimedException;
use App\CallCenter\CallCenterService;
use App\Clients\ClientTiers;
use App\Enums\CallStatus;
use App\Enums\ClientTier;
use App\Filament\Actions\TakeCallAction;
use App\Filament\Admin\Pages\Identify;
use App\Filament\Admin\Pages\SearchClients;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Models\Call;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * The "ongoing calls box": every call the call center reported and has not
 * ended, refreshed by polling. "Identify" claims the call and opens the
 * identification page (or search if the caller is unknown); a call another
 * agent already holds asks for confirmation before it is taken over, and an
 * agent can hand their own call back to the queue.
 */
class OngoingCallsWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can(Permission::CallsView->value) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn () => auth()->user()->activeTier() ? __(':tier ongoing calls', ['tier' => auth()->user()->activeTier()->label()]) : __('Ongoing calls'))
            ->query(fn (): Builder => Call::query()->ongoing()->visibleTo(auth()->user())->with(['client.externalRecord', 'agent', 'idSessions'])->latest('arrived_at'))
            ->poll(app(Settings::class)->int(SettingKey::CallsDashboardPollSeconds).'s')
            ->paginated(false)
            ->emptyStateHeading(__('No ongoing calls'))
            ->columns([
                TextColumn::make('arrived_at')->label(__('Arrived'))->since(),
                TextColumn::make('caller_number_e164')->label(__('Number'))->placeholder(__('withheld'))->copyable()
                    ->state(fn (Call $record) => $record->callerNumber())
                    ->tooltip(fn (Call $record) => $record->caller_number_e164 === null && $record->caller_number_raw !== null ? __('Number as the call center sent it; it could not be normalised, so no client was matched by it.') : null),
                TextColumn::make('client.name')->label(__('Client'))
                    ->state(fn (Call $call): ?string => app(ClientTiers::class)->servedName($call->client))
                    ->placeholder(fn (Call $call) => ($call->metadata['ambiguous_number'] ?? false) === true ? __('Shared number') : __('Unknown caller'))
                    ->tooltip(fn (Call $call) => match (true) {
                        ($call->metadata['ambiguous_number'] ?? false) === true => __('Several clients have this number on file; the caller has to identify.'),
                        app(ClientTiers::class)->isServed($call->client) => $call->client->externalRecord?->jobLabel() ?? __('No title or department in EMD'),
                        default => null,
                    })
                    ->url(fn (Call $call) => app(ClientTiers::class)->isServed($call->client) ? ClientResource::getUrl('view', ['record' => $call->client]) : null)
                    ->weight('bold'),
                TextColumn::make('tier')->label(__('Level'))->badge()
                    ->formatStateUsing(fn (?ClientTier $state) => $state?->label() ?? '-')
                    ->color(fn (?ClientTier $state) => $state === ClientTier::Premium ? 'warning' : 'gray'),
                TextColumn::make('queue')->label(__('Call queue'))->placeholder('-'),
                TextColumn::make('identification')
                    ->label(__('Identification'))
                    ->badge()
                    ->state(fn (Call $call): string => match (static::identificationOf($call)) {
                        'passed' => __('Identified'), 'failed' => __('Failed'), default => __('None'),
                    })
                    ->color(fn (Call $call): string => match (static::identificationOf($call)) {
                        'passed' => 'success', 'failed' => 'danger', default => 'gray',
                    }),
                TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn (CallStatus $state) => __($state->value))->color(fn (CallStatus $state): string => $state === CallStatus::Active ? 'info' : 'warning'),
                TextColumn::make('agent.name')->label(__('Agent'))->placeholder('-'),
            ])
            ->recordActions([
                // Watching the calls (calls.view) is not working them: taking,
                // releasing and handling need the identification permission.
                TakeCallAction::make('take')
                    ->authorize(fn (): bool => auth()->user()?->can(Permission::IdentificationRun->value) ?? false)
                    ->label(fn (Call $call) => match (true) {
                        $call->isHeldBySomeoneElse(auth()->user()) => __('Take over'),
                        static::recordUrl($call) !== null => __('Client record'),
                        default => __('Identify'),
                    })
                    ->icon(fn (Call $call) => static::recordUrl($call) !== null ? 'heroicon-o-user' : 'heroicon-o-identification')
                    ->button()
                    ->color(fn (Call $call) => $call->isHeldBySomeoneElse(auth()->user()) ? 'warning' : 'primary')
                    // The caller the agent identified: nothing left to identify, the record has everything (AZO-07).
                    ->url(fn (Call $call) => static::recordUrl($call))
                    // An identified call belongs to the agent who proved the caller; it is neither released nor taken over.
                    ->disabled(fn (Call $call) => $call->isHeldBySomeoneElse(auth()->user()) && $call->isIdentified())
                    ->tooltip(fn (Call $call) => $call->isHeldBySomeoneElse(auth()->user()) && $call->isIdentified() ? __('The caller is identified: the call stays with the agent who handled it.') : null)
                    ->requiresConfirmation(fn (Call $call) => $call->isHeldBySomeoneElse(auth()->user()))
                    ->modal(fn (Call $record): bool => $record->isHeldBySomeoneElse(auth()->user()))
                    ->modalHeading(fn (Call $call) => __(':name has already taken this call.', ['name' => $call->agent?->name ?? '?']))
                    ->modalDescription(__('Take it over only if you are the one actually talking to the caller. The hand-over is recorded.'))
                    ->modalSubmitActionLabel(__('Take over'))
                    ->action(function (Call $call, array $arguments): void {
                        // The holder the button was drawn for (see TakeCallAction): a
                        // take-over is confirmed against that colleague only.
                        $from = TakeCallAction::from($arguments);

                        try {
                            app(CallCenterService::class)->claim($call, auth()->user(), takeOver: $from !== null, takeOverFrom: $from);
                        } catch (CallAlreadyClaimedException $e) {
                            Notification::make()->title($e->getMessage())->warning()->send();

                            return;
                        } catch (ValidationException $e) {
                            Notification::make()->title($e->validator->errors()->first())->warning()->send();

                            return;
                        }

                        $this->redirect(static::identifyUrl($call));
                    }),
                Action::make('release')
                    ->authorize(fn (): bool => auth()->user()?->can(Permission::IdentificationRun->value) ?? false)
                    ->label(__('Release'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (Call $call) => $call->isHeldBy(auth()->user()))
                    ->disabled(fn (Call $call) => $call->isIdentified())
                    ->tooltip(fn (Call $call) => $call->isIdentified()
                        ? __('The caller is identified: the call stays with the agent who handled it.')
                        : __('The call stays on the dashboard without an agent so that a colleague can take it.'))
                    ->action(function (Call $call): void {
                        try {
                            app(CallCenterService::class)->release($call, auth()->user());
                        } catch (ValidationException $e) {
                            Notification::make()->title($e->validator->errors()->first())->warning()->send();
                        }
                    }),
            ]);
    }

    /**
     * How far the matched client got on this call; nothing about a client no
     * longer served (premium-only mode), like the API.
     */
    public static function identificationOf(Call $call): ?string
    {
        if (! app(ClientTiers::class)->isServed($call->client)) {
            return null;
        }

        return $call->passedSession() !== null ? 'passed' : ($call->clientIdSessions()->isNotEmpty() ? 'failed' : null);
    }

    /**
     * The identified client's record, for the agent holding the call who
     * proved the caller; null for every other row, which keeps its button.
     */
    public static function recordUrl(Call $call): ?string
    {
        if (! $call->isHeldBy(auth()->user()) || ! $call->isIdentified() || ! app(ClientTiers::class)->isServed($call->client)) {
            return null;
        }

        return ClientResource::getUrl('view', ['record' => $call->client_id]);
    }

    /**
     * Where "Identify" leads: the matched client's identification page, or
     * the search when nobody is matched or the client is not served now
     * (a call matched before premium-only went on).
     */
    public static function identifyUrl(Call $call): string
    {
        return app(ClientTiers::class)->isServed($call->client)
            ? Identify::getUrl(['client' => $call->client_id, 'call' => $call->id])
            : SearchClients::getUrl(['call' => $call->id]);
    }
}
