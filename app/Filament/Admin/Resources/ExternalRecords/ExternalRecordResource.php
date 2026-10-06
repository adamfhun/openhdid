<?php

namespace App\Filament\Admin\Resources\ExternalRecords;

use App\Enums\PrincipalType;
use App\Filament\Admin\Clusters\System;
use App\Filament\Admin\Resources\BaseResource;
use App\Filament\Admin\Resources\ExternalRecords\Pages\ManageExternalRecords;
use App\Models\ExternalRecord;
use App\Settings\SettingKey;
use App\Settings\Settings;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExternalRecordResource extends BaseResource
{
    protected static ?string $model = ExternalRecord::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $cluster = System::class;

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('EMD record');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Enterprise Master Data');
    }

    public static function getNavigationLabel(): string
    {
        return __('Enterprise Master Data');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('external_id')->label(__('External id')),
            TextEntry::make('kind')->label(__('Kind'))->badge()->formatStateUsing(fn (PrincipalType $state) => $state->label()),
            TextEntry::make('name')->label(__('Name')),
            TextEntry::make('email')->label(__('E-mail')),
            TextEntry::make('email_domain')->label(__('E-mail domain')),
            TextEntry::make('company')->label(__('Company'))->placeholder('-'),
            TextEntry::make('title')->label(__('Job title'))->placeholder('-'),
            TextEntry::make('department')->label(__('Department'))->placeholder('-'),
            TextEntry::make('login_name')->label(__('Login name'))->visible(fn (ExternalRecord $record) => filled($record->login_name)),
            TextEntry::make('room')->label(__('Room'))->visible(fn (ExternalRecord $record) => filled($record->room)),
            TextEntry::make('employment_status')->label(__('Employment status'))->visible(fn (ExternalRecord $record) => filled($record->employment_status)),
            TextEntry::make('implicit_package')->label(__('Implicit package'))->placeholder('-'),
            TextEntry::make('explicit_package')->label(__('Explicit package'))->placeholder('-'),
            TextEntry::make('phones')->label(__('Phone numbers'))->listWithLineBreaks()->placeholder('-'),
            TextEntry::make('last_seen_at')->label(__('Last seen in EMD sync'))->dateTime()->placeholder('-'),
            TextEntry::make('missing_since')->label(__('Missing since'))->dateTime()->placeholder(__('present')),
            TextEntry::make('missed_runs')->label(__('Missed EMD sync runs'))
                ->helperText(fn (ExternalRecord $record): ?string => $record->closesAtNextRun(static::threshold()) ? __('One more EMD sync without this record closes its account.') : null),
            KeyValueEntry::make('attributes')->label(__('Other columns'))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('external_id')->label(__('External id'))->searchable()->sortable(),
                TextColumn::make('kind')->label(__('Kind'))->badge()->formatStateUsing(fn (PrincipalType $state) => $state->label()),
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('email')->label(__('E-mail'))->searchable(),
                TextColumn::make('email_domain')->label(__('Domain'))->badge()->color('gray'),
                TextColumn::make('company')->label(__('Company'))->searchable()->sortable(),
                TextColumn::make('title')->label(__('Job title'))->placeholder('-')->searchable()->toggleable(),
                TextColumn::make('department')->label(__('Department'))->placeholder('-')->searchable()->toggleable(),
                TextColumn::make('implicit_package')->label(__('Implicit package'))->placeholder('-')->toggleable(),
                TextColumn::make('explicit_package')->label(__('Explicit package'))->placeholder('-')->toggleable(),
                TextColumn::make('missing_since')->label(__('Missing'))->since()->placeholder(__('present'))->badge()->color('danger'),
                // The operator sees the next run's closures coming: missed once
                // or more is a warning, one run away from closing is red.
                TextColumn::make('missed_runs')->label(__('Missed runs'))->badge()
                    ->state(fn (ExternalRecord $record): ?string => $record->missing_since !== null || (int) $record->missed_runs === 0 ? null
                        : ($record->closesAtNextRun(static::threshold()) ? __('closes at the next sync') : __('missed :n of :m', ['n' => $record->missed_runs, 'm' => static::threshold()])))
                    ->color(fn (ExternalRecord $record): string => $record->closesAtNextRun(static::threshold()) ? 'danger' : 'warning')
                    ->placeholder('-'),
                TextColumn::make('last_seen_at')->label(__('Last seen in EMD sync'))->since()->sortable()
                    ->tooltip(__('The last EMD sync run in which this record was present')),
            ])
            ->filters([
                SelectFilter::make('kind')->label(__('Kind'))->options(collect(PrincipalType::cases())->mapWithKeys(fn ($k) => [$k->value => $k->label()])),
                SelectFilter::make('email_domain')->label(__('E-mail domain'))->searchable()
                    ->options(fn () => ExternalRecord::query()->whereNotNull('email_domain')->distinct()->orderBy('email_domain')->pluck('email_domain', 'email_domain')),
                SelectFilter::make('company')->label(__('Company'))->searchable()
                    ->options(fn () => ExternalRecord::query()->whereNotNull('company')->distinct()->orderBy('company')->pluck('company', 'company')),
                TernaryFilter::make('missing')->label(__('Missing'))->queries(
                    true: fn (Builder $query) => $query->whereNotNull('missing_since'),
                    false: fn (Builder $query) => $query->whereNull('missing_since'),
                ),
                TernaryFilter::make('closing_next')->label(__('Closes at the next sync'))->queries(
                    true: fn (Builder $query) => $query->closingAtNextRun(static::threshold()),
                    false: fn (Builder $query) => $query->whereNotIn('id', ExternalRecord::query()->select('id')->closingAtNextRun(static::threshold())),
                ),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('last_seen_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageExternalRecords::route('/')];
    }

    /**
     * Absent runs before an account is closed (sync.missed_runs_before_close).
     */
    public static function threshold(): int
    {
        return max(1, app(Settings::class)->int(SettingKey::SyncMissedRunsBeforeClose));
    }

    /**
     * Directory records whose accounts close if the next run leaves them out again.
     */
    public static function closingAtNextRunCount(): int
    {
        return ExternalRecord::query()->fromDirectory()->closingAtNextRun(static::threshold())->count();
    }
}
