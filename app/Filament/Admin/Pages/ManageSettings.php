<?php

namespace App\Filament\Admin\Pages;

use App\Auth\Permission;
use App\Auth\Role;
use App\Enums\ClientTier;
use App\Filament\Admin\Clusters\System;
use App\Identification\MobileOtpService;
use App\Identification\PinService;
use App\Models\Call;
use App\Settings\SettingKey;
use App\Settings\SettingRules;
use App\Settings\Settings;
use App\Settings\SettingType;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Every runtime business decision, grouped by the first segment of the
 * setting key. Fields are generated from the SettingKey enum.
 */
class ManageSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $cluster = System::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'settings';

    protected string $view = 'filament.admin.manage-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

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
        $settings = app(Settings::class);
        $values = [];

        foreach (SettingKey::cases() as $key) {
            $values[static::fieldName($key)] = $this->toFormValue($key, $settings->get($key));
        }

        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        $tabs = collect(SettingKey::cases())
            ->groupBy(fn (SettingKey $key) => $key->group())
            ->map(fn ($keys, string $group) => Tab::make($keys->first()->groupLabel())
                ->schema($keys->map(fn (SettingKey $key) => $this->field($key))->all())
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
        $this->assertConsistent($state);
        $settings = app(Settings::class);
        $values = [];

        foreach (SettingKey::cases() as $key) {
            if ($key === SettingKey::SyncExportPayload && ! $this->canEditExportPayload()) {
                $values[$key->value] = $settings->get($key);

                continue;
            }
            $values[$key->value] = $this->fromFormValue($key, $state[static::fieldName($key)] ?? null);
        }

        $this->validateValues($values);

        if (! $this->canEditExportPayload()) {
            unset($values[SettingKey::SyncExportPayload->value]);
        }
        $replaced = $this->replacedImages($settings, $values);
        $settings->setMany($values);
        $this->deleteImages($replaced);
        foreach ([SettingKey::SyncUserDomains, SettingKey::SyncClientDomains] as $key) {
            $this->data[static::fieldName($key)] = $settings->array($key);
        }

        Notification::make()->title(__('Settings saved'))->success()->send();
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
     * @return array<int, Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label(__('Save'))->submit('save')->keyBindings(['mod+s']),
        ];
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

        if ($key === SettingKey::SyncExportPayload) {
            return Textarea::make($name)->label(__('EMD export JSON payload'))
                ->rows(16)->columnSpanFull()
                ->rules(SettingRules::for($key))
                ->disabled(fn (): bool => ! $this->canEditExportPayload())
                ->dehydrated(fn (): bool => $this->canEditExportPayload())
                ->helperText(__('Complete JSON request body, including the static ID list. Only a SuperAdmin can edit it.'));
        }

        return match ($key->type()) {
            SettingType::Boolean => Toggle::make($name)->label($label)->inline(false)
                ->helperText(match ($key) {
                    SettingKey::PinUniqueRequired => __('Enabled: newly assigned PINs must be unique across clients, including closed accounts. Disabled: clients may share a PIN. Changing this setting does not alter existing PINs.'),
                    SettingKey::PinClientChangesEnabled => __('Allows clients to set, replace and remove their own PIN. Disabled: only authorized staff can manage it. PIN uniqueness is controlled separately.'),
                    SettingKey::SyncUniqueDomains => __('Enabled: a domain may appear in only one list. Disabled: shared domains are classified as staff. Domains are saved in lowercase; unlisted domains are skipped.'),
                    SettingKey::ClientsListRelevantOnly => __('The Clients list opens with the relevant accounts only: portal access, closed, or any history (PIN, answers, calls, identifications, links, notes). EMD-only accounts stay reachable through the list filter and the search.'),
                    SettingKey::ClientsUnlinkedBadge => __('Shows a red counter next to the Clients menu item with the number of explicit-premium clients that have no sponsor link yet. The tab on the list keeps its counter either way.'),
                    default => null,
                }),
            SettingType::Integer => TextInput::make($name)->label($label)->numeric()->required()
                ->minValue(match ($key) {
                    SettingKey::PinMinLength, SettingKey::PinMaxLength => PinService::MIN_ALLOWED_LENGTH,
                    SettingKey::MobileOtpLength, SettingKey::IvrCodeLength => MobileOtpService::MIN_LENGTH,
                    SettingKey::AgentAttemptsPerHour, SettingKey::QaMinAcceptedToPass, SettingKey::QaMaxQuestionsPerSession, SettingKey::QaMinAnsweredQuestionsRequired, SettingKey::QaMaxRejectedToFail, SettingKey::QaSessionTtlMinutes, SettingKey::MobileOtpTtlMinutes, SettingKey::ClientLoginMagicLinkTtlMinutes, SettingKey::ClientLoginOtpSmsTtlMinutes, SettingKey::PinMaxFailedAttempts, SettingKey::PinLockoutMinutes => 1,
                    default => 0,
                })
                ->helperText(match ($key) {
                    SettingKey::ClientLoginMagicLinkTtlMinutes => __('In minutes; 4320 = 3 days.'),
                    SettingKey::PinMinLength, SettingKey::PinMaxLength => __('Allowed PIN length when setting or replacing a PIN (:min–:max digits). Existing PINs remain valid.', ['min' => PinService::MIN_ALLOWED_LENGTH, 'max' => PinService::MAX_ALLOWED_LENGTH]),
                    SettingKey::IvrCodeLength => __('Length of new IVR codes requested by the mobile backend (8–12 digits), independent of the portal dictated code.'),
                    SettingKey::MobileOtpLength => __('Digits of the dictated code; shown in pairs, the second digit of a pair is never zero.'),
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
                ->helperText($key === SettingKey::PortalFooterText ? __('Shown at the bottom of the client site. Markdown is allowed.') : null),
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
                        ? __('Clients whose implicit or explicit package is listed here get the premium portal. Matching ignores case.')
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

    private function canEditExportPayload(): bool
    {
        return auth()->user()?->hasRole(Role::SuperAdmin->value) ?? false;
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
