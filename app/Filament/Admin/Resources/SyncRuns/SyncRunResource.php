<?php

namespace App\Filament\Admin\Resources\SyncRuns;

use App\Filament\Admin\Clusters\System;
use App\Filament\Admin\Resources\BaseResource;
use App\Filament\Admin\Resources\SyncRunChanges\SyncRunChangeResource;
use App\Filament\Admin\Resources\SyncRuns\Pages\ManageSyncRuns;
use App\Models\SyncRun;
use App\Support\InputLimits;
use App\Sync\AccountProvisioner;
use App\Sync\SyncExternalRecords;
use BackedEnum;
use Filament\Actions\Action;
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
                TextEntry::make('stats.samples.closing_next')->label(__('Closes at the next sync (sample)'))->listWithLineBreaks()->placeholder('-')->columnSpanFull()
                    ->helperText(__('Records absent from this run that one more absent run makes missing, closing their accounts.')),
                TextEntry::make('stats.samples.kind_pending')->label(__('Classification changing (sample)'))->listWithLineBreaks()->placeholder('-')->columnSpanFull()
                    ->visible(fn (SyncRun $record) => ($record->stats['kind_pending'] ?? 0) > 0)
                    ->helperText(__('Records whose e-mail domain now belongs to the other list; the old account closes once the new classification was seen in as many runs in a row as the missed-runs threshold.')),
                TextEntry::make('stats.samples.kind_changed')->label(__('Classification changed (sample)'))->listWithLineBreaks()->placeholder('-')->columnSpanFull()
                    ->visible(fn (SyncRun $record) => ($record->stats['kind_changed'] ?? 0) > 0)
                    ->helperText(__('The old account was closed in this run; a staff account is not created automatically, a client account is.')),
            ]),
            RepeatableEntry::make('stats.email_conflict_samples')->label(__('E-mail conflicts (first :n)', ['n' => 50]))->columnSpanFull()
                ->visible(fn (SyncRun $record) => ($record->stats['email_conflicts'] ?? 0) > 0)
                ->helperText(__('A record whose address could not go to its account. Renamed: the directory renamed the address to one another account already holds; the account kept its old address and cannot sign in until the duplicate is resolved. New record: its address is already on another account, possibly written differently (accents, full-width characters, look-alike domain); the record stays without an account, the existing account is unchanged. Demo record: in production a demo record is not handed to the real person with the same external id. See the operations manual.'))
                ->schema([
                    TextEntry::make('external_id')->label(__('External id')),
                    TextEntry::make('kind')->label(__('Classification'))->formatStateUsing(fn ($state) => $state === 'user' ? __('Staff') : __('Client')),
                    TextEntry::make('email')->label(__('New e-mail in EMD')),
                    TextEntry::make('kept')->label(__('Address kept')),
                    TextEntry::make('case')->label(__('Case'))
                        ->state(fn (TextEntry $component): string => self::emailConflictCase((array) $component->getContainer()->getConstantState())),
                ])->columns(5),
            RepeatableEntry::make('stats.holder_data_removed_samples')->label(__('Accounts handed to another person (first :n)', ['n' => SyncExternalRecords::CHANGE_SAMPLE_SIZE]))->columnSpanFull()
                ->visible(fn (SyncRun $record) => ($record->stats['holder_data_removed'] ?? 0) > 0)
                ->helperText(__('The account went to an EMD record that, as far as the system can tell, belongs to another person: its address moved over from another record that is still in the source (two people), or it was the first link after an administrator changed the address by hand. The account was signed out everywhere, and what the previous holder set up was removed (a staff member\'s password, second factors and sign-in binding; a client\'s PIN, security answers and phone numbers added by hand). Recorded in the audit log.'))
                ->schema([
                    TextEntry::make('external_id')->label(__('External id')),
                    TextEntry::make('kind')->label(__('Classification'))->formatStateUsing(fn ($state) => $state === 'user' ? __('Staff') : __('Client')),
                    TextEntry::make('email')->label(__('E-mail')),
                    TextEntry::make('reason')->label(__('Why'))->formatStateUsing(fn ($state): string => $state === AccountProvisioner::REASON_SYNC_REBOUND
                        ? __('The address moved over from another EMD record')
                        : __('First link after an e-mail change by hand')),
                    TextEntry::make('removed')->label(__('Removed'))->placeholder(__('nothing was set up'))
                        ->formatStateUsing(fn ($state): string => self::traceLabel((string) $state)),
                ])->columns(5),
            RepeatableEntry::make('stats.reissued_samples')->label(__('Records reissued to the same person (first :n)', ['n' => SyncExternalRecords::CHANGE_SAMPLE_SIZE]))->columnSpanFull()
                ->visible(fn (SyncRun $record) => ($record->stats['reissued'] ?? 0) > 0)
                ->helperText(__('The account\'s address came with a new EMD record while the old record is no longer in the source: the directory reissued the same person\'s record under a new external id. The account follows the new record with its PIN, security answers and phone numbers; only its sign-ins ended (a new login link or code is needed). Recorded in the audit log.'))
                ->schema([
                    TextEntry::make('external_id')->label(__('New external id')),
                    TextEntry::make('from_external_id')->label(__('Previous external id'))->placeholder('-'),
                    TextEntry::make('kind')->label(__('Classification'))->formatStateUsing(fn ($state) => $state === 'user' ? __('Staff') : __('Client')),
                    TextEntry::make('email')->label(__('E-mail')),
                ])->columns(4),
            Section::make(__('EMD requests'))->description(__('Status codes of the ID list query and the export; the ID list status comes from the configured response path, or from HTTP when none is set.'))
                ->visible(fn (SyncRun $record): bool => ! empty($record->stats['requests'] ?? null))->columns(3)->schema([
                    TextEntry::make('stats.requests.id_list.status')->label(__('ID list status code'))->placeholder('-')
                        ->formatStateUsing(fn ($state, SyncRun $record) => $state.(($record->stats['requests']['id_list']['http_status'] ?? $state) !== $state ? ' (HTTP '.$record->stats['requests']['id_list']['http_status'].')' : ''))
                        ->visible(fn (SyncRun $record): bool => isset($record->stats['requests']['id_list'])),
                    TextEntry::make('stats.requests.id_list.sent')->label(__('IDs sent'))->placeholder('-')
                        ->visible(fn (SyncRun $record): bool => isset($record->stats['requests']['id_list'])),
                    TextEntry::make('stats.requests.export.status')->label(__('Export status code'))->placeholder('-'),
                    TextEntry::make('stats.requests.id_list.ids')->label(__('IDs in the export payload'))->placeholder('-')->columnSpanFull()
                        ->visible(fn (SyncRun $record): bool => isset($record->stats['requests']['id_list'])),
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
                TextEntry::make('stats.incoming.duplicate')->label(__('Repeated external ids'))->visible(fn (SyncRun $record) => ($record->stats['incoming']['duplicate'] ?? 0) > 0),
                TextEntry::make('stats.incoming.duplicate_email')->label(__('Repeated e-mail addresses'))->visible(fn (SyncRun $record) => ($record->stats['incoming']['duplicate_email'] ?? 0) > 0),
                TextEntry::make('stats.incoming.invalid_email')->label(__('Invalid e-mail addresses'))->visible(fn (SyncRun $record) => ($record->stats['incoming']['invalid_email'] ?? 0) > 0),
                TextEntry::make('stats.invalid_email_kept')->label(__('Known records kept with their old address'))
                    ->helperText(__('The record is in the export, but its new address is not a usable e-mail address: the record and its account stay as they were, with the old address, and do not count as missing. Correct the address in EMD.'))
                    ->visible(fn (SyncRun $record) => ($record->stats['invalid_email_kept'] ?? 0) > 0),
                TextEntry::make('stats.truncated')->label(__('Records with text cut to :max characters', ['max' => InputLimits::NAME]))
                    ->helperText(__('Text cells longer than :max characters are cut to fit; the record is imported.', ['max' => InputLimits::NAME]))
                    ->visible(fn (SyncRun $record) => ($record->stats['truncated'] ?? 0) > 0),
                // The lists this run compared the e-mail domains with, as compared (normalised).
                TextEntry::make('domains_users')->label(__('Staff domains of the run'))
                    ->state(fn (SyncRun $record): string => implode(', ', $record->stats['domains']['users'] ?? []) ?: __('none'))
                    ->visible(fn (SyncRun $record): bool => isset($record->stats['domains'])),
                TextEntry::make('domains_clients')->label(__('Client domains of the run'))
                    ->state(fn (SyncRun $record): string => implode(', ', $record->stats['domains']['clients'] ?? []) ?: __('none'))
                    ->visible(fn (SyncRun $record): bool => isset($record->stats['domains'])),
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
            RepeatableEntry::make('stats.invalid_email_kept_samples')->label(__('Records kept with their old address (first :n)', ['n' => SyncExternalRecords::CHANGE_SAMPLE_SIZE]))->columnSpanFull()
                ->visible(fn (SyncRun $record) => ($record->stats['invalid_email_kept'] ?? 0) > 0)->schema([
                    TextEntry::make('external_id')->label(__('External ID')),
                    TextEntry::make('email')->label(__('Address in the export')),
                    TextEntry::make('kept')->label(__('Address kept')),
                ])->columns(3),
            RepeatableEntry::make('stats.truncated_samples')->label(__('Cut text (first :n)', ['n' => SyncExternalRecords::CHANGE_SAMPLE_SIZE]))->columnSpanFull()
                ->visible(fn (SyncRun $record) => ($record->stats['truncated'] ?? 0) > 0)->schema([
                    TextEntry::make('external_id')->label(__('External ID')),
                    TextEntry::make('fields')->label(__('Cut fields')),
                ])->columns(2),
            RepeatableEntry::make('skipped_rows')->label(__('Skipped rows (first :n)', ['n' => 50]))->columnSpanFull()->schema([
                TextEntry::make('reason')->label(__('Reason'))->badge()->formatStateUsing(fn ($state) => __('sync-skip.'.$state)),
                TextEntry::make('sample')->label(__('Row'))->formatStateUsing(fn ($state) => is_array($state) ? implode(' · ', array_map(fn ($k, $v) => $k.'='.$v, array_keys($state), $state)) : (string) $state),
            ])->columns(2),
        ]);
    }

    /**
     * What the hand-over removed of the previous holder, as a phrase.
     */
    public static function traceLabel(string $trace): string
    {
        return match ($trace) {
            AccountProvisioner::TRACE_PASSWORD => __('the password'),
            AccountProvisioner::TRACE_SECOND_FACTORS => __('the second factors'),
            AccountProvisioner::TRACE_SSO_BINDING => __('the company sign-in binding'),
            AccountProvisioner::TRACE_PIN => __('the PIN'),
            AccountProvisioner::TRACE_ANSWERS => __('the security answers'),
            default => __('the phone numbers added by hand'),
        };
    }

    /**
     * Why an e-mail conflict sample of a run happened, from the flags the
     * sync gives it (AccountProvisioner::$emailConflicts).
     *
     * @param  array{look_alike?: bool, demo?: bool}  $sample
     */
    public static function emailConflictCase(array $sample): string
    {
        return match (true) {
            (bool) ($sample['demo'] ?? false) => __('Demo record, not handed over in production'),
            (bool) ($sample['look_alike'] ?? false) => __('New record with an address already on another account'),
            default => __('Renamed to an address another account holds'),
        };
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
                TextColumn::make('stats.closing_next')->label(__('Closes next'))->placeholder('-')->color(fn ($state) => $state > 0 ? 'warning' : null)
                    ->tooltip(__('Accounts the next sync closes if their records stay absent')),
                TextColumn::make('stats.changes')->label(__('Changes'))->placeholder('-')
                    ->tooltip(__('Directory records this run changed; the detail is on the EMD changes page, not in the audit log')),
                TextColumn::make('error')->label(__('Error'))->limit(60)->placeholder('-')->tooltip(fn (SyncRun $record) => $record->error),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('changes')->label(__('Changes'))->icon('heroicon-o-arrows-right-left')->color('gray')
                    ->url(fn (SyncRun $record): string => SyncRunChangeResource::getUrl('index', ['tableFilters' => ['sync_run_id' => ['value' => $record->id]]]))
                    ->disabled(fn (SyncRun $record): bool => ($record->stats['changes'] ?? 0) === 0)
                    ->tooltip(fn (SyncRun $record): ?string => ($record->stats['changes'] ?? 0) === 0 ? __('This run changed no directory record.') : null),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageSyncRuns::route('/')];
    }
}
