<?php

namespace App\Filament\Admin\Pages;

use App\Auth\Permission;
use App\Clients\ClientLinks;
use App\Clients\PackageListImpact;
use App\Enums\ClientTier;
use App\Filament\Actions\ExportTableAction;
use App\Filament\Admin\Clusters\System;
use App\Identification\MobileOtpService;
use App\Identification\PinService;
use App\Models\Call;
use App\Models\SyncIdListItem;
use App\Settings\SettingKey;
use App\Settings\SettingRules;
use App\Settings\Settings;
use App\Settings\SettingType;
use App\Support\HuDate;
use App\Sync\IdList;
use App\Sync\RequestPayload;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use RuntimeException;

/**
 * Every runtime business decision, grouped by the first segment of the
 * setting key. Fields are generated from the SettingKey enum.
 */
class ManageSettings extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $cluster = System::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'settings';

    protected string $view = 'filament.admin.manage-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /**
     * The values the form was filled with, as stored. Save writes only the
     * keys whose value differs from these, so a page opened earlier cannot
     * undo what another admin saved in the meantime.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $loaded = [];

    public static function getNavigationLabel(): string
    {
        return __('Settings');
    }

    public function getTitle(): string
    {
        return __('Settings');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    public function mount(): void
    {
        $this->fillFromSettings();
    }

    /**
     * Fill the form with the stored values and remember them as the base
     * that Save compares with.
     */
    private function fillFromSettings(): void
    {
        $settings = app(Settings::class);
        $values = [];
        $this->loaded = [];

        foreach (SettingKey::cases() as $key) {
            $formValue = $this->toFormValue($key, $settings->get($key));
            $values[static::fieldName($key)] = $formValue;
            $this->loaded[$key->value] = $this->asStored($key, $formValue);
        }

        $this->form->fill($values);
    }

    /**
     * A form value in the shape the settings store keeps it, so an untouched
     * field compares equal to what the page was filled with.
     */
    private function asStored(SettingKey $key, mixed $formValue): mixed
    {
        $value = $key->type()->cast($this->fromFormValue($key, $formValue));

        return in_array($key, [SettingKey::SyncUserDomains, SettingKey::SyncClientDomains], true)
            ? SettingRules::normalizeDomains((array) $value)
            : $value;
    }

    /**
     * The keys changed on this page, as stored, keyed by setting key. Keys
     * the user may not change (the EMD request settings without sync
     * management) never count.
     *
     * @param  array<string, mixed>  $state  form state keyed by field name
     * @return array<string, mixed>
     */
    private function pageChanges(array $state): array
    {
        $changes = [];

        foreach (SettingKey::cases() as $key) {
            if ($key->requiresSyncManage() && ! $this->canManageSync()) {
                continue;
            }

            $value = $this->asStored($key, $state[static::fieldName($key)] ?? null);
            if ($value !== ($this->loaded[$key->value] ?? null)) {
                $changes[$key->value] = $value;
            }
        }

        return $changes;
    }

    /**
     * The values Save would leave in place: the stored ones with this page's
     * changes on top, keyed by setting key.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function valuesAfterSave(array $changes): array
    {
        $settings = app(Settings::class);
        $settings->forgetLocal();
        $values = [];

        foreach (SettingKey::cases() as $key) {
            $values[$key->value] = array_key_exists($key->value, $changes) ? $changes[$key->value] : $settings->get($key);
        }

        return $values;
    }

    public function form(Schema $schema): Schema
    {
        $tabs = collect(SettingKey::cases())
            ->groupBy(fn (SettingKey $key) => $key->group())
            ->map(fn ($keys, string $group) => Tab::make($keys->first()->groupLabel())
                ->schema([
                    ...$keys->map(fn (SettingKey $key) => $this->field($key))->all(),
                    ...($group === 'sync' ? [$this->idListSection()] : []),
                ])
                ->columns(2))
            ->values()
            ->all();

        return $schema
            ->components([Tabs::make('settings')->tabs($tabs)->persistTabInQueryString()])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        $settings = app(Settings::class);
        // Only what was changed here is written; the rest stays as stored,
        // including what another admin saved since this page was opened.
        $changes = $this->pageChanges($state);
        $values = $this->valuesAfterSave($changes);

        $this->assertConsistent(collect($values)->mapWithKeys(fn (mixed $value, string $key) => [static::fieldName(SettingKey::from($key)) => $value])->all());
        $this->validateValues($values);
        $impact = $this->assertPackageListsUsable($values);
        $replaced = $this->replacedImages($settings, $changes);
        $settings->setMany($changes);
        $this->deleteImages($replaced);
        // Entitlement follows the lists at once; the links of sponsors whose
        // package left the premium list end now, not at the hourly sweep.
        $endedLinks = $impact['changed'] ? app(ClientLinks::class)->endLinksOfIneligibleSponsors() : 0;
        // The page now shows what is stored, other admins' changes included.
        $this->fillFromSettings();

        $saved = Notification::make()->title(__('Settings saved'))->success();
        if ($endedLinks > 0 || $impact['downgraded']['count'] > 0 || $impact['upgraded']['count'] > 0) {
            $saved->body(__('Package lists applied: :links link(s) ended, :down client(s) moved to the standard tier, :up to premium.', [
                'links' => $endedLinks, 'down' => $impact['downgraded']['count'], 'up' => $impact['upgraded']['count'],
            ]))->persistent();
        }
        $saved->send();

        // Any order is fine: an export payload with {{ ids }} saves even before the ID
        // list is ready, and the sync refuses to run until it is. Say what is missing.
        if ($missing = $this->idsReadiness()) {
            Notification::make()->warning()->persistent()
                ->title(__('The export uses {{ ids }}, but the sync does not run yet'))
                ->body(__('Still missing: :list.', ['list' => implode('; ', $missing)]))
                ->send();
        }
    }

    /**
     * Hard errors of the package lists: a name on both lists, or open
     * clients whose package would be on neither list (they would lose
     * portal access at once). The impact is returned for the post-save report.
     *
     * @param  array<string, mixed>  $values  keyed by SettingKey value
     * @return array<string, mixed>
     */
    private function assertPackageListsUsable(array $values): array
    {
        $impact = app(PackageListImpact::class)->estimate(
            (array) ($values[SettingKey::PackagesPremium->value] ?? []),
            (array) ($values[SettingKey::PackagesStandard->value] ?? []),
        );
        $errors = app(PackageListImpact::class)->errors($impact);

        if ($errors !== []) {
            Notification::make()->title(__('Settings not saved'))->body(implode(' ', $errors))->danger()->persistent()->send();
            throw ValidationException::withMessages(['data.'.static::fieldName(SettingKey::PackagesPremium) => $errors]);
        }

        return $impact;
    }

    /**
     * The package-list impact of saving the form as it stands: only the lists
     * changed on this page count (for the confirmation).
     *
     * @return array<string, mixed>
     */
    private function packageImpact(): array
    {
        $values = $this->valuesAfterSave($this->pageChanges($this->data ?? []));

        return app(PackageListImpact::class)->estimate(
            (array) ($values[SettingKey::PackagesPremium->value] ?? []),
            (array) ($values[SettingKey::PackagesStandard->value] ?? []),
        );
    }

    /**
     * Range and format rules per key plus the cross-key rules; errors land
     * on the offending fields.
     *
     * @param  array<string, mixed>  $values  keyed by SettingKey value
     */
    private function validateValues(array $values): void
    {
        $rules = [];
        $labels = [];
        foreach (SettingKey::cases() as $key) {
            if ($keyRules = SettingRules::for($key)) {
                $rules[str_replace('.', '\\.', $key->value)] = $keyRules;
                $labels[$key->value] = $key->groupLabel().' / '.$key->label();
            }
        }

        $validator = Validator::make($values, $rules, [], $labels);
        $errors = $validator->fails() ? $validator->errors()->toArray() : [];
        foreach (SettingRules::crossErrors($values) as $key => $message) {
            $errors[$key][] = $message;
        }

        if ($errors !== []) {
            $messages = [];
            foreach ($errors as $key => $list) {
                $messages['data.'.static::fieldName(SettingKey::from($key))] = $list;
            }
            Notification::make()->title(__('Settings not saved'))->body(implode(' ', array_map(fn (array $l) => $l[0], $errors)))->danger()->send();

            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * Rules that only make sense across fields: a Q-A session must be able
     * to reach its pass threshold, and a client must have answered at least
     * as many questions as the threshold asks for.
     *
     * @param  array<string, mixed>  $state
     */
    private function assertConsistent(array $state): void
    {
        $int = fn (SettingKey $key): int => (int) ($state[static::fieldName($key)] ?? 0);
        $errors = [];

        if ($int(SettingKey::QaMinAcceptedToPass) > $int(SettingKey::QaMaxQuestionsPerSession)) {
            $errors['data.'.static::fieldName(SettingKey::QaMinAcceptedToPass)] = __('The accepted answers needed to pass cannot exceed the questions per session.');
        }

        if ($int(SettingKey::QaMinAnsweredQuestionsRequired) < $int(SettingKey::QaMinAcceptedToPass)) {
            $errors['data.'.static::fieldName(SettingKey::QaMinAnsweredQuestionsRequired)] = __('A client must have answered at least as many questions as are needed to pass.');
        }

        if ($errors !== []) {
            Notification::make()->title(__('The identification settings contradict each other'))->body(implode(' ', $errors))->danger()->send();

            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Saving asks for confirmation only when the package lists change who
     * sponsors or which tier clients get.
     */
    public function saveAction(): Action
    {
        $impact = app(PackageListImpact::class);

        return Action::make('save')->label(__('Save'))->keyBindings(['mod+s'])
            // A custom heading or description opens the modal on its own in Filament;
            // modal() keeps a plain save one click.
            ->requiresConfirmation(fn (): bool => $impact->needsConfirmation($this->packageImpact()))
            ->modal(fn (): bool => $impact->needsConfirmation($this->packageImpact()))
            ->modalHeading(__('Apply the package list change?'))
            ->modalDescription(fn (): string => $impact->summary($this->packageImpact()))
            ->modalSubmitActionLabel(__('Save'))
            ->action(fn () => $this->save());
    }

    /**
     * The ID list on the EMD tab: its state and what the sync still needs,
     * then the stored list as a paged, searchable table whose selection
     * changes take effect at once (EmbeddedTable renders this page's table).
     */
    private function idListSection(): Section
    {
        return Section::make(__('ID list'))->key('idList')
            ->description(__('The selected IDs still on the list fill {{ ids }} of the export payload, comma-separated. New IDs appear unselected. An ID left out (unticked or gone from the list) is no longer sent, so its records stop arriving and the linked accounts close after the configured number of missed syncs. A selected ID that leaves the list keeps its selection: when it returns, it is sent again without a new decision, and the accounts closed as missing reopen.').' '.__('Selecting and deselecting in the table takes effect at once; it does not wait for Save.'))
            ->columnSpanFull()
            ->schema([
                Text::make(fn (): string => $this->idListStatus()),
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(SyncIdListItem::query())
            ->defaultSort('name')
            ->selectCurrentPageOnly()
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->searchPlaceholder(__('Search by name or ID'))
            ->emptyStateHeading(__('The ID list is empty'))
            ->emptyStateDescription(__('Fetch it from the API with the button above.'))
            ->columns([
                // A long name wraps at about 65–70 characters instead of pushing the other columns out of view.
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable()->weight('semibold')
                    ->wrap()->extraAttributes(['class' => 'max-w-[54ch]']),
                TextColumn::make('external_id')->label(__('ID'))->searchable()->sortable(),
                IconColumn::make('selected')->label(__('Selected'))->boolean()->falseIcon(Heroicon::OutlinedMinus)->falseColor('gray')->alignCenter(),
                TextColumn::make('removed_at')->label(__('On the list'))->badge()
                    ->state(fn (SyncIdListItem $record): string => $record->isListed() ? __('Listed') : __('Left the list'))
                    ->color(fn (SyncIdListItem $record): string => $record->isListed() ? 'success' : 'gray'),
                TextColumn::make('last_seen_at')->label(__('Last seen'))->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('selected')->label(__('Selected')),
                TernaryFilter::make('listed')->label(__('On the list'))->default(true)->queries(
                    true: fn (Builder $query) => $query->whereNull('removed_at'),
                    false: fn (Builder $query) => $query->whereNotNull('removed_at'),
                ),
            ])
            ->headerActions([
                Action::make('fetchIdList')->label(__('Fetch the ID list from the API'))->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (): bool => $this->canManageSync())
                    ->authorize(fn (): bool => $this->canManageSync())
                    ->disabled(fn (): bool => ! app(IdList::class)->isConfigured())
                    ->tooltip(fn (): ?string => app(IdList::class)->isConfigured() ? null : __('EMD_SYNC_ID_LIST_URL is not configured.'))
                    ->action(fn () => $this->fetchIdList()),
                Action::make('selectByIds')->label(__('Select by IDs'))->icon(Heroicon::OutlinedClipboardDocumentList)->color('gray')
                    ->visible(fn (): bool => $this->canManageSync())
                    ->authorize(fn (): bool => $this->canManageSync())
                    ->modalDescription(__('Paste IDs separated by commas, semicolons, spaces or new lines. IDs not on the list are reported and left out.'))
                    ->schema([
                        // Room for every ID of a large list (about 5000 IDs of up to 12 characters).
                        Textarea::make('ids')->label(__('IDs'))->rows(6)->required()->maxLength(100000),
                        Radio::make('mode')->label(__('What should happen?'))->required()->default('add')->options([
                            'add' => __('Add them to the selection'),
                            'only' => __('Select only these (the others are deselected)'),
                        ])->descriptions([
                            'only' => __('Deselected IDs are no longer sent; their accounts close after the configured number of missed syncs.'),
                        ]),
                    ])
                    ->action(fn (array $data) => $this->selectByIds((string) $data['ids'], (string) $data['mode'])),
                Action::make('exportIdList')->label(__('Export CSV'))->icon('heroicon-o-arrow-down-tray')->color('gray')
                    ->action(fn () => ExportTableAction::stream(
                        $this->getFilteredSortedTableQuery(),
                        fn (SyncIdListItem $record): array => [
                            __('ID') => $record->external_id,
                            __('Name') => $record->name,
                            __('Selected') => $record->selected ? __('yes') : __('no'),
                            __('On the list') => $record->isListed() ? __('Listed') : __('Left the list'),
                            __('First seen') => $record->first_seen_at?->format(HuDate::DATETIME),
                            __('Last seen') => $record->last_seen_at?->format(HuDate::DATETIME),
                        ],
                        'id-lista',
                    )),
            ])
            ->recordActions([
                // Two actions with a fixed meaning instead of one toggle: the row is
                // read again when the action runs, so a toggle confirmed as a
                // deselection would select an ID a colleague deselected meanwhile.
                Action::make('selectId')
                    ->label(__('Select this ID'))
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->color('primary')
                    ->visible(fn (SyncIdListItem $record): bool => $this->canManageSync() && ! $record->selected)
                    ->authorize(fn (): bool => $this->canManageSync())
                    ->disabled(fn (SyncIdListItem $record): bool => ! $record->isListed())
                    ->tooltip(fn (SyncIdListItem $record): ?string => ! $record->isListed() ? __('Not on the list now; it can be selected when it returns.') : null)
                    ->action(function (SyncIdListItem $record): void {
                        abort_unless($this->canManageSync(), 403);
                        app(IdList::class)->setSelected([$record->external_id], true);
                    }),
                // Only a deselection has consequences worth a question.
                Action::make('deselectId')
                    ->label(__('Deselect'))
                    ->icon(Heroicon::OutlinedMinusCircle)
                    ->color('gray')
                    ->visible(fn (SyncIdListItem $record): bool => $this->canManageSync() && $record->selected)
                    ->authorize(fn (): bool => $this->canManageSync())
                    ->requiresConfirmation()
                    ->modalHeading(__('Stop sending this ID?'))
                    ->modalDescription(fn (SyncIdListItem $record): string => $this->deselectWarning([$record->external_id]))
                    ->modalSubmitActionLabel(__('Deselect'))
                    ->action(function (SyncIdListItem $record): void {
                        abort_unless($this->canManageSync(), 403);
                        app(IdList::class)->setSelected([$record->external_id], false);
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('selectIds')->label(__('Select this ID'))->icon(Heroicon::OutlinedPlusCircle)
                    ->visible(fn (): bool => $this->canManageSync())
                    ->authorize(fn (): bool => $this->canManageSync())
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $this->notifySelection(
                        app(IdList::class)->setSelected($records->pluck('external_id')->all(), true), true,
                    )),
                BulkAction::make('deselectIds')->label(__('Deselect'))->icon(Heroicon::OutlinedMinusCircle)->color('gray')
                    ->visible(fn (): bool => $this->canManageSync())
                    ->authorize(fn (): bool => $this->canManageSync())
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->modalHeading(__('Stop sending these IDs?'))
                    ->modalDescription(fn (Collection $records): string => $this->deselectWarning($records->where('selected', true)->pluck('external_id')->all()))
                    ->modalSubmitActionLabel(__('Deselect'))
                    ->action(fn (Collection $records) => $this->notifySelection(
                        app(IdList::class)->setSelected($records->pluck('external_id')->all(), false), false,
                    )),
            ]);
    }

    /**
     * What a deselection means, named: the records stop arriving and the
     * accounts close after the missed runs; an empty selection stops the sync.
     *
     * @param  list<string>  $externalIds
     */
    private function deselectWarning(array $externalIds): string
    {
        if ($externalIds === []) {
            return __('None of the chosen IDs is selected; nothing changes.');
        }

        $idList = app(IdList::class);
        $parts = [__('These IDs will no longer be sent in the export: :list. Their EMD records stop arriving, and the linked accounts are closed after :runs missed syncs. Run a trial sync after saving to see the planned closures.', [
            'list' => $idList->describe($externalIds),
            'runs' => app(Settings::class)->int(SettingKey::SyncMissedRunsBeforeClose),
        ])];

        if ($this->exportUsesIds() && array_diff($idList->selectedIds(), array_map('strval', $externalIds)) === []) {
            $parts[] = __('No ID stays selected: the sync does not run until one is selected again.');
        }

        return implode(' ', $parts);
    }

    private function notifySelection(int $changed, bool $selected): void
    {
        Notification::make()->success()
            ->title($selected ? __(':n ID(s) selected', ['n' => $changed]) : __(':n ID(s) deselected', ['n' => $changed]))
            ->send();
    }

    private function selectByIds(string $text, string $mode): void
    {
        abort_unless($this->canManageSync(), 403);
        $idList = app(IdList::class);
        ['known' => $known, 'unknown' => $unknown] = $idList->parseIds($text);

        // A typo must not empty the selection: "only these" with no known ID changes nothing.
        if ($known === []) {
            Notification::make()->warning()->title(__('None of the IDs is on the list; nothing changed.'))
                ->body($unknown === [] ? null : __('Not on the list: :list.', ['list' => implode(', ', array_slice($unknown, 0, 30)).(count($unknown) > 30 ? ' …' : '')]))
                ->send();

            return;
        }

        $result = $mode === 'only' ? $idList->replaceSelection($known) : ['added' => $idList->setSelected($known, true), 'removed' => 0];

        Notification::make()->success()->persistent()
            ->title(__('Selection updated: :added selected, :removed deselected.', $result))
            ->body($unknown === [] ? null : __('Not on the list, left out: :list.', ['list' => implode(', ', array_slice($unknown, 0, 30)).(count($unknown) > 30 ? ' …' : '')]))
            ->send();
    }

    private function exportUsesIds(): bool
    {
        return RequestPayload::uses(app(Settings::class)->string(SettingKey::SyncExportPayload), 'ids');
    }

    /**
     * What the sync still needs before an export with {{ ids }} can run, in
     * the order the steps are done; empty when ready or when ids is not used.
     *
     * @return list<string>
     */
    private function idsReadiness(): array
    {
        if (! $this->exportUsesIds()) {
            return [];
        }

        $idList = app(IdList::class);
        $missing = [];
        if (! $idList->isConfigured()) {
            $missing[] = __('the ID list address (EMD_SYNC_ID_LIST_URL in .env)');
        }
        if (! RequestPayload::isJsonObject(app(Settings::class)->string(SettingKey::SyncIdListPayload))) {
            $missing[] = __('the ID list request payload (above)');
        }
        $counts = $idList->counts();
        if ($counts['listed'] === 0) {
            $missing[] = __('fetching the ID list (button below)');
        }
        if ($counts['selected'] === 0) {
            $missing[] = __('at least one selected ID (table below)');
        }

        return $missing;
    }

    private function fetchIdList(): void
    {
        abort_unless($this->canManageSync(), 403);

        try {
            $result = app(IdList::class)->fetchNow();
        } catch (RuntimeException $exception) {
            Notification::make()->title(__('The ID list could not be fetched'))->body($exception->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()->title(__('ID list fetched'))->success()->body(__('Status :status (HTTP :http), :count IDs; new: :added, renamed: :renamed, removed: :removed, returned: :returned.', [
            'status' => $result['status'], 'http' => $result['http_status'], 'count' => $result['received'],
            'added' => $result['added'], 'renamed' => $result['renamed'], 'removed' => $result['removed'], 'returned' => $result['returned'],
        ]))->send();
    }

    private function idListStatus(): string
    {
        $idList = app(IdList::class);
        $status = $idList->status();
        $parts = [];

        if (! isset($status['at'])) {
            $parts[] = __('The ID list has not been fetched yet.');
        } else {
            $replace = [
                'at' => Carbon::parse($status['at'])->format(HuDate::DATETIME),
                'status' => $status['status'] ?? '-', 'http' => $status['http_status'] ?? '-', 'count' => $status['received'] ?? 0,
            ];
            $parts[] = ($status['error'] ?? null) === null
                ? __('Last query: :at, status :status (HTTP :http), :count IDs.', $replace)
                : __('Last query failed: :at, status :status (HTTP :http).', $replace).' '.$status['error'];
        }

        $counts = $idList->counts();
        $parts[] = __(':selected of :listed listed IDs selected.', $counts);

        if ($removed = $idList->selectedButRemoved()) {
            $parts[] = __('Selected but no longer on the list, so not sent; if they return, they are sent again automatically: :list.', [
                'list' => implode(', ', array_slice($removed, 0, 10)).(count($removed) > 10 ? ' '.__('and :n more', ['n' => count($removed) - 10]) : ''),
            ]);
        }

        if ($missing = $this->idsReadiness()) {
            $parts[] = __('The export uses {{ ids }}; the sync does not run until these are done: :list.', ['list' => implode('; ', $missing)]);
        }

        return implode(' ', $parts);
    }

    /**
     * The importer's target fields, keyed by themselves: the only keys the
     * column mapping may carry.
     *
     * @return array<string, string>
     */
    public static function mappingFields(): array
    {
        $fields = array_keys(SettingKey::SyncColumnMapping->default());

        return array_combine($fields, $fields);
    }

    public static function fieldName(SettingKey $key): string
    {
        return str_replace('.', '__', $key->value);
    }

    private function field(SettingKey $key): Component
    {
        $name = static::fieldName($key);
        $label = $key->label();

        if ($key === SettingKey::SyncExportPayload || $key === SettingKey::SyncIdListPayload) {
            return Textarea::make($name)->label($key === SettingKey::SyncExportPayload ? __('EMD export JSON payload') : $label)
                ->rows($key === SettingKey::SyncExportPayload ? 16 : 8)->columnSpanFull()
                ->rules(SettingRules::for($key))
                ->disabled(fn (): bool => ! $this->canManageSync())
                ->dehydrated(fn (): bool => $this->canManageSync())
                ->helperText($key === SettingKey::SyncExportPayload
                    ? __('Complete JSON request body. Inside a text value, {{ now }} is replaced with the time of sending in UTC (e.g. 2026-09-28T12:03:15.000Z), and {{ ids }} with the selected IDs of the ID list below, comma-separated (e.g. 3,12,40). Placeholders stay inside quotes, e.g. "ids": "{{ ids }}". Only staff who manage EMD sync can edit it.')
                    : __('JSON request body of the ID list query (EMD_SYNC_ID_LIST_URL), sent with the export\'s token; {{ now }} works here too. Only staff who manage EMD sync can edit it.'));
        }

        if (in_array($key, [SettingKey::SyncIdListIdPath, SettingKey::SyncIdListNamePath, SettingKey::SyncIdListStatusPath], true)) {
            return TextInput::make($name)->label($label)->maxLength(200)->required($key !== SettingKey::SyncIdListStatusPath)
                ->disabled(fn (): bool => ! $this->canManageSync())
                ->dehydrated(fn (): bool => $this->canManageSync())
                ->placeholder($key === SettingKey::SyncIdListStatusPath ? __('Empty: the HTTP status code') : null)
                ->helperText(match ($key) {
                    SettingKey::SyncIdListIdPath => __('Where the IDs are in the response, as a dotted path. A list is crossed by name (result.data.id reads data.id of every element of result) or with *. Default: result.data.id.'),
                    SettingKey::SyncIdListNamePath => __('Where the names are, read the same way; an entry without a name shows its ID. Default: result.data.name.'),
                    default => __('Where the status code is in the response body, e.g. statusCode. Empty: the HTTP status code of the response. The list is accepted only with a 2xx status.'),
                });
        }

        return match ($key->type()) {
            SettingType::Boolean => Toggle::make($name)->label($label)->inline(false)
                ->helperText(match ($key) {
                    SettingKey::PinUniqueRequired => __('Enabled: newly assigned PINs must be unique across clients, including closed accounts. Disabled: clients may share a PIN. Changing this setting does not alter existing PINs.'),
                    SettingKey::PinClientChangesEnabled => __('Allows clients to set, replace and remove their own PIN. Disabled: only authorized staff can manage it. PIN uniqueness is controlled separately.'),
                    SettingKey::SyncUniqueDomains => __('Enabled: a domain may appear in only one list. Disabled: shared domains are classified as staff. Domains are saved in lowercase; unlisted domains are skipped.'),
                    SettingKey::ClientsListRelevantOnly => __('The Clients list opens with the relevant accounts only: portal access, closed, or any history (PIN, answers, calls, identifications, links, notes). EMD-only accounts stay reachable through the list filter and the search.'),
                    SettingKey::ClientsUnlinkedBadge => __('Shows a red counter next to the Clients menu item with the number of explicit-premium clients that have no sponsor link yet. The tab on the list keeps its counter either way.'),
                    SettingKey::PortalPhoneVerificationEnabled => __('The client confirms a number added on the portal with a code sent by SMS; needs a working SMS gateway. Off: the helpdesk confirms the number on the client page, or an identified call does when that switch is on. Unverified numbers rank below verified ones when a caller is matched.'),
                    SettingKey::VerifyPhoneOnIdentifiedCall => __('A client identified on a call (by PIN, code, question and answer or manually) proves the number the call came from: their unverified copy of that number becomes verified. Off: numbers are verified by SMS code or by the helpdesk only.'),
                    SettingKey::ClientLoginMagicLinkPreviewInPanel => __('On: after "Send a login link" the panel notification also shows the link itself, so a tester can sign in without the mailbox. Anyone who can send links can then sign in as the client: keep it off in production; the system status page warns while it is on. Debug mode shows the link regardless.'),
                    SettingKey::ClientLoginOtpSmsEmailFallbackEnabled => __('On: when the SMS code did not arrive after the second send, the login page offers to e-mail a login link once. Works only while "Magic link enabled" is also on: with the e-mail link login off, this switch has no effect and the page says to contact support, as when the switch is off.'),
                    SettingKey::UserLoginRememberEnabled, SettingKey::ClientLoginRememberEnabled => __('On: a sign-in through single sign-on (and, for staff, the "Remember me" box of the password form) keeps the browser signed in for the days set below, beyond the session limit. Off: no remember-me cookie. The e-mail link and the SMS code never remember.'),
                    SettingKey::PortalSharedNumberNotice => __('On: the portal tells the client when one of their numbers is also on file for another client, so the phone menu cannot recognise them from it (the other client is never named). Off: the portal says nothing about it.'),
                    default => null,
                }),
            SettingType::Integer => TextInput::make($name)->label($label)->numeric()->required()
                ->minValue(match ($key) {
                    SettingKey::PinMinLength, SettingKey::PinMaxLength => PinService::MIN_ALLOWED_LENGTH,
                    SettingKey::MobileOtpLength, SettingKey::IvrCodeLength => MobileOtpService::MIN_LENGTH,
                    SettingKey::AgentAttemptsPerHour, SettingKey::QaMinAcceptedToPass, SettingKey::QaMaxQuestionsPerSession, SettingKey::QaMinAnsweredQuestionsRequired, SettingKey::QaMaxRejectedToFail, SettingKey::QaSessionTtlMinutes, SettingKey::MobileOtpTtlMinutes, SettingKey::ClientLoginMagicLinkTtlMinutes, SettingKey::ClientLoginOtpSmsTtlMinutes, SettingKey::PinMaxFailedAttempts, SettingKey::PinLockoutMinutes, SettingKey::UserLoginRememberDays, SettingKey::ClientLoginRememberDays, SettingKey::UserLoginSessionMaxHours, SettingKey::ClientLoginSessionMaxHours, SettingKey::UserLoginLockoutMaxAttempts, SettingKey::ClientLoginLockoutMaxAttempts, SettingKey::UserLoginLockoutMinutes, SettingKey::ClientLoginLockoutMinutes, SettingKey::SsoMobileTokenMaxAgeMinutes, SettingKey::ClientLoginOtpSmsMaxPerNumberPerMinute, SettingKey::ClientLoginOtpSmsMaxPerNumberPerHour => 1,
                    default => 0,
                })
                ->helperText(match ($key) {
                    SettingKey::ClientLoginMagicLinkTtlMinutes => __('In minutes; 720 = 12 hours.'),
                    SettingKey::ClientLoginOtpSmsMaxPerNumberPerMinute => __('Login codes sent to one phone number per minute; the login page offers the next send after this interval. Keeps a flood of requests from running up the SMS bill. Other text messages are not limited.'),
                    SettingKey::ClientLoginOtpSmsMaxPerNumberPerHour => __('Login codes sent to one phone number per hour; beyond it no code goes out until the hour has passed (the request is refused silently and audited). The login page offers one re-send, then the e-mailed link or support.'),
                    SettingKey::UserLoginRememberDays, SettingKey::ClientLoginRememberDays => __('How long a remembered sign-in lasts, in days.'),
                    SettingKey::UserLoginSessionMaxHours, SettingKey::ClientLoginSessionMaxHours => __('A browser session ends this many hours after sign-in, however active it is; the idle limit of the session applies as well.'),
                    SettingKey::UserLoginLockoutMaxAttempts => __('Wrong passwords in a row before the password login of the staff account is locked. Single sign-on stays usable.'),
                    SettingKey::ClientLoginLockoutMaxAttempts => __('Wrong SMS codes in a row before the SMS login of the client is locked. Single sign-on and the e-mail link stay usable.'),
                    SettingKey::UserLoginLockoutMinutes, SettingKey::ClientLoginLockoutMinutes => __('How long the lockout lasts, in minutes (120 = 2 hours). Staff with the user or client management permission can unlock it earlier on the account page.'),
                    SettingKey::SsoMobileTokenMaxAgeMinutes => __('The Microsoft ID token of the mobile app is accepted once, and only within this many minutes of its issue.'),
                    SettingKey::PinMinLength, SettingKey::PinMaxLength => __('Allowed PIN length when setting or replacing a PIN (:min–:max digits). Existing PINs remain valid. The phone menu checks the PIN only for callers recognised by a registered number, so a PIN needs a phone number.', ['min' => PinService::MIN_ALLOWED_LENGTH, 'max' => PinService::MAX_ALLOWED_LENGTH]),
                    SettingKey::IvrCodeLength => __('Length of new IVR codes requested by the mobile app backend (8–12 digits), independent of the portal one-time identification code.'),
                    SettingKey::MobileOtpLength => __('Digits of the one-time identification code; shown in pairs, the second digit of a pair is never zero.'),
                    SettingKey::AgentAttemptsPerHour => __('Failed code and PIN checks one agent may run in an hour; successful checks do not count.'),
                    SettingKey::SyncMinRowsRatioPercent => __('A run is refused when it reads fewer rows than this share of the previous successful run (0 = off). Protects against a truncated export closing accounts.'),
                    SettingKey::SyncExpectedIntervalHours => __('How often EMD sync is expected to run; the status page fails when no successful run happened in twice this time.'),
                    SettingKey::RetentionGeneralYears => __('Audit log, identification sessions, calls, outbound messages and EMD sync runs older than this are deleted for good.'),
                    SettingKey::RetentionShortLivedYears => __('Expired one-time codes and uploaded EMD files older than this are deleted.'),
                    SettingKey::CallsStaleAfterHours => __('A call whose end the call center never reported (lost event, PBX restart) is closed as missed after this many hours, so it does not stay on the dashboard forever.'),
                    default => null,
                }),
            SettingType::Image => FileUpload::make($name)->label($label)->image()->disk('public')->directory('branding')->visibility('public')
                // Without this the removed file only leaves the form state and
                // stays on the public disk for good.
                ->deleteUploadedFileUsing(fn (string $file) => Storage::disk('public')->delete($file))
                ->maxSize(4096)->imagePreviewHeight('120')->columnSpan(1),
            SettingType::Text => match ($key) {
                SettingKey::CallsDefaultTier => Select::make($name)->label($label)->required()->native(false)
                    ->options(collect(ClientTier::cases())->mapWithKeys(fn (ClientTier $t) => [$t->value => $t->label()])->all())
                    ->helperText(__('The level a call gets when its queue is not listed below and the caller is unknown. While only the premium call center is connected, leave it on premium.')),
                default => TextInput::make($name)->label($label)->maxLength(500)->helperText(match ($key) {
                    SettingKey::SyncCsvEncoding => __('Character set of uploaded CSV files, e.g. UTF-8, Windows-1250 or ISO-8859-2.'),
                    default => null,
                }),
            },
            SettingType::Color => ColorPicker::make($name)->label($label)->required()->hex(),
            SettingType::LongText => Textarea::make($name)->label($label)->rows(5)->columnSpanFull()
                ->helperText($key === SettingKey::PortalFooterText ? __('Shown at the bottom of the client site; line breaks are kept.') : null),
            SettingType::Json => match ($key) {
                SettingKey::SyncColumnMapping => KeyValue::make($name)->label($label)->keyLabel(__('Field'))->valueLabel(__('Source column'))->columnSpanFull()
                    ->editableKeys(false)->addable(false)->deletable(false)->reorderable(false)
                    ->helperText(__('Use exact source headings. For phones, list all phone column headings separated by commas, e.g. Mobile phone, Office phone. Each cell may also contain several comma-separated numbers. All numbers are normalized and deduplicated. Empty mappings use the field name.')),
                SettingKey::ClientsDirectoryFields => KeyValue::make($name)->label($label)->keyLabel(__('EMD column'))->valueLabel(__('Label shown'))->columnSpanFull()
                    ->reorderable()
                    ->helperText(__('Columns of EMD that are not mapped to a fixed field arrive as "other columns" of the EMD record. List here the ones the helpdesk should see on the client page and list and be able to export; the key is the column header as EMD sends it, the value is the label shown.')),
                SettingKey::CallsQueueTiers => Repeater::make($name)->label($label)->columnSpanFull()
                    ->schema([
                        Select::make('queue')->label(__('Call queue'))->required()->searchable()->native(false)
                            ->options(fn () => static::knownQueues())
                            ->getSearchResultsUsing(fn (string $search) => array_filter(static::knownQueues(), fn (string $q) => str_contains(mb_strtolower($q), mb_strtolower($search))))
                            ->getOptionLabelUsing(fn ($value) => is_string($value) && $value !== '' ? $value : null)
                            ->createOptionForm([
                                TextInput::make('name')->label(__('Queue name as the call center sends it'))->required()->maxLength(100),
                            ])
                            ->createOptionUsing(fn (array $data): string => trim($data['name']))
                            ->helperText(__('Queues already seen in the call events are listed; a not-yet-seen queue can be added with its exact name.')),
                        Select::make('tier')->label(__('Level'))->required()->native(false)
                            ->options(collect(ClientTier::cases())->mapWithKeys(fn (ClientTier $t) => [$t->value => $t->label()])->all()),
                    ])
                    ->columns(2)
                    ->addActionLabel(__('Add a call queue'))
                    ->reorderable(false)
                    ->defaultItems(0)
                    ->helperText(__('One phone number is one call queue. Only needed once a second call center (the standard line) is connected; queues not listed here get the default level below.')),
                SettingKey::PackagesPremium, SettingKey::PackagesStandard => TagsInput::make($name)->label($label)
                    ->placeholder(__('Add a package name and press enter'))
                    ->helperText($key === SettingKey::PackagesPremium
                        ? __('Clients whose implicit or explicit package is listed here get the premium portal. Matching ignores case, accents and extra spaces.')
                        : __('Clients whose package is listed here get the standard portal. Clients in neither list cannot sign in.')),
                SettingKey::SyncUserDomains, SettingKey::SyncClientDomains => TagsInput::make($name)->label($label)
                    ->live()->formatStateUsing(fn ($state) => SettingRules::normalizeDomains((array) $state))
                    ->afterStateUpdated(fn (TagsInput $component, $state) => $component->state(SettingRules::normalizeDomains((array) $state)))
                    ->placeholder(__('Add a domain and press enter'))
                    ->helperText(match ($key) {
                        SettingKey::SyncUserDomains => __('Rows with these e-mail domains become staff users.'),
                        default => __('Rows with these e-mail domains become clients.'),
                    }),
                default => TagsInput::make($name)->label($label),
            },
        };
    }

    private function canManageSync(): bool
    {
        return auth()->user()?->can(Permission::SyncManage->value) ?? false;
    }

    /**
     * Queue names the call center has actually sent, plus the ones already mapped.
     *
     * @return array<string, string>
     */
    public static function knownQueues(): array
    {
        $seen = Call::query()->whereNotNull('queue')->distinct()->orderBy('queue')->pluck('queue')->all();
        $mapped = array_keys(app(Settings::class)->array(SettingKey::CallsQueueTiers));
        $names = array_values(array_unique(array_filter(array_map(fn ($q) => trim((string) $q), [...$seen, ...$mapped]))));

        return array_combine($names, $names);
    }

    /**
     * Image settings that are about to point somewhere else; their previous
     * file has no other owner, so it would linger on the public disk.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function replacedImages(Settings $settings, array $values): array
    {
        $paths = [];

        foreach (SettingKey::cases() as $key) {
            if ($key->type() !== SettingType::Image || ! array_key_exists($key->value, $values)) {
                continue;
            }

            $previous = $settings->string($key);

            if (is_string($previous) && $previous !== '' && $previous !== $values[$key->value]) {
                $paths[] = $previous;
            }
        }

        return $paths;
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteImages(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function toFormValue(SettingKey $key, mixed $value): mixed
    {
        if ($key === SettingKey::SyncColumnMapping) {
            $stored = is_array($value) ? $value : [];

            return array_map(fn (string $field) => is_array($stored[$field] ?? null) ? implode(',', $stored[$field]) : (string) ($stored[$field] ?? ''), static::mappingFields());
        }

        if ($key === SettingKey::CallsQueueTiers && is_array($value)) {
            return collect($value)->map(fn ($tier, $queue) => ['queue' => (string) $queue, 'tier' => mb_strtolower(trim((string) $tier))])->values()->all();
        }

        return $value;
    }

    private function fromFormValue(SettingKey $key, mixed $value): mixed
    {
        if ($key === SettingKey::SyncColumnMapping) {
            $mapping = [];
            foreach (static::mappingFields() as $field) {
                $column = trim((string) (is_array($value) ? ($value[$field] ?? '') : ''));
                if ($column === '') {
                    continue;
                }
                $mapping[$field] = $field === 'phones' && str_contains($column, ',') ? array_values(array_filter(array_map('trim', explode(',', $column)))) : $column;
            }

            return $mapping;
        }

        if ($key === SettingKey::CallsQueueTiers) {
            return collect(is_array($value) ? $value : [])
                ->filter(fn ($row) => is_array($row) && filled($row['queue'] ?? null) && filled($row['tier'] ?? null))
                ->mapWithKeys(fn ($row) => [trim((string) $row['queue']) => (string) $row['tier']])
                ->all();
        }

        if (in_array($key->type(), [SettingType::Text, SettingType::LongText, SettingType::Image], true) && ($value === '' || $value === [])) {
            return null;
        }

        if ($key->type() === SettingType::Image && is_array($value)) {
            return array_values($value)[0] ?? null;
        }

        return $value;
    }
}
