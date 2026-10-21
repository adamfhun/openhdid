<?php

namespace App\Filament\Admin\Resources\SyncRunChanges;

use App\Enums\SyncChangeKind;
use App\Filament\Admin\Clusters\System;
use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use App\Filament\Admin\Resources\BaseResource;
use App\Filament\Admin\Resources\SyncRunChanges\Pages\ManageSyncRunChanges;
use App\Filament\Admin\Resources\SyncRuns\SyncRunResource;
use App\Models\SyncRun;
use App\Models\SyncRunChange;
use App\Support\HuDate;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What the EMD sync changed, one row per directory record and run (see
 * RunChangeRecorder). Searchable by external id, name and e-mail, filterable
 * by run and kind, exportable as CSV; the audit log keeps one summary row per
 * run and the account lifecycle events only.
 */
class SyncRunChangeResource extends BaseResource
{
    protected static ?string $model = SyncRunChange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $cluster = System::class;

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'sync-changes';

    public static function getModelLabel(): string
    {
        return __('EMD change');
    }

    public static function getPluralModelLabel(): string
    {
        return __('EMD changes');
    }

    public static function getNavigationLabel(): string
    {
        return __('EMD changes');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('created_at')->label(__('Time'))->dateTime(HuDate::DATETIME_SECONDS),
            TextEntry::make('run.started_at')->label(__('EMD sync run'))->dateTime()->url(fn (SyncRunChange $record): string => SyncRunResource::getUrl('index', ['tableSearch' => $record->run?->started_at?->format(HuDate::DATETIME)])),
            TextEntry::make('external_id')->label(__('External id'))->copyable()->fontFamily('mono'),
            TextEntry::make('kind')->label(__('Change'))->badge()->formatStateUsing(fn (SyncChangeKind $state): string => $state->label())->color(fn (SyncChangeKind $state): string => $state->color()),
            TextEntry::make('name')->label(__('Name'))->placeholder('-'),
            TextEntry::make('email')->label(__('Email'))->placeholder('-'),
            TextEntry::make('subject')->label(__('Account'))->state(fn (SyncRunChange $record): ?string => AuditLogResource::describe($record->subject)['label'])
                ->url(fn (SyncRunChange $record): ?string => AuditLogResource::describe($record->subject)['url'])->placeholder(__('no account')),
            TextEntry::make('fields')->label(__('Changed fields'))->state(fn (SyncRunChange $record): string => static::fieldSummary($record) ?: '-')->columnSpanFull(),
            TextEntry::make('changes')->label(__('Details'))->columnSpanFull()->copyable()->fontFamily('mono')
                ->state(fn (SyncRunChange $record): string => json_encode($record->changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['run', 'subject']))
            ->columns([
                TextColumn::make('created_at')->label(__('Time'))->dateTime(HuDate::DATETIME_SECONDS)->sortable(),
                TextColumn::make('run.started_at')->label(__('Run'))->dateTime()->sortable()
                    ->url(fn (SyncRunChange $record): string => SyncRunResource::getUrl('index', ['tableSearch' => $record->run?->started_at?->format(HuDate::DATETIME)]))->color('primary'),
                TextColumn::make('kind')->label(__('Change'))->badge()->formatStateUsing(fn (SyncChangeKind $state): string => $state->label())->color(fn (SyncChangeKind $state): string => $state->color()),
                TextColumn::make('external_id')->label(__('External id'))->searchable()->copyable()->fontFamily('mono')->size('xs'),
                TextColumn::make('name')->label(__('Name'))->searchable()->placeholder('-')->wrap(),
                TextColumn::make('email')->label(__('Email'))->searchable()->placeholder('-')->toggleable(),
                TextColumn::make('fields')->label(__('Changed fields'))->state(fn (SyncRunChange $record): string => Str::limit(static::fieldSummary($record), 120))->placeholder('-')->wrap()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('sync_run_id')->label(__('Run'))
                    ->options(fn (): array => SyncRun::query()->withTrashed()->latest('started_at')->limit(200)->get()
                        ->mapWithKeys(fn (SyncRun $run): array => [$run->id => $run->started_at?->format(HuDate::DATETIME).($run->dry_run ? ' ('.__('Trial run').')' : '')])->all()),
                SelectFilter::make('kind')->label(__('Change'))->options(collect(SyncChangeKind::cases())->mapWithKeys(fn (SyncChangeKind $k): array => [$k->value => $k->label()])->all()),
                Filter::make('period')->label(__('Period'))->schema([
                    DatePicker::make('from')->label(__('From')),
                    DatePicker::make('until')->label(__('Until')),
                ])->columns(2)->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
                    ->when($data['until'] ?? null, fn ($q, $v) => $q->where('created_at', '<', Carbon::parse($v)->addDay()->startOfDay()))),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc');
    }

    /**
     * The changed columns of every diff in the row, as "subject: field, field",
     * for the list and the CSV.
     */
    public static function fieldSummary(SyncRunChange $record): string
    {
        $parts = [];

        foreach ($record->changes ?? [] as $entry) {
            $subject = Str::before((string) ($entry['event'] ?? ''), '.');
            $action = Str::after((string) ($entry['event'] ?? ''), '.');
            $fields = array_keys((array) ($entry['context']['to'] ?? $entry['context']['attributes'] ?? []));
            $parts[] = $subject.'.'.$action.($fields !== [] ? ': '.implode(', ', $fields) : '');
        }

        return implode(' · ', $parts);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSyncRunChanges::route('/')];
    }
}
