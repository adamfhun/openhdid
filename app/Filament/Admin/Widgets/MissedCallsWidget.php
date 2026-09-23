<?php

namespace App\Filament\Admin\Widgets;

use App\Auth\Permission;
use App\Enums\ClientTier;
use App\Filament\Admin\Pages\Identify;
use App\Filament\Admin\Pages\SearchClients;
use App\Models\Call;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Calls that ended before an agent took them. An agent can call back and
 * identify the client, or mark the call as handled.
 */
class MissedCallsWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return auth()->user()?->can(Permission::CallsView->value) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn () => auth()->user()->activeTier() ? __(':tier missed calls', ['tier' => auth()->user()->activeTier()->label()]) : __('Missed calls'))
            ->query(fn (): Builder => Call::query()->missed()->visibleTo(auth()->user())->with('client')->latest('arrived_at'))
            ->poll(app(Settings::class)->int(SettingKey::CallsDashboardPollSeconds).'s')
            ->paginated([10, 25])
            ->emptyStateHeading(__('No missed calls'))
            ->columns([
                TextColumn::make('arrived_at')->label(__('Arrived'))->since(),
                TextColumn::make('ended_at')->label(__('Ended'))->since()->placeholder('-'),
                TextColumn::make('caller_number_e164')->label(__('Number'))->placeholder(__('withheld'))->copyable()
                    ->state(fn (Call $record) => $record->callerNumber())
                    ->tooltip(fn (Call $record) => $record->caller_number_e164 === null && $record->caller_number_raw !== null ? __('Number as the call center sent it; it could not be normalised, so no client was matched by it.') : null),
                TextColumn::make('client.name')->label(__('Client'))->placeholder(__('Unknown caller'))->weight('bold'),
                TextColumn::make('tier')->label(__('Level'))->badge()
                    ->formatStateUsing(fn (?ClientTier $state) => $state?->label() ?? '-')
                    ->color(fn (?ClientTier $state) => $state === ClientTier::Premium ? 'warning' : 'gray'),
                TextColumn::make('queue')->label(__('Call queue'))->placeholder('-'),
            ])
            ->recordActions([
                Action::make('identify')
                    ->label(__('Identify'))
                    ->icon('heroicon-o-identification')
                    ->button()
                    ->url(fn (Call $call): string => $call->client_id !== null
                        ? Identify::getUrl(['client' => $call->client_id, 'call' => $call->id])
                        : SearchClients::getUrl(['call' => $call->id, 'q' => $call->callerNumber()])),
                Action::make('handled')
                    ->label(__('Handled'))
                    ->icon('heroicon-o-check')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(__('Mark this missed call as handled?'))
                    ->modalDescription(fn (Call $call) => __('The call from :number leaves this list; it stays in the Calls list marked as handled by you. Do this once the caller has been called back or the case is closed.', ['number' => $call->callerNumber() ?? __('withheld number')]))
                    ->modalSubmitActionLabel(__('Mark as handled'))
                    ->action(fn (Call $call) => $call->markHandled(auth()->user())),
            ]);
    }
}
