<?php

namespace App\Filament\Admin\Pages;

use App\Auth\Permission;
use App\Clients\ClientLinks;
use App\Clients\PackageListImpact;
use App\Enums\ClientTier;
use App\Filament\Admin\Clusters\System;
use App\Identification\MobileOtpService;
use App\Identification\PinService;
use App\Models\Call;
use App\Settings\SettingKey;
use App\Settings\SettingRules;
use App\Settings\Settings;
use App\Settings\SettingType;
use App\Support\HuDate;
use App\Sync\IdList;
use App\Sync\RequestPayload;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

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

    /** Form field of the ID list selection; not a setting, stored by IdList. */
    public const ID_LIST_FIELD = 'id_list_selection';

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
        $values[self::ID_LIST_FIELD] = app(IdList::class)->selectedIds();

        $this->form->fill($values);
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
        $this->assertConsistent($state);
        $settings = app(Settings::class);
        $values = [];

        foreach (SettingKey::cases() as $key) {
            if ($key->requiresSyncManage() && ! $this->canManageSync()) {
                $values[$key->value] = $settings->get($key);

                continue;
            }
            $values[$key->value] = $this->fromFormValue($key, $state[static::fieldName($key)] ?? null);
        }

        $this->validateValues($values);
        $impact = $this->assertPackageListsUsable($values);
        $selection = $this->canManageSync() ? array_map('strval', array_values((array) ($state[self::ID_LIST_FIELD] ?? []))) : null;
        $this->assertIdSelectionUsable($values, $selection);

        if (! $this->canManageSync()) {
            $values = array_filter($values, fn (string $key): bool => ! SettingKey::from($key)->requiresSyncManage(), ARRAY_FILTER_USE_KEY);
        }
        $replaced = $this->replacedImages($settings, $values);
        $settings->setMany($values);
        $this->deleteImages($replaced);
        // Entitlement follows the lists at once; the links of sponsors whose
        // package left the premium list end now, not at the hourly sweep.
        $endedLinks = $impact['changed'] ? app(ClientLinks::class)->endLinksOfIneligibleSponsors() : 0;
        if ($selection !== null) {
            app(IdList::class)->select($selection);
        }
        foreach ([SettingKey::SyncUserDomains, SettingKey::SyncClientDomains] as $key) {
            $this->data[static::fieldName($key)] = $settings->array($key);
        }

        $saved = Notification::make()->title(__('Settings saved'))->success();
        if ($endedLinks > 0 || $impact['downgraded']['count'] > 0 || $impact['upgraded']['count'] > 0) {
            $saved->body(__('Package lists applied: :links link(s) ended, :down client(s) moved to the standard tier, :up to premium.', [
                'links' => $endedLinks, 'down' => $impact['downgraded']['count'], 'up' => $impact['upgraded']['count'],
            ]))->persistent();
        }
        $saved->send();
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
     * The package-list impact of the form as it stands (for the confirmation).
     *
     * @return array<string, mixed>
     */
    private function packageImpact(): array
    {
        return app(PackageListImpact::class)->estimate(
            (array) ($this->data[static::fieldName(SettingKey::PackagesPremium)] ?? []),
            (array) ($this->data[static::fieldName(SettingKey::PackagesStandard)] ?? []),
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
     * An export payload with `{{ ids }}` needs at least one selected ID on
     * the list, or every sync would stop before the export.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>|null  $selection  null when the user cannot change it
     */
    private function assertIdSelectionUsable(array $values, ?array $selection): void
    {
        $payload = $values[SettingKey::SyncExportPayload->value] ?? null;
        if ($selection === null || ! RequestPayload::uses(is_string($payload) ? $payload : null, 'ids')) {
            return;
        }

        if ($message = app(IdList::class)->emptySelectionMessage($selection)) {
            Notification::make()->title(__('Settings not saved'))->body($message)->danger()->send();

            throw ValidationException::withMessages(['data.'.self::ID_LIST_FIELD => $message]);
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
     * Saving asks for confirmation when it drops selected IDs: their records
     * stop arriving and their accounts close after the missed runs.
     */
    public function saveAction(): Action
    {
        $impact = app(PackageListImpact::class);

        return Action::make('save')->label(__('Save'))->keyBindings(['mod+s'])
            ->requiresConfirmation(fn (): bool => $this->idListDeselections() !== [] || $impact->needsConfirmation($this->packageImpact()))
            ->modalHeading(fn (): string => $this->idListDeselections() !== [] ? __('Stop sending these IDs?') : __('Apply the package list change?'))
            ->modalDescription(fn (): string => implode(' ', array_filter([
                $this->idListDeselections() !== [] ? __('These IDs will no longer be sent in the export: :list. Their EMD records stop arriving, and the linked accounts are closed after :runs missed syncs. Run a trial sync after saving to see the planned closures.', [
                    'list' => implode(', ', $this->idListDeselections()),
                    'runs' => app(Settings::class)->int(SettingKey::SyncMissedRunsBeforeClose),
                ]) : null,
                $impact->summary($this->packageImpact()),
            ])))
            ->modalSubmitActionLabel(__('Save'))
            ->action(fn () => $this->save());
    }

    /**
     * @return list<string>
     */
    private function idListDeselections(): array
    {
        if (! $this->canManageSync()) {
            return [];
        }

        return app(IdList::class)->wouldDeselect(array_values((array) ($this->data[self::ID_LIST_FIELD] ?? [])));
    }

    /**
     * The ID list on the EMD tab: the stored list as checkboxes in alphabetical
     * order with a live name filter, the fetch button and the last status.
     */
    private function idListSection(): Section
    {
        return Section::make(__('ID list'))->key('idList')
            ->description(__('The selected IDs still on the list fill {{ ids }} of the export payload, comma-separated. New IDs appear unselected. An ID left out (unticked or gone from the list) is no longer sent, so its records stop arriving and the linked accounts close after the configured number of missed syncs. A selected ID that leaves the list keeps its selection: when it returns, it is sent again without a new decision, and the accounts closed as missing reopen.'))
            ->headerActions([
                Action::make('fetchIdList')->label(__('Fetch the ID list from the API'))->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (): bool => $this->canManageSync())
                    ->authorize(fn (): bool => $this->canManageSync())
                    ->disabled(fn (): bool => ! app(IdList::class)->isConfigured())
                    ->tooltip(fn (): ?string => app(IdList::class)->isConfigured() ? null : __('EMD_SYNC_ID_LIST_URL is not configured.'))
                    ->action(fn () => $this->fetchIdList()),
            ])
            ->columnSpanFull()
            ->schema([
                CheckboxList::make(self::ID_LIST_FIELD)->label(__('IDs sent in the export'))
                    ->options(fn (): array => app(IdList::class)->options())
                    ->searchable()->searchPrompt(__('Filter by name'))->noSearchResultsMessage(__('No ID matches the filter.'))
                    ->bulkToggleable()->columns(2)
                    ->disabled(fn (): bool => ! $this->canManageSync())
                    ->dehydrated(fn (): bool => $this->canManageSync())
                    ->helperText(fn (): string => $this->idListStatus()),
            ]);
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

        if ($removed = $idList->selectedButRemoved()) {
            $parts[] = __('Selected but no longer on the list, so not sent; if they return, they are sent again automatically: :list.', ['list' => implode(', ', $removed)]);
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
                    ? __('Complete JSON request body. Inside a text value, {{ now }} is replaced with the time of sending in UTC (e.g. 2026-09-28T12:03:15.000Z), and {{ ids }} with the selected IDs of the ID list below, comma-separated (e.g. 3,12,40). Only staff who manage EMD sync can edit it.')
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
