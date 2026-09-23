<?php

namespace App\Filament\Admin\Resources\SyncRuns;

use App\Filament\Admin\Clusters\System;
use App\Filament\Admin\Resources\BaseResource;
use App\Filament\Admin\Resources\SyncRuns\Pages\ManageSyncRuns;
use App\Models\SyncRun;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SyncRunResource extends BaseResource
{
    protected static ?string $model = SyncRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $cluster = System::class;

    protected static ?int $navigationSort = 4;

    public static function getModelLabel(): string
    {
        return __('EMD sync run');
    }

    public static function getPluralModelLabel(): string
    {
        return __('EMD sync runs');
    }

    public static function getNavigationLabel(): string
    {
        return __('EMD sync runs');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('started_at')->label(__('Started'))->dateTime(),
                TextEntry::make('finished_at')->label(__('Finished'))->dateTime()->placeholder('-'),
                TextEntry::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => __($state->value)),
                TextEntry::make('dry_run')->label(__('Trial run'))->formatStateUsing(fn ($state) => $state ? __('yes') : __('no')),
                TextEntry::make('file_name')->label(__('Source'))->placeholder('-'),
                TextEntry::make('error')->label(__('Error'))->color('danger')->placeholder('-'),
                TextEntry::make('stats')->label(__('Counts'))->columnSpanFull()
                    ->state(fn (SyncRun $record) => collect($record->stats ?? [])->filter(fn ($value): bool => is_int($value))->map(fn ($v, $k) => __(ucfirst($k)).': '.$v)->implode(' · ')),
                TextEntry::make('stats.samples.created')->label(__('New (sample)'))->listWithLineBreaks()->placeholder('-')->columnSpanFull(),
                TextEntry::make('stats.samples.closed')->label(__('Closed (sample)'))->listWithLineBreaks()->placeholder('-')->columnSpanFull(),
            ]),
            Section::make(__('Current active accounts'))->description(__('Accounts before this run: not closed and not deleted.'))->columns(2)->schema([
                TextEntry::make('stats.current.users')->label(__('Active staff accounts'))->placeholder('-'),
                TextEntry::make('stats.current.clients')->label(__('Active client accounts'))->placeholder('-'),
            ]),
            Section::make(__('Incoming records after domain filtering'))->description(__('Only listed staff/client domains are imported. Shared domains are classified as staff. Staff records do not automatically create staff accounts.'))->columns(3)->schema([
                TextEntry::make('stats.read')->label(__('Total incoming records'))->placeholder('-'),
                TextEntry::make('stats.incoming.users')->label(__('Incoming staff records'))->placeholder('-'),
                TextEntry::make('stats.incoming.clients')->label(__('Incoming client records'))->placeholder('-'),
                TextEntry::make('stats.incoming.domain_not_allowed')->label(__('Excluded by allowed domains'))->visible(fn (SyncRun $record) => ($record->stats['incoming']['domain_not_allowed'] ?? 0) > 0),
                TextEntry::make('stats.incoming.unclassified')->label(__('Unclassified records'))->placeholder('-'),
                TextEntry::make('stats.incoming.invalid_row')->label(__('Invalid records'))->placeholder('-'),
            ]),
            RepeatableEntry::make('stats.preview')->label(__('Incoming records (first 10 accepted)'))->columnSpanFull()->schema([
                TextEntry::make('external_id')->label(__('External ID')),
                TextEntry::make('name')->label(__('Name')),
                TextEntry::make('email')->label(__('Email')),
                TextEntry::make('kind')->label(__('Classification'))->formatStateUsing(fn ($state) => $state === 'user' ? __('Staff') : __('Client')),
            ])->columns(4),
            RepeatableEntry::make('stats.phone_warnings')->label(__('Invalid phone numbers (first 50)'))->columnSpanFull()
                ->visible(fn (SyncRun $record) => ($record->stats['invalid_phones'] ?? 0) > 0)->schema([
                    TextEntry::make('external_id')->label(__('External id')),
                    TextEntry::make('number')->label(__('Original phone number')),
                ])->columns(2),
            RepeatableEntry::make('skipped_rows')->label(__('Skipped rows (first :n)', ['n' => 50]))->columnSpanFull()->schema([
                TextEntry::make('reason')->label(__('Reason'))->badge()->formatStateUsing(fn ($state) => __('sync-skip.'.$state)),
                TextEntry::make('sample')->label(__('Row'))->formatStateUsing(fn ($state) => is_array($state) ? implode(' · ', array_map(fn ($k, $v) => $k.'='.$v, array_keys($state), $state)) : (string) $state),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->columns([
                TextColumn::make('started_at')->label(__('Started'))->dateTime()->sortable(),
                IconColumn::make('dry_run')->label(__('Trial'))->boolean()->trueIcon('heroicon-o-eye')->falseIcon('heroicon-o-minus')->falseColor('gray'),
                TextColumn::make('source')->label(__('Source type'))->badge(),
                TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => __($state->value))->color(fn ($state) => match ($state->value) {
                    'completed' => 'success', 'failed' => 'danger', default => 'warning',
                }),
                TextColumn::make('file_name')->label(__('Source'))->limit(40)->placeholder('-'),
                TextColumn::make('stats.read')->label(__('Read')),
                TextColumn::make('stats.created')->label(__('New')),
                TextColumn::make('stats.provisioned')->label(__('Provisioned')),
                TextColumn::make('stats.skipped')->label(__('Skipped'))->color(fn ($state) => $state > 0 ? 'warning' : null),
                TextColumn::make('stats.missing')->label(__('Missing')),
                TextColumn::make('stats.closed')->label(__('Closed'))->color('danger'),
                TextColumn::make('error')->label(__('Error'))->limit(60)->placeholder('-')->tooltip(fn (SyncRun $record) => $record->error),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageSyncRuns::route('/')];
    }
}
