<?php

namespace App\Filament\Admin\Pages;

use App\Auth\Mfa\StaffMfa;
use App\Auth\Oidc\EntraTenantOverview;
use App\Auth\Oidc\OidcProvider;
use App\Auth\Permission;
use App\Clients\ClientLinks;
use App\Clients\PackageListImpact;
use App\Enums\ClientTier;
use App\Enums\MfaMethod;
use App\Enums\PrincipalType;
use App\Filament\Actions\ExportTableAction;
use App\Filament\Admin\Clusters\System;
use App\Filament\Concerns\GuardsUnsavedChanges;
use App\Filament\Concerns\LimitsSearchInput;
use App\Identification\MobileOtpService;
use App\Identification\PinService;
use App\Models\Call;
use App\Models\SyncIdListItem;
use App\Settings\JsonOverview;
use App\Settings\SettingKey;
use App\Settings\SettingRules;
use App\Settings\Settings;
use App\Settings\SettingSection;
use App\Settings\SettingTab;
use App\Settings\SettingType;
use App\Sms\SmsDestinations;
use App\Support\DomainNameRule;
use App\Support\EmailAddressRule;
use App\Support\HuDate;
use App\Support\InputLimits;
use App\Support\PhoneNumberRule;
use App\Sync\IdList;
use App\Sync\RequestPayload;
use App\System\Installation;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Field;
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
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use RuntimeException;

/**
 * Every runtime business decision, grouped by the first segment of the
 * setting key. Fields are generated from the SettingKey enum.
 */
class ManageSettings extends Page implements HasTable
{
    use GuardsUnsavedChanges;
    use InteractsWithTable;
    use LimitsSearchInput;

    /** Entries of a package or domain list. */
    public const LIST_MAX_ITEMS = 200;

    /** Call queues mapped to a level, and the length of a queue name (the call center API takes the same). */
    public const QUEUE_TIERS_MAX_ITEMS = 100;

    public const QUEUE_NAME_MAX_LENGTH = 100;

    /** EMD columns shown on the client, and the length of their label. */
    public const DIRECTORY_FIELDS_MAX_ITEMS = 50;

    public const DIRECTORY_LABEL_MAX_LENGTH = 100;

    /** A dotted path or a parameter name of the EMD API, and the CSV encoding name. */
    public const PATH_MAX_LENGTH = 200;

    public const PARAM_MAX_LENGTH = 100;

    public const ENCODING_MAX_LENGTH = 40;

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

    /**
     * The consequences the save confirmation showed (null: none was needed).
     * The save goes through only if they still hold when it runs.
     */
    #[Locked]
    public ?string $shownConfirmation = null;

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
        $this->rememberData();
    }

    /**
     * A form value in the shape the settings store keeps it, so an untouched
     * field compares equal to what the page was filled with.
     */
    private function asStored(SettingKey $key, mixed $formValue): mixed
    {
        $value = $key->type()->cast($this->fromFormValue($key, $formValue));

        return match (true) {
            in_array($key, [SettingKey::SyncUserDomains, SettingKey::SyncClientDomains], true) => SettingRules::normalizeDomains((array) $value),
            $key === SettingKey::PhoneAllowedRegions => SmsDestinations::normalizeRegions((array) $value),
            default => $value,
        };
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
            // A field the form does not hand back (disabled, or not the
            // user's to change) keeps its stored value.
            if (($key->requiresSyncManage() && ! $this->canManageSync()) || ! array_key_exists(static::fieldName($key), $state)) {
                continue;
            }

            $value = $this->asStored($key, $state[static::fieldName($key)]);
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

    /**
     * Seven tabs by the administrator's questions, each made of blocks that
     * say where they act (SettingTab, SettingSection, 2026-10-23). A setting
     * that only matters under a switch sits under it and shows while the
     * switch is on; hidden, it is not sent and keeps its stored value.
     */
    public function form(Schema $schema): Schema
    {
        $tabs = array_map(fn (SettingTab $tab): Tab => Tab::make($tab->label())->schema([
            ...array_map(fn (SettingSection $section): Section => $this->section($section), $tab->sections()),
            ...($tab === SettingTab::Directory ? [$this->idListSection()] : []),
        ]), SettingTab::cases());

        return $schema
            ->components([Tabs::make('settings')->tabs($tabs)->persistTabInQueryString()->extraAttributes(['class' => 'hdid-settings-tabs'])])
            ->statePath('data');
    }

    private function section(SettingSection $section): Section
    {
        $components = [];

        foreach ($section->keys() as $key) {
            if ($key->dependsOn() !== null) {
                continue;
            }

            $components[] = $this->field($key);

            if ($dependents = $key->dependents()) {
                $components[] = Group::make(array_map(fn (SettingKey $dependent): Component => $this->field($dependent), $dependents))
                    ->visible(fn (Get $get): bool => (bool) $get(static::fieldName($key)))
                    ->extraAttributes(['class' => 'hdid-setting-dependents'])
                    ->columnSpanFull();
            }
        }

        $unused = $section->syncDriver() !== null && config('hdid.sync.driver') !== $section->syncDriver();

        return Section::make($section->label())
            ->description($section->where().($unused ? ' '.__('Not used: this installation reads EMD through another source (EMD_SYNC_DRIVER=:driver).', ['driver' => (string) config('hdid.sync.driver')]) : ''))
            ->schema($components)
            ->columns($section->columns())
            ->inlineLabel($section->columns() === 1)
            ->collapsible($unused)
            ->collapsed($unused);
    }

    /**
     * Every setting with where it is and the words it is found by, for the
     * search box on top of the page: the label, the help and the details,
     * the block and the tab, the key itself and the earlier label and tab.
     * The values of the settings are not searched.
     *
     * @return list<array{key: string, master: ?string, tab: string, section: string, label: string, text: string}>
     */
    public function searchIndex(): array
    {
        return array_map(function (SettingKey $key): array {
            $section = $key->section();

            return [
                'key' => $key->value,
                'master' => $key->dependsOn()?->value,
                'tab' => $section->tab()->label(),
                'section' => $section->label(),
                'label' => $key->label(),
                'text' => implode(' ', array_filter([
                    $key->label(), $section->label(), $section->tab()->label(), $key->help(), $key->details(), $key->unit(),
                    $key->dependsOn()?->label(), ...$key->searchTerms(), ...$this->envKeysOf($key),
                ])),
            ];
        }, SettingKey::cases());
    }

    /**
     * Saving always goes through the Save action, so a save that needs a
     * confirmation cannot skip it, however it is called.
     */
    public function save(): void
    {
        $this->mountAction('save');
    }

    /**
     * One save at a time: the checks across keys (premium-only needs a
     * premium package, the package lists must leave nobody without access)
     * hold for what is stored only if no other save lands between the check
     * and the write. Inside the lock the stored values are read afresh, and
     * the save stops when its consequences are no longer the ones confirmed.
     */
    private function persist(Action $action): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            Cache::lock(Installation::lockName('hdid:settings-save'), 60)->block(15, fn () => $this->persistLocked($action));
        } catch (LockTimeoutException) {
            Notification::make()->warning()->title(__('Another save of the settings is in progress; try again in a moment.'))->send();
            $action->halt();
        }
    }

    private function persistLocked(Action $action): void
    {
        app(Settings::class)->forgetLocal();

        $confirmation = $this->confirmationText();
        if ($confirmation !== $this->shownConfirmation) {
            $this->shownConfirmation = $confirmation;
            Notification::make()->warning()->persistent()
                ->title(__('What this save does has changed'))
                ->body(__('Another save changed the stored settings since this one was started. Check the consequences again, then save.'))
                ->send();
            $action->halt();
        }

        $state = $this->form->getState();
        $settings = app(Settings::class);
        // Only what was changed here is written; the rest stays as stored,
        // including what another admin saved since this page was opened.
        $changes = $this->pageChanges($state);
        $values = $this->valuesAfterSave($changes);

        $this->assertConsistent(collect($values)->mapWithKeys(fn (mixed $value, string $key) => [static::fieldName(SettingKey::from($key)) => $value])->all());
        $this->validateValues($values, $changes);
        $impact = $this->assertPackageListsUsable($values);
        $replaced = $this->replacedImages($settings, $changes);
        $settings->setMany($changes);
        $this->deleteImages($replaced);
        // Entitlement follows the lists at once; the links of sponsors whose
        // package left the premium list end now, not at the hourly sweep.
        // A closed sponsor's links end too, and the clients closed only with it reopen.
        $ended = $impact['changed'] ? app(ClientLinks::class)->sweepIneligibleSponsors() : ['links' => 0, 'reopened' => 0, 'kept_closed' => 0];
        // The page now shows what is stored, other admins' changes included.
        $this->fillFromSettings();

        $saved = Notification::make()->title(__('Settings saved'))->success();
        if ($ended['links'] > 0 || $ended['reopened'] > 0 || $impact['downgraded']['count'] > 0 || $impact['upgraded']['count'] > 0) {
            $body = __('Package lists applied: :links link(s) ended, :down client(s) moved to the standard tier, :up to premium.', [
                'links' => $ended['links'], 'down' => $impact['downgraded']['count'], 'up' => $impact['upgraded']['count'],
            ]);
            if ($ended['reopened'] > 0) {
                $body .= ' '.__(':n linked client(s), closed only because their sponsor was closed, reopened.', ['n' => $ended['reopened']]);
            }
            $saved->body($body)->persistent();
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
            (bool) ($values[SettingKey::PackagesPremiumOnly->value] ?? false),
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
            (bool) ($values[SettingKey::PackagesPremiumOnly->value] ?? false),
        );
    }

    /**
     * Whether this page switches premium-only mode on (it was off when loaded).
     */
    private function switchesPremiumOnlyOn(): bool
    {
        return ($this->pageChanges($this->data ?? [])[SettingKey::PackagesPremiumOnly->value] ?? false) === true;
    }

    /**
     * Who would stop being served, with the package lists as they would be saved.
     *
     * @return array{count: int, hidden: int, sample: list<string>}
     */
    private function premiumOnlyImpact(): array
    {
        $values = $this->valuesAfterSave($this->pageChanges($this->data ?? []));

        return app(PackageListImpact::class)->premiumOnlyImpact(
            (array) ($values[SettingKey::PackagesPremium->value] ?? []),
            (array) ($values[SettingKey::PackagesStandard->value] ?? []),
        );
    }

    /**
     * Range and format rules per key plus the cross-key rules; errors land
     * on the offending fields. The format and list limits (limitRules) apply
     * to what this page changes: an older stored value they would refuse (a
     * phone number the normaliser cannot read, a domain with an underscore)
     * keeps working until someone edits it. So do the field lengths
     * (maxLengthOf): a longer value stored before the limit does not block
     * saving another field.
     *
     * @param  array<string, mixed>  $values  keyed by SettingKey value
     * @param  array<string, mixed>  $changes  the keys changed on this page
     */
    private function validateValues(array $values, array $changes = []): void
    {
        $rules = [];
        $labels = [];
        foreach (SettingKey::cases() as $key) {
            $keyRules = SettingRules::for($key);

            if (array_key_exists($key->value, $changes)) {
                $keyRules = [...$keyRules, ...static::limitRules($key)];
            }

            if ($keyRules !== []) {
                $rules[str_replace('.', '\\.', $key->value)] = $keyRules;
                $labels[$key->value] = $key->section()->tab()->label().' › '.$key->label();
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
     * sponsors (a closed sponsor's links too, with the linked clients that
     * reopen) or which tier clients get.
     */
    public function saveAction(): Action
    {
        $needsConfirmation = fn (): bool => $this->needsConfirmation();

        return Action::make('save')->label(__('Save'))->keyBindings(['mod+s'])
            // A custom heading or description opens the modal on its own in Filament;
            // modal() keeps a plain save one click.
            ->requiresConfirmation($needsConfirmation)
            ->modal($needsConfirmation)
            ->mountUsing(function (): void {
                $this->shownConfirmation = $this->confirmationText();
            })
            ->modalHeading(fn (): string => $this->switchesPremiumOnlyOn() ? __('Serve premium clients only?') : __('Apply the package list change?'))
            ->modalDescription(fn (): string => (string) $this->shownConfirmation)
            ->modalSubmitActionLabel(__('Save'))
            ->action(fn (Action $action) => $this->persist($action))
            ->after(fn (array $arguments) => $this->followLeaveLink($arguments));
    }

    private function needsConfirmation(): bool
    {
        return $this->switchesPremiumOnlyOn() || app(PackageListImpact::class)->needsConfirmation($this->packageImpact());
    }

    /**
     * What saving the form as it stands would do, spelled out for the
     * confirmation; null when the save needs none.
     */
    private function confirmationText(): ?string
    {
        if (! $this->needsConfirmation()) {
            return null;
        }

        $impact = app(PackageListImpact::class);

        return trim(($this->switchesPremiumOnlyOn() ? $impact->premiumOnlySummary($this->premiumOnlyImpact()).' ' : '').$impact->summary($this->packageImpact()));
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

    /**
     * A setting's field with what every field shares: its help sentence,
     * the finer points behind the info icon, the default it differs from,
     * its unit, the installation status it depends on, and a marker the
     * search box scrolls to. While premium-only is switched on in the form,
     * the standard level's settings say what they do now: the package list
     * grants nothing, and the contacts are what a turned-away standard
     * client sees on the portal's login page (so they stay editable).
     */
    private function field(SettingKey $key): Component
    {
        $field = $this->baseField($key);

        if (! $field instanceof Field) {
            return $field;
        }

        $field->extraFieldWrapperAttributes(['data-setting' => $key->value], merge: true);

        // Tables and JSON bodies need the whole width: their label goes above them.
        if ($field instanceof KeyValue || $field instanceof Repeater || $key->type() === SettingType::LongText) {
            $field->inlineLabel(false);
        }

        // Lists and tables draw no maxlength attribute: their limits go into the help text.
        if (($help = static::helpWithLimits($key)) !== null) {
            $field->helperText($help);
        }

        if ($key->details() !== null) {
            $field->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: $key->details())->hintColor('gray');
        }

        if ($this->showsDefault($key)) {
            // The info icon shares the hint's colour: orange only while the default is shown.
            $field->hint(fn (mixed $state): ?string => $this->defaultHint($key, $state))
                ->hintColor(fn (mixed $state): string => $this->defaultHint($key, $state) === null ? 'gray' : 'warning');
        }

        if ($key->dependents() !== []) {
            $field->live();
        }

        if ($key->unit() !== null && $field instanceof TextInput) {
            $field->suffix($key->unit());
        }

        // The helper text lives in the same place under the field, so both go there together.
        if (($status = $this->installationStatus($key)) !== null) {
            $field->belowContent(array_values(array_filter([
                $key->help() === null ? null : Text::make($key->help()),
                Text::make($status['text'])->color($status['color'])->weight('medium'),
                $this->entraTenantList($key),
            ])));
        }

        $premiumOnly = fn (Get $get): bool => (bool) $get(static::fieldName(SettingKey::PackagesPremiumOnly));

        if (in_array($key, [SettingKey::SupportStandardEmail, SettingKey::SupportStandardPhone, SettingKey::SupportStandardHours], true)) {
            return $field->helperText(fn (Get $get): ?string => $premiumOnly($get)
                ? __('While only premium clients are served, a standard client turned away on the portal\'s login page sees this contact.')
                : null);
        }

        if ($key === SettingKey::PackagesStandard) {
            return $field->helperText(fn (Get $get): string => $premiumOnly($get)
                ? __('Grants nothing while only premium clients are served; it applies again once that is switched off.')
                : (string) static::helpWithLimits($key));
        }

        if ($key === SettingKey::PhoneAllowedRegions) {
            return $field->belowContent(fn (mixed $state): array => $this->acceptedCountriesNotes($key, $state));
        }

        return $field;
    }

    /**
     * Under the accepted countries: the help sentence, and, as the field
     * changes (before saving), the chosen countries whose numbering plan
     * does not tell every mobile number apart from a landline, by name.
     *
     * @return list<Text>
     */
    private function acceptedCountriesNotes(SettingKey $key, mixed $state): array
    {
        $notes = [Text::make((string) $key->help())];
        $mixed = array_values(array_intersect(SmsDestinations::normalizeRegions((array) $state), SmsDestinations::regionsWithoutSeparateMobileNumbers()));

        if ($mixed !== []) {
            $notes[] = Text::make(__('In these countries the numbering plan does not tell every mobile number apart from a landline, so a text message may go to their landline numbers too: :countries.', [
                'countries' => implode(', ', SmsDestinations::regionNames($mixed)),
            ]))->color('warning')->weight('medium');
        }

        return $notes;
    }

    /**
     * Settings with a meaningful default to compare with: switches, numbers,
     * colours and short texts that have one.
     */
    private function showsDefault(SettingKey $key): bool
    {
        return in_array($key->type(), [SettingType::Boolean, SettingType::Integer, SettingType::Color], true)
            || ($key->type() === SettingType::Text && $key->default() !== null);
    }

    /**
     * "Default: 30 minutes" next to a setting whose value differs from the
     * default; nothing while it holds the default.
     */
    private function defaultHint(SettingKey $key, mixed $state): ?string
    {
        $default = $key->type()->cast($key->default());
        $value = $this->asStored($key, $state);

        if ($key->type() === SettingType::Color ? strtolower((string) $value) === strtolower((string) $default) : $value === $default) {
            return null;
        }

        $shown = match (true) {
            is_bool($default) => $default ? __('on') : __('off'),
            $key === SettingKey::CallsDefaultTier => ClientTier::tryFrom((string) $default)?->label() ?? (string) $default,
            default => trim($default.' '.($key->unit() ?? '')),
        };

        return __('Default: :value', ['value' => $shown]);
    }

    /**
     * What the installation provides for a switch that needs it: the SSO
     * provider's keys, the SMS gateway. Null for other settings.
     *
     * @return array{text: string, color: string}|null
     */
    private function installationStatus(SettingKey $key): ?array
    {
        if (($sso = $this->ssoOf($key)) !== null) {
            [$provider, $principal] = $sso;

            return $provider->configFor($principal)->isConfigured()
                ? ['text' => __('Set up in the installation.'), 'color' => 'success']
                : ['text' => __('Missing in the installation: :keys. It can be switched on once these are filled in the .env file and the services are restarted.', ['keys' => implode(', ', $provider->envKeys($principal))]), 'color' => 'danger'];
        }

        if (in_array($key, [SettingKey::ClientLoginOtpSmsEnabled, SettingKey::PortalPhoneVerificationEnabled], true)) {
            $driver = (string) config('hdid.sms.driver');

            return $driver === 'log'
                ? ['text' => __('No SMS gateway: SMS_DRIVER=log writes the text messages to the application log only.'), 'color' => 'warning']
                : ['text' => __('SMS gateway connected (SMS_DRIVER=:driver).', ['driver' => $driver]), 'color' => 'success'];
        }

        return null;
    }

    /**
     * Under the client Entra switch, next to its installation status: the
     * tenants the installation allows (CLIENT_ENTRA_TENANTS) and what the EMD
     * sync makes of each domain they may present. Read-only, shown with the
     * switch off as well; EntraTenantOverview decides, this only draws it.
     */
    private function entraTenantList(SettingKey $key): ?Component
    {
        if ($key !== OidcProvider::Entra->enabledSetting(PrincipalType::Client)) {
            return null;
        }

        return SchemaView::make('filament.admin.settings.entra-tenants')
            ->viewData(fn (): array => ['overview' => app(EntraTenantOverview::class)->clients()]);
    }

    /**
     * The SSO provider and account type a switch turns on, or null.
     *
     * @return array{0: OidcProvider, 1: PrincipalType}|null
     */
    private function ssoOf(SettingKey $key): ?array
    {
        foreach (PrincipalType::cases() as $principal) {
            foreach (OidcProvider::cases() as $provider) {
                if ($provider->enabledSetting($principal) === $key) {
                    return [$provider, $principal];
                }
            }
        }

        return null;
    }

    /**
     * The .env keys a setting depends on, for the search.
     *
     * @return list<string>
     */
    private function envKeysOf(SettingKey $key): array
    {
        $sso = $this->ssoOf($key);

        return match (true) {
            // The tenant list is searched for too: the status tile sends the operator here by its name.
            $sso !== null && $sso[0] === OidcProvider::Entra && $sso[1] === PrincipalType::Client => [...$sso[0]->envKeys($sso[1]), 'CLIENT_ENTRA_TENANTS'],
            $sso !== null => $sso[0]->envKeys($sso[1]),
            in_array($key, [SettingKey::ClientLoginOtpSmsEnabled, SettingKey::PortalPhoneVerificationEnabled], true) => ['SMS_DRIVER'],
            $key->section()->syncDriver() !== null => ['EMD_SYNC_DRIVER'],
            default => [],
        };
    }

    /**
     * "Readable view" of a JSON request body: the text as it stands in the
     * field (saved or not), formatted, coloured and with its placeholders
     * marked. It only shows; the field and its saving are unchanged.
     */
    private function jsonOverviewAction(SettingKey $key): Action
    {
        return Action::make(lcfirst($key->name).'Overview')
            ->label(__('Readable view'))
            ->icon(Heroicon::OutlinedEye)
            ->link()
            ->modalHeading($key->label())
            ->modalDescription(__('The text as it stands in the field now, saved or not; the field and its saving are unchanged.'))
            ->modalContent(function () use ($key): View {
                $text = (string) ($this->data[static::fieldName($key)] ?? '');

                return view('filament.admin.settings.json-overview', [
                    'overview' => JsonOverview::of(
                        $text,
                        $key === SettingKey::SyncExportPayload ? RequestPayload::EXPORT_PLACEHOLDERS : RequestPayload::ID_LIST_PLACEHOLDERS,
                        $this->payloadSaveError($key, $text),
                    ),
                    'placeholders' => $this->placeholderNotes($key),
                ]);
            })
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }

    /**
     * What saving this text would say against it (the same rules as Save),
     * or null when it would be accepted.
     */
    private function payloadSaveError(SettingKey $key, string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        $validator = Validator::make([$key->value => $text], [str_replace('.', '\\.', $key->value) => SettingRules::for($key)]);

        if ($validator->fails()) {
            return $validator->errors()->first();
        }

        return SettingRules::payloadErrors([$key->value => $text])[$key->value] ?? null;
    }

    /**
     * What each placeholder of a request body stands for now.
     *
     * @return array<string, string>
     */
    private function placeholderNotes(SettingKey $key): array
    {
        $notes = ['now' => __('the time of sending in UTC, e.g. :example', ['example' => now()->utc()->format('Y-m-d\TH:i:s.000\Z')])];

        if ($key === SettingKey::SyncExportPayload) {
            $selected = app(IdList::class)->selectedIds();
            $notes['ids'] = $selected === []
                ? __('the selected IDs of the ID list; none is selected now, so the sync does not run')
                : __(':n selected ID(s) of the ID list, e.g. :sample', ['n' => count($selected), 'sample' => implode(',', array_slice($selected, 0, 5)).(count($selected) > 5 ? ',…' : '')]);
        }

        return $notes;
    }

    private function baseField(SettingKey $key): Component
    {
        $name = static::fieldName($key);
        $label = $key->label();

        if ($key === SettingKey::PackagesPremiumOnly) {
            return Toggle::make($name)->label($label)->inline(false)->live();
        }

        if ($key === SettingKey::SyncExportPayload || $key === SettingKey::SyncIdListPayload) {
            return $this->limitLength(Textarea::make($name)->label($label)
                ->rows($key === SettingKey::SyncExportPayload ? 16 : 8)
                ->extraInputAttributes(['class' => 'font-mono text-sm']), $key)
                ->rules(SettingRules::for($key))
                ->disabled(fn (): bool => ! $this->canManageSync())
                ->dehydrated(fn (): bool => $this->canManageSync())
                ->hintAction($this->jsonOverviewAction($key));
        }

        if (in_array($key, [SettingKey::SyncIdListIdPath, SettingKey::SyncIdListNamePath, SettingKey::SyncIdListStatusPath], true)) {
            return $this->limitLength(TextInput::make($name)->label($label), $key)->required($key !== SettingKey::SyncIdListStatusPath)
                ->disabled(fn (): bool => ! $this->canManageSync())
                ->dehydrated(fn (): bool => $this->canManageSync())
                ->placeholder($key === SettingKey::SyncIdListStatusPath ? __('Empty: the HTTP status code') : null);
        }

        if (($sso = $this->ssoOf($key)) !== null) {
            [$provider, $principal] = $sso;

            // Off and not set up: it cannot be switched on (the status line says
            // what is missing). On but no longer set up: it can still be switched off.
            return Toggle::make($name)->label($label)->inline(false)
                ->disabled(fn (mixed $state): bool => ! $state && ! $provider->configFor($principal)->isConfigured());
        }

        return match ($key->type()) {
            SettingType::Boolean => Toggle::make($name)->label($label)->inline(false),
            SettingType::Integer => TextInput::make($name)->label($label)->numeric()->required()
                ->minValue(match ($key) {
                    SettingKey::PinMinLength, SettingKey::PinMaxLength => PinService::MIN_ALLOWED_LENGTH,
                    SettingKey::MobileOtpLength, SettingKey::IvrCodeLength => MobileOtpService::MIN_LENGTH,
                    SettingKey::AgentAttemptsPerHour, SettingKey::QaMinAcceptedToPass, SettingKey::QaMaxQuestionsPerSession, SettingKey::QaMinAnsweredQuestionsRequired, SettingKey::QaMaxRejectedToFail, SettingKey::QaSessionTtlMinutes, SettingKey::QaMaxUnsuccessfulSessions, SettingKey::QaUnsuccessfulWindowHours, SettingKey::MobileOtpTtlMinutes, SettingKey::ClientLoginMagicLinkTtlMinutes, SettingKey::ClientLoginOtpSmsTtlMinutes, SettingKey::PinMaxFailedAttempts, SettingKey::PinLockoutMinutes, SettingKey::UserLoginRememberDays, SettingKey::ClientLoginRememberDays, SettingKey::UserLoginSessionMaxHours, SettingKey::ClientLoginSessionMaxHours, SettingKey::UserLoginLockoutMaxAttempts, SettingKey::ClientLoginLockoutMaxAttempts, SettingKey::UserLoginLockoutMinutes, SettingKey::ClientLoginLockoutMinutes, SettingKey::SsoMobileTokenMaxAgeMinutes, SettingKey::ClientLoginOtpSmsMaxPerNumberPerMinute, SettingKey::ClientLoginOtpSmsMaxPerNumberPerHour, SettingKey::ClientLoginOtpSmsMaxPerNumberPerDay, SettingKey::ClientLoginOtpSmsMaxPerHour, SettingKey::ClientLoginOtpSmsMaxPerDay => 1,
                    default => 0,
                }),
            // A removed or replaced image is deleted from the disk once the change
            // is saved (replacedImages), never while the form is still open: a
            // discarded change would otherwise point to a missing file. Only the
            // image this page was opened with (or the one stored now) may stand in
            // the field as a path: a path the browser made up would be stored and
            // deleted from the public disk by the next image change.
            SettingType::Image => FileUpload::make($name)->label($label)->image()->disk('public')->directory('branding')->visibility('public')
                ->preventFilePathTampering(allowFilePathUsing: fn (string $file): bool => in_array($file, [$this->loaded[$key->value] ?? null, app(Settings::class)->string($key)], true))
                ->validationMessages(['tampered' => __('The file has to be uploaded here; a file path cannot be given.')])
                ->maxSize(4096)->imagePreviewHeight('120')->columnSpan(1),
            SettingType::Text => match ($key) {
                SettingKey::CallsDefaultTier => Select::make($name)->label($label)->required()->native(false)
                    ->options(collect(ClientTier::cases())->mapWithKeys(fn (ClientTier $t) => [$t->value => $t->label()])->all()),
                default => $this->limitLength(TextInput::make($name)->label($label), $key),
            },
            SettingType::Color => ColorPicker::make($name)->label($label)->required()->hex(),
            SettingType::LongText => $this->limitLength(Textarea::make($name)->label($label)->rows(5), $key),
            SettingType::Json => match ($key) {
                SettingKey::SyncColumnMapping => KeyValue::make($name)->label($label)->keyLabel(__('Field'))->valueLabel(__('Source column'))
                    ->editableKeys(false)->addable(false)->deletable(false)->reorderable(false),
                SettingKey::UserLoginMfaMethods => CheckboxList::make($name)->label($label)
                    ->options(collect(MfaMethod::cases())->mapWithKeys(fn (MfaMethod $method): array => [$method->value => $method->label()])->all())
                    ->disableOptionWhen(fn (string $value): bool => $value === MfaMethod::Sms->value && ! StaffMfa::smsAvailable())
                    ->helperText(fn (): string => __('The second factors staff may choose from: an authenticator app (RFC 6238 codes with a QR code, e.g. Google Authenticator), an SMS code to a mobile number of their EMD record, an e-mail code to their account address.')
                        .(StaffMfa::smsAvailable() ? '' : ' '.__('SMS is not available: no SMS gateway is connected (SMS_DRIVER=log).'))),
                SettingKey::ClientsDirectoryFields => KeyValue::make($name)->label($label)->keyLabel(__('EMD column'))->valueLabel(__('Label shown'))
                    ->reorderable(),
                SettingKey::CallsQueueTiers => Repeater::make($name)->label($label)
                    ->schema([
                        Select::make('queue')->label(__('Call queue'))->required()->searchable()->native(false)
                            ->options(fn () => static::knownQueues())
                            ->getSearchResultsUsing(fn (string $search) => array_filter(static::knownQueues(), fn (string $q) => str_contains(mb_strtolower($q), mb_strtolower(InputLimits::search($search)))))
                            ->getOptionLabelUsing(fn ($value) => is_string($value) && $value !== '' ? $value : null)
                            ->createOptionForm([
                                TextInput::make('name')->label(__('Queue name as the call center sends it'))->required()->maxLength(self::QUEUE_NAME_MAX_LENGTH),
                            ])
                            ->createOptionUsing(fn (array $data): string => trim($data['name']))
                            ->helperText(__('Queues already seen in the call events are listed; a not-yet-seen queue can be added with its exact name.')),
                        Select::make('tier')->label(__('Level'))->required()->native(false)
                            ->options(collect(ClientTier::cases())->mapWithKeys(fn (ClientTier $t) => [$t->value => $t->label()])->all()),
                    ])
                    ->columns(2)
                    ->addActionLabel(__('Add a call queue'))
                    ->reorderable(false)
                    ->defaultItems(0),
                // Live: the note below names the chosen countries where a text message may reach a landline.
                SettingKey::PhoneAllowedRegions => Select::make($name)->label($label)->multiple()->searchable()->native(false)->required()->live()
                    ->options(fn (): array => SmsDestinations::regionOptions()),
                SettingKey::PackagesPremium, SettingKey::PackagesStandard => TagsInput::make($name)->label($label)
                    ->placeholder(__('Add a package name and press enter')),
                SettingKey::SyncUserDomains, SettingKey::SyncClientDomains => TagsInput::make($name)->label($label)
                    ->live()->formatStateUsing(fn ($state) => SettingRules::normalizeDomains((array) $state))
                    ->afterStateUpdated(fn (TagsInput $component, $state) => $component->state(SettingRules::normalizeDomains((array) $state)))
                    ->placeholder(__('Add a domain and press enter')),
                default => TagsInput::make($name)->label($label),
            },
        };
    }

    /**
     * The length limit of a typed text field: the browser keeps new typing
     * within it (maxlength), and the save checks it on the changed values
     * only (limitRules in validateValues). A value stored before the limit
     * that is longer keeps working and does not block saving another field;
     * the field says so until someone shortens it.
     *
     * @template T of TextInput|Textarea
     *
     * @param  T  $field
     * @return T
     */
    private function limitLength(TextInput|Textarea $field, SettingKey $key): TextInput|Textarea
    {
        $max = static::maxLengthOf($key);

        if ($max === null) {
            return $field;
        }

        return $field->extraInputAttributes(['maxlength' => $max], merge: true)
            ->hint(fn (): ?string => mb_strlen((string) ($this->loaded[$key->value] ?? '')) > $max
                ? __('The stored value is longer than the :max characters allowed now; it keeps working, and a change has to fit.', ['max' => $max])
                : null)
            ->hintColor('warning');
    }

    /**
     * The longest value a typed text setting takes: the field shows it
     * (maxlength) and the save enforces the same number (limitRules). Null
     * for settings that are not typed text (switches, numbers, lists,
     * choices, colours, images).
     */
    public static function maxLengthOf(SettingKey $key): ?int
    {
        return match ($key) {
            SettingKey::BrandingAppName => InputLimits::APP_NAME,
            SettingKey::PremiumBadgeLabel => InputLimits::BADGE,
            SettingKey::SupportStandardEmail, SettingKey::SupportPremiumEmail => EmailAddressRule::MAX_LENGTH,
            SettingKey::SupportStandardPhone, SettingKey::SupportPremiumPhone => PhoneNumberRule::RAW_MAX_LENGTH,
            SettingKey::PortalPrivacyUrl, SettingKey::PortalTermsUrl, SettingKey::PortalImprintUrl => InputLimits::URL,
            SettingKey::SyncApiDataPath, SettingKey::SyncIdListIdPath, SettingKey::SyncIdListNamePath, SettingKey::SyncIdListStatusPath => self::PATH_MAX_LENGTH,
            SettingKey::SyncApiPageParam => self::PARAM_MAX_LENGTH,
            SettingKey::SyncCsvEncoding => self::ENCODING_MAX_LENGTH,
            SettingKey::PortalFooterText => InputLimits::FOOTER,
            SettingKey::SyncExportPayload, SettingKey::SyncIdListPayload => InputLimits::JSON_CHARS,
            SettingKey::CallsDefaultTier => null,
            default => in_array($key->type(), [SettingType::Text, SettingType::LongText], true) ? InputLimits::NAME : null,
        };
    }

    /**
     * Length and format rules of a value typed on this page, on top of
     * SettingRules::for(): the standard e-mail, phone and URL forms, the
     * EMD API names, #RRGGBB colours, and the size of lists and tables.
     *
     * @return list<mixed>
     */
    public static function limitRules(SettingKey $key): array
    {
        $max = static::maxLengthOf($key);
        $string = $max === null ? [] : ['nullable', 'string', 'max:'.$max];

        return match (true) {
            in_array($key, [SettingKey::SupportStandardEmail, SettingKey::SupportPremiumEmail], true) => [...$string, new EmailAddressRule],
            in_array($key, [SettingKey::SupportStandardPhone, SettingKey::SupportPremiumPhone], true) => [...$string, new PhoneNumberRule],
            in_array($key, [SettingKey::PortalPrivacyUrl, SettingKey::PortalTermsUrl, SettingKey::PortalImprintUrl], true) => [...$string, 'url:http,https'],
            $key === SettingKey::SyncApiDataPath => [...$string, 'regex:/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)*$/'],
            $key === SettingKey::SyncApiPageParam => [...$string, 'regex:/^[A-Za-z0-9_\-.\[\]]+$/'],
            $key->type() === SettingType::Color => ['nullable', 'string', 'regex:/^#[0-9a-f]{6}$/i'],
            in_array($key, [SettingKey::PackagesPremium, SettingKey::PackagesStandard], true) => [self::listRule(self::LIST_MAX_ITEMS, InputLimits::NAME)],
            in_array($key, [SettingKey::SyncUserDomains, SettingKey::SyncClientDomains], true) => [self::listRule(self::LIST_MAX_ITEMS, DomainNameRule::MAX_LENGTH, DomainNameRule::problem(...))],
            $key === SettingKey::CallsQueueTiers => [self::mapRule(self::QUEUE_TIERS_MAX_ITEMS, self::QUEUE_NAME_MAX_LENGTH, self::QUEUE_NAME_MAX_LENGTH, array_map(fn (ClientTier $tier): string => $tier->value, ClientTier::cases()))],
            $key === SettingKey::ClientsDirectoryFields => [self::mapRule(self::DIRECTORY_FIELDS_MAX_ITEMS, InputLimits::NAME, self::DIRECTORY_LABEL_MAX_LENGTH)],
            $key === SettingKey::SyncColumnMapping => [self::mapRule(count(static::mappingFields()), InputLimits::NAME, InputLimits::NAME)],
            default => $string,
        };
    }

    /**
     * The setting's help sentence with the limits of a list or table (their
     * fields draw no maxlength attribute), or null when there is neither.
     */
    public static function helpWithLimits(SettingKey $key): ?string
    {
        $limits = match (true) {
            in_array($key, [SettingKey::PackagesPremium, SettingKey::PackagesStandard], true) => __('At most :items names, each at most :max characters.', ['items' => self::LIST_MAX_ITEMS, 'max' => InputLimits::NAME]),
            in_array($key, [SettingKey::SyncUserDomains, SettingKey::SyncClientDomains], true) => __('At most :items domains, each a domain name of at most :max characters.', ['items' => self::LIST_MAX_ITEMS, 'max' => DomainNameRule::MAX_LENGTH]),
            $key === SettingKey::CallsQueueTiers => __('At most :items call queues, each name at most :max characters.', ['items' => self::QUEUE_TIERS_MAX_ITEMS, 'max' => self::QUEUE_NAME_MAX_LENGTH]),
            $key === SettingKey::ClientsDirectoryFields => __('At most :items columns; a column name at most :column, a label at most :label characters.', ['items' => self::DIRECTORY_FIELDS_MAX_ITEMS, 'column' => InputLimits::NAME, 'label' => self::DIRECTORY_LABEL_MAX_LENGTH]),
            $key === SettingKey::SyncColumnMapping => __('A source column name may be at most :max characters long.', ['max' => InputLimits::NAME]),
            default => null,
        };

        $help = array_values(array_filter([$key->help(), $limits]));

        return $help === [] ? null : implode(' ', $help);
    }

    /**
     * A list setting: at most $maxItems entries of at most $maxLength
     * characters, each also passing $problem (a translated reason or null).
     *
     * @param  (callable(string): ?string)|null  $problem
     */
    private static function listRule(int $maxItems, int $maxLength, ?callable $problem = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($maxItems, $maxLength, $problem): void {
            if ($value === null || $value === []) {
                return;
            }

            if (! is_array($value) || count($value) > $maxItems) {
                $fail(__('At most :items entries.', ['items' => $maxItems]));

                return;
            }

            foreach ($value as $item) {
                if (! is_string($item) || mb_strlen($item) > $maxLength) {
                    $fail(__('Each entry may be at most :max characters long.', ['max' => $maxLength]));

                    return;
                }

                if ($problem !== null && ($reason = $problem($item)) !== null) {
                    $fail(__('":entry": :reason', ['entry' => Str::limit($item, 60), 'reason' => $reason]));

                    return;
                }
            }
        };
    }

    /**
     * A table setting (key => value): at most $maxItems rows, keys of at
     * most $keyMax characters, values (or each value of a list) of at most
     * $valueMax, and only $allowed values when given.
     *
     * @param  list<string>|null  $allowed
     */
    private static function mapRule(int $maxItems, int $keyMax, int $valueMax, ?array $allowed = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($maxItems, $keyMax, $valueMax, $allowed): void {
            if ($value === null || $value === []) {
                return;
            }

            if (! is_array($value) || count($value) > $maxItems) {
                $fail(__('At most :items rows.', ['items' => $maxItems]));

                return;
            }

            foreach ($value as $name => $entry) {
                if (mb_strlen((string) $name) > $keyMax) {
                    $fail(__('A name in the table may be at most :max characters long.', ['max' => $keyMax]));

                    return;
                }

                foreach (is_array($entry) ? $entry : [$entry] as $part) {
                    if (! is_scalar($part) && $part !== null) {
                        $fail(__('A value in the table is not text.'));

                        return;
                    }

                    if (mb_strlen((string) $part) > $valueMax) {
                        $fail(__('A value in the table may be at most :max characters long.', ['max' => $valueMax]));

                        return;
                    }

                    if ($allowed !== null && ! in_array((string) $part, $allowed, true)) {
                        $fail(__('":entry" is not one of the choices.', ['entry' => Str::limit((string) $part, 60)]));

                        return;
                    }
                }
            }
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
