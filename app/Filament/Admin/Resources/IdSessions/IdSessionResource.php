<?php

namespace App\Filament\Admin\Resources\IdSessions;

use App\Enums\IdChannel;
use App\Enums\IdMethod;
use App\Enums\IdSessionStatus;
use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Resources\BaseResource;
use App\Filament\Admin\Resources\IdSessions\Pages\ManageIdSessions;
use App\Models\IdSession;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

class IdSessionResource extends BaseResource
{
    protected static ?string $model = IdSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Identification;

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('identification session');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Identification sessions');
    }

    public static function getNavigationLabel(): string
    {
        return __('Identification sessions');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('client.name')->label(__('Client'))->placeholder('-'),
                TextEntry::make('agent.name')->label(__('Agent'))->placeholder('-'),
                TextEntry::make('call.external_call_id')->label(__('Call'))->placeholder('-'),
                TextEntry::make('method')->label(__('Method'))->badge()->formatStateUsing(fn (IdMethod $state) => $state->label()),
                TextEntry::make('channel')->label(__('Channel'))->badge()->formatStateUsing(fn (IdChannel $state) => __($state->value)),
                TextEntry::make('status')->label(__('Status'))->badge()->formatStateUsing(fn (IdSessionStatus $state) => __($state->value))->color(fn (IdSessionStatus $state) => static::statusColor($state)),
                TextEntry::make('outcome_reason')->label(__('Outcome'))->formatStateUsing(fn (?string $state) => $state ? __($state) : '-')->placeholder('-'),
                TextEntry::make('started_at')->label(__('Started'))->dateTime(),
                TextEntry::make('decided_at')->label(__('Decided'))->dateTime()->placeholder('-'),
                TextEntry::make('counts')->label(__('Accepted / rejected / undecided'))
                    ->state(fn (IdSession $r) => __(':a / :r / :u (need :req, max :mq questions, max :mr rejected)', ['a' => $r->accepted_count, 'r' => $r->rejected_count, 'u' => $r->undecided_count, 'req' => $r->required_accepted, 'mq' => $r->max_questions, 'mr' => $r->max_rejected])),
            ]),
            RepeatableEntry::make('steps')->label(__('Questions asked'))->columnSpanFull()->schema([
                TextEntry::make('questionVersion.text')->label(__('Question')),
                TextEntry::make('verdict')->label(__('Verdict'))->badge()->formatStateUsing(fn ($state) => __($state->value)),
                TextEntry::make('decided_at')->label(__('Decided'))->dateTime()->placeholder('-'),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client', 'agent']))
            ->columns([
                TextColumn::make('started_at')->label(__('Started'))->dateTime()->sortable(),
                TextColumn::make('client.name')->label(__('Client'))->searchable()->placeholder('-'),
                TextColumn::make('agent.name')->label(__('Agent'))->placeholder('-'),
                TextColumn::make('method')->label(__('Method'))->badge()->formatStateUsing(fn (IdMethod $state) => $state->label()),
                TextColumn::make('channel')->label(__('Channel'))->badge()->formatStateUsing(fn (IdChannel $state) => __($state->value)),
                TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn (IdSessionStatus $state) => __($state->value))->color(fn (IdSessionStatus $state) => static::statusColor($state)),
                TextColumn::make('outcome_reason')->label(__('Outcome'))->formatStateUsing(fn (?string $state) => $state ? __($state) : '-')->placeholder('-'),
                TextColumn::make('accepted_count')->label(__('OK'))->badge()->color('success'),
                TextColumn::make('rejected_count')->label(__('Rejected'))->badge()->color('danger'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(collect(IdSessionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => __($s->value)])),
                SelectFilter::make('method')->label(__('Method'))->options(collect(IdMethod::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()])),
                SelectFilter::make('channel')->label(__('Channel'))->options(collect(IdChannel::cases())->mapWithKeys(fn ($c) => [$c->value => __($c->value)])),
                SelectFilter::make('agent')->label(__('Agent'))->relationship('agent', 'name')->searchable()->preload(),
                Filter::make('client')->schema([TextInput::make('client')->label(__('Client (name or e-mail)'))])
                    ->query(fn (Builder $query, array $data) => $query->when($data['client'] ?? null, fn ($q, $v) => $q->whereHas('client', fn ($c) => $c->where('name', 'like', '%'.$v.'%')->orWhere('email', 'like', '%'.$v.'%')))),
                Filter::make('period')->label(__('Period'))->schema([
                    DatePicker::make('from')->label(__('From')),
                    DatePicker::make('until')->label(__('Until')),
                ])->columns(2)->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $v) => $q->where('started_at', '>=', $v))
                    ->when($data['until'] ?? null, fn ($q, $v) => $q->where('started_at', '<', Carbon::parse($v)->addDay()->startOfDay()))),
            ])
            ->filtersFormColumns(2)
            ->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function statusColor(IdSessionStatus $status): string
    {
        return match ($status) {
            IdSessionStatus::Passed => 'success',
            IdSessionStatus::Failed => 'danger',
            IdSessionStatus::Open => 'info',
            default => 'warning',
        };
    }

    public static function getPages(): array
    {
        return ['index' => ManageIdSessions::route('/')];
    }
}
