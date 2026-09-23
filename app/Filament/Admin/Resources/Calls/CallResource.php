<?php

namespace App\Filament\Admin\Resources\Calls;

use App\Clients\ClientTiers;
use App\Enums\CallStatus;
use App\Enums\ClientTier;
use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Resources\BaseResource;
use App\Filament\Admin\Resources\Calls\Pages\ManageCalls;
use App\Models\Call;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use UnitEnum;

class CallResource extends BaseResource
{
    protected static ?string $model = Call::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Helpdesk;

    protected static ?int $navigationSort = 4;

    public static function getModelLabel(): string
    {
        return __('call');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Calls');
    }

    public static function getNavigationLabel(): string
    {
        return __('Calls');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->visibleTo(auth()->user())->with(['client', 'agent'])->withCount('idSessions'))
            ->columns([
                TextColumn::make('arrived_at')->label(__('Arrived'))->dateTime()->sortable(),
                TextColumn::make('external_call_id')->label(__('Call id'))->searchable()->copyable()->toggleable(),
                TextColumn::make('caller_number_e164')->label(__('Number'))->searchable()->placeholder(__('withheld'))
                    ->state(fn (Call $record) => $record->callerNumber())
                    ->tooltip(fn (Call $record) => $record->caller_number_e164 === null && $record->caller_number_raw !== null ? __('Number as the call center sent it; it could not be normalised, so no client was matched by it.') : null),
                TextColumn::make('client.name')->label(__('Client'))->placeholder(__('unknown'))->searchable(),
                TextColumn::make('agent.name')->label(__('Agent'))->placeholder('-'),
                TextColumn::make('tier')->label(__('Level'))->badge()
                    ->formatStateUsing(fn (?ClientTier $state) => $state?->label() ?? '-')
                    ->color(fn (?ClientTier $state) => $state === ClientTier::Premium ? 'warning' : 'gray'),
                TextColumn::make('queue')->label(__('Call queue'))->placeholder('-'),
                TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn (CallStatus $state) => __($state->value)),
                TextColumn::make('id_sessions_count')->label(__('Attempts'))->badge(),
                TextColumn::make('wrap_up_note')->label(__('Call note'))->limit(40)->wrap()->placeholder('-')
                    ->tooltip(fn (Call $record) => $record->wrap_up_note)
                    ->toggleable(),
                TextColumn::make('ended_at')->label(__('Ended'))->dateTime()->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('tier')->label(__('Level'))->options(collect(app(ClientTiers::class)->activeTiers())->mapWithKeys(fn (ClientTier $t) => [$t->value => $t->label()])->all())
                    ->visible(fn () => count(app(ClientTiers::class)->activeTiers()) > 1),
                SelectFilter::make('status')->label(__('Status'))->options(collect(CallStatus::cases())->mapWithKeys(fn ($s) => [$s->value => __($s->value)])),
                SelectFilter::make('agent_user_id')->label(__('Agent'))
                    ->options(fn () => User::withTrashed()->whereIn('id', Call::withTrashed()->visibleTo(auth()->user())->whereNotNull('agent_user_id')->select('agent_user_id'))->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Filter::make('arrived')
                    ->label(__('Arrived between'))
                    ->schema([
                        DatePicker::make('from')->label(__('From'))->native(false),
                        DatePicker::make('until')->label(__('Until'))->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $from) => $q->where('arrived_at', '>=', Carbon::parse($from)->startOfDay()))
                        ->when($data['until'] ?? null, fn (Builder $q, string $until) => $q->where('arrived_at', '<=', Carbon::parse($until)->endOfDay())))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = __('From :date', ['date' => Carbon::parse($data['from'])->translatedFormat('Y. m. d.')]);
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = __('Until :date', ['date' => Carbon::parse($data['until'])->translatedFormat('Y. m. d.')]);
                        }

                        return $indicators;
                    }),
            ])
            ->filtersFormColumns(2)
            ->emptyStateHeading(__('No calls in this view'))
            ->emptyStateDescription(__('Calls arrive from the call center automatically; finished ones leave the dashboard after the short retention and stay here, like in the reports, until the general retention period ends.'))
            ->defaultSort('id', 'desc');
    }

    /**
     * The short call retention only clears the dashboard lists (it soft-deletes
     * ended calls); this list and the reports keep every call until the
     * general retention removes it, so the two never disagree.
     *
     * @return Builder<Call>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCalls::route('/')];
    }
}
