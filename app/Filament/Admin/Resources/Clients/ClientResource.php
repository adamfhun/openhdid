<?php

namespace App\Filament\Admin\Resources\Clients;

use App\Auth\Permission;
use App\Clients\ClientExporter;
use App\Clients\ClientLinks;
use App\Clients\ClientTiers;
use App\Clients\PackageOverrides;
use App\Enums\ClientTier;
use App\Filament\Actions\SendMagicLinkAction;
use App\Filament\Actions\SetPinAction;
use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Pages\Identify;
use App\Filament\Admin\Resources\BaseResource;
use App\Filament\Admin\Resources\Clients\Pages\CreateClient;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Clients\RelationManagers\AnswersRelationManager;
use App\Filament\Admin\Resources\Clients\RelationManagers\IdSessionsRelationManager;
use App\Filament\Admin\Resources\Clients\RelationManagers\LinkedClientsRelationManager;
use App\Filament\Admin\Resources\Clients\RelationManagers\PhoneNumbersRelationManager;
use App\Identification\ClientAnswers;
use App\Identification\PinService;
use App\Models\Call;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Support\HuDate;
use App\Support\PhoneNormalizer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Clients as seen by everyone in the panel: agents look up and identify,
 * administrators additionally edit, close, reset PINs and send login links.
 */
class ClientResource extends BaseResource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Helpdesk;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('client');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Clients');
    }

    public static function getNavigationLabel(): string
    {
        return __('Clients');
    }

    public static function identifyAction(): Action
    {
        return Action::make('identify')
            ->label(__('Identify'))
            ->icon('heroicon-o-identification')
            ->color('success')
            ->button()
            ->visible(fn (Client $record) => auth()->user()?->can(Permission::IdentificationRun->value) && ! $record->isClosed())
            ->url(fn (Client $record) => Identify::getUrl(array_filter(['client' => $record->id, 'call' => static::currentCallId()])));
    }

    /**
     * The call the signed-in agent has taken and not finished, so that an
     * identification started from the client list still lands on the call.
     */
    public static function currentCallId(): ?string
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        return Call::query()
            ->where('agent_user_id', $user->id)
            ->ongoing()
            ->latest('arrived_at')
            ->value('id');
    }

    /**
     * @return array{email: ?string, phone: ?string, hours: ?string}|null
     */
    /**
     * Rare, time-boxed exception to the directory value; needs its own permission.
     * While an override is in force only "End package override" is offered.
     */
    public static function overrideAction(): Action
    {
        return Action::make('overridePackage')
            ->label(__('Override package'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('warning')
            ->visible(fn (Client $record) => (auth()->user()?->can(Permission::ClientsPackageOverride->value) ?? false) && ! $record->hasActivePackageOverride())
            ->disabled(fn (Client $record) => static::overrideBlocker($record) !== null)
            ->tooltip(fn (Client $record) => static::overrideBlocker($record))
            ->modalHeading(fn (Client $record) => __('Temporary package override for :name', ['name' => $record->name]))
            ->modalDescription(__('EMD stays the source of truth: the EMD sync keeps updating the real packages and never touches this override. The override can only raise the level, and it counts until its end date, until you end it, or until EMD carries the same value.'))
            ->schema(fn (Client $record) => [
                Select::make('package')->label(__('Package'))->required()->searchable()->native(false)
                    ->options(fn () => static::upgradeOptions($record))
                    ->default($record->package_override)
                    ->helperText(__('Only packages that raise the level EMD already gives are offered; an override can never take access away.')),
                Textarea::make('reason')->label(__('Reason'))->required()->maxLength(500)->rows(3)
                    ->default($record->package_override_reason)
                    ->placeholder(__('e.g. ticket number, who asked for it and why')),
                DatePicker::make('until')->label(__('Valid until'))->required()->native(false)->minDate(now()->addDay())
                    ->default($record->package_override_until ?? now()->addDays(30))
                    ->helperText(__('At the end of this day the override ends by itself and EMD value applies again.')),
            ])
            ->action(function (Client $record, array $data): void {
                try {
                    app(PackageOverrides::class)->set($record, $data['package'], $data['reason'], Carbon::parse($data['until'])->endOfDay(), auth()->user());
                    Notification::make()->title(__('Package override set'))->success()->send();
                } catch (ValidationException $e) {
                    Notification::make()->title($e->validator->errors()->first())->danger()->send();
                }
            });
    }

    /**
     * Why the override button is greyed out right now, or null when usable.
     * The button stays on the page so nobody has to guess where it went.
     */
    public static function overrideBlocker(Client $client): ?string
    {
        if ($client->isClosed()) {
            return __('The account is closed; reopen it first.');
        }

        if (static::upgradeOptions($client) === []) {
            return __('EMD already gives this client the highest level; an override can only raise the level.');
        }

        return null;
    }

    /**
     * Link a dependent (explicit-premium) client to a sponsor from its own
     * record, instead of hunting for the sponsor's "Linked clients" tab.
     */
    public static function linkToSponsorAction(): Action
    {
        $links = app(ClientLinks::class);
        $candidates = fn (): Builder => Client::query()->open()->whereIn(DB::raw('lower(trim(implicit_package))'), app(ClientTiers::class)->packages(ClientTier::Premium));

        return Action::make('linkToSponsor')
            ->label(__('Link to a sponsor'))
            ->icon('heroicon-o-link')
            ->color('danger')
            ->visible(fn (Client $record) => (auth()->user()?->can(Permission::ClientsManage->value) ?? false)
                && ! $record->isClosed() && $links->needsSponsor($record) && $record->sponsor() === null)
            ->disabled(fn () => ! $candidates()->exists())
            ->tooltip(fn () => $candidates()->exists() ? null : __('There is no open client with an implicit premium package to link to.'))
            ->modalHeading(fn (Client $record) => __('Link :name to a sponsor', ['name' => $record->name]))
            ->modalDescription(__('The sponsor is a client with an implicit premium package of their own. Closing the sponsor closes this account too; reopening reopens it.'))
            ->schema([
                Select::make('sponsor_id')->label(__('Sponsor'))->required()->searchable()->allowHtml()
                    ->getSearchResultsUsing(fn (string $search) => $candidates()
                        ->with(['externalRecord', 'phoneNumbers'])
                        ->where(fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
                            ->orWhereHas('externalRecord', fn (Builder $r) => $r->where('company', 'like', "%{$search}%")->orWhere('external_id', ctype_digit($search) ? (int) $search : -1)))
                        ->orderBy('name')->limit(20)->get()
                        ->mapWithKeys(fn (Client $c) => [$c->id => LinkedClientsRelationManager::optionLabel($c)])->all())
                    ->getOptionLabelUsing(fn ($value) => ($c = Client::query()->with(['externalRecord', 'phoneNumbers'])->find($value)) ? LinkedClientsRelationManager::optionLabel($c) : null)
                    ->helperText(__('Search by name, e-mail, company or external id; only open clients with an implicit premium package are offered.')),
            ])
            ->action(function (Client $record, array $data) use ($links): void {
                try {
                    $links->link(Client::query()->findOrFail($data['sponsor_id']), $record, auth()->user());
                    Notification::make()->title(__('Client linked'))->success()->send();
                } catch (ValidationException $e) {
                    Notification::make()->title($e->validator->errors()->first())->danger()->send();
                }
            });
    }

    public static function endOverrideAction(): Action
    {
        return Action::make('endOverride')
            ->label(__('End package override'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('EMD value applies again immediately.'))
            ->visible(fn (Client $record) => auth()->user()?->can(Permission::ClientsPackageOverride->value) && $record->hasActivePackageOverride())
            ->action(fn (Client $record) => app(PackageOverrides::class)->end($record, 'manual', auth()->user()));
    }

    /**
     * Documented exception: silence the "explicit premium without a link"
     * warning for one client. Same permission as linking. The button stays
     * visible while the warning applies; it never hides the information,
     * the muted state is shown in grey with its reason.
     */
    public static function muteLinkWarningAction(): Action
    {
        return Action::make('muteLinkWarning')
            ->label(__('Mute link warning'))
            ->icon('heroicon-o-bell-slash')
            ->color('gray')
            ->visible(fn (Client $record) => (auth()->user()?->can(Permission::ClientsManage->value) ?? false) && static::linkWarning($record) !== null)
            ->modalHeading(fn (Client $record) => __('Mute the link warning for :name', ['name' => $record->name]))
            ->modalSubmitActionLabel(__('Mute link warning'))
            ->modalDescription(__('For a client whose explicit premium package is legitimate without a sponsor link. The client leaves the "Explicit premium without a link" tab, the counter and the warning triangle; the mute is shown in grey on the client with its reason and is written to the audit log. Linking the client later ends the mute.'))
            ->schema([
                Textarea::make('reason')->label(__('Reason'))->required()->minLength(ClientLinks::MIN_MUTE_REASON_LENGTH)->maxLength(500)->rows(3)
                    ->placeholder(__('e.g. ticket number, who decided it and why the client needs no sponsor')),
                DatePicker::make('until')->label(__('Valid until'))->native(false)->minDate(now()->addDay())
                    ->helperText(__('Optional. At the end of this day the warning applies again.')),
            ])
            ->action(function (Client $record, array $data): void {
                try {
                    app(ClientLinks::class)->muteWarning($record, $data['reason'], filled($data['until'] ?? null) ? Carbon::parse($data['until'])->endOfDay() : null, auth()->user());
                    Notification::make()->title(__('Link warning muted'))->success()->send();
                } catch (ValidationException $e) {
                    Notification::make()->title($e->validator->errors()->first())->danger()->send();
                }
            });
    }

    public static function unmuteLinkWarningAction(): Action
    {
        return Action::make('unmuteLinkWarning')
            ->label(__('Unmute link warning'))
            ->icon('heroicon-o-bell-alert')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('The "explicit premium without a link" warning applies to this client again immediately.'))
            ->visible(fn (Client $record) => (auth()->user()?->can(Permission::ClientsManage->value) ?? false) && $record->hasMutedLinkWarning())
            ->action(fn (Client $record) => app(ClientLinks::class)->unmuteWarning($record, 'manual', auth()->user()));
    }

    /**
     * Directory columns surfaced by the operator (Settings › Clients ›
     * Directory fields): read-only entries on the client page.
     *
     * @return list<TextEntry>
     */
    public static function directoryFieldEntries(): array
    {
        $entries = [];

        foreach (app(ClientExporter::class)->directoryFields() as $column => $label) {
            $entries[] = TextEntry::make('directory_'.Str::slug($column, '_'))->label($label)->placeholder('-')
                ->state(fn (Client $record) => $record->externalRecord?->attribute($column));
        }

        return $entries;
    }

    /**
     * The same directory columns on the list, hidden until toggled on.
     *
     * @return list<TextColumn>
     */
    public static function directoryFieldColumns(): array
    {
        $columns = [];

        foreach (app(ClientExporter::class)->directoryFields() as $column => $label) {
            $columns[] = TextColumn::make('directory_'.Str::slug($column, '_'))->label($label)->placeholder('-')->toggleable(isToggledHiddenByDefault: true)
                ->state(fn (Client $record) => $record->externalRecord?->attribute($column));
        }

        return $columns;
    }

    /**
     * Package names that would raise this client's directory-given level.
     *
     * @return array<string, string>
     */
    public static function upgradeOptions(Client $client): array
    {
        $tiers = app(ClientTiers::class);
        $current = $tiers->tierFromDirectory($client);
        $rank = fn (?ClientTier $t) => match ($t) {
            ClientTier::Premium => 2, ClientTier::Standard => 1, default => 0
        };

        return array_filter(static::packageOptions(), fn (string $name) => $rank($tiers->tierOfPackage($name)) > $rank($current), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Package names an operator may pick: the configured lists, plus the
     * current value so that an old record still opens for editing.
     *
     * @return array<string, string>
     */
    public static function packageOptions(?string $current = null): array
    {
        $settings = app(Settings::class);
        $names = [...$settings->array(SettingKey::PackagesPremium), ...$settings->array(SettingKey::PackagesStandard)];

        if ($current !== null && $current !== '' && ! in_array($current, $names, true)) {
            $names[] = $current;
        }

        $names = array_values(array_unique(array_filter(array_map(fn ($n) => trim((string) $n), $names))));

        return array_combine($names, $names);
    }

    /**
     * Warning when a client depends on a sponsor but has none.
     */
    public static function linkWarning(Client $client): ?string
    {
        if (app(ClientLinks::class)->isMissingSponsor($client)) {
            return __('Premium only by explicit package, but not linked to any implicit premium client. The helpdesk should link them.');
        }

        return null;
    }

    /**
     * "Warning muted: reason · by whom · until when", or null when the
     * client has no muted warning.
     */
    public static function linkWarningMutedNote(Client $client): ?string
    {
        if (! $client->hasMutedLinkWarning()) {
            return null;
        }

        return __('Link warning muted: :reason · by :who · :until', [
            'reason' => $client->link_warning_muted_reason,
            'who' => $client->linkWarningMutedBy?->name ?? '-',
            'until' => $client->link_warning_muted_until ? __('until :date', ['date' => $client->link_warning_muted_until->format(HuDate::DATETIME)]) : __('no end date'),
        ]);
    }

    /**
     * Small inline marks after the name: lock when closed, star when
     * premium, warning triangle when a link is missing.
     */
    public static function nameMarks(Client $client): string
    {
        $marks = [];

        if ($client->hasActivePackageOverride()) {
            $marks[] = svg('heroicon-s-adjustments-horizontal', 'inline-block h-4 w-4 text-warning-600', ['title' => __('Package override')])->toHtml();
        }

        if ($client->isClosed()) {
            $marks[] = svg('heroicon-s-lock-closed', 'inline-block h-4 w-4 text-danger-500', ['title' => __('Account closed')])->toHtml();
        }

        if (app(ClientTiers::class)->tierFor($client) === ClientTier::Premium) {
            $marks[] = svg('heroicon-s-star', 'inline-block h-4 w-4 text-warning-500', ['title' => __('Premium')])->toHtml();
        }

        if (static::linkWarning($client) !== null) {
            $marks[] = svg('heroicon-s-exclamation-triangle', 'inline-block h-4 w-4 text-danger-500', ['title' => __('Not linked')])->toHtml();
        }

        if ($client->hasMutedLinkWarning()) {
            $marks[] = svg('heroicon-s-bell-slash', 'inline-block h-4 w-4 text-gray-400', ['title' => __('Link warning muted')])->toHtml();
        }

        return $marks === [] ? '' : ' <span class="ms-1 inline-flex items-center gap-1 align-text-bottom">'.implode('', $marks).'</span>';
    }

    /**
     * What closing this account drags along.
     */
    public static function closeWarning(Client $client): string
    {
        $linked = $client->activeLinks()->with('linked')->get()->map(fn ($link) => $link->linked)->filter(fn ($c) => $c !== null && ! $c->isClosed());

        if ($linked->isEmpty()) {
            return __('The client can no longer sign in to the portal and cannot be identified until reopened.');
        }

        $names = $linked->take(8)->map(fn (Client $c) => $c->name.' ('.$c->email.')')->implode(', ');
        $more = $linked->count() > 8 ? ' '.__('and :n more', ['n' => $linked->count() - 8]) : '';

        return __('This client sponsors :n linked client(s). Closing it also closes their accounts: :names:more. Reopening this client reopens them.', ['n' => $linked->count(), 'names' => $names, 'more' => $more]);
    }

    public static function supportOf(Client $client): ?array
    {
        $tier = app(ClientTiers::class)->tierFor($client);

        return $tier === null ? null : app(ClientTiers::class)->supportFor($tier);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Client'))->columns(2)->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255)
                    ->disabled(fn (?Client $record) => $record?->isSynced() ?? false)->dehydrated(fn (?Client $record) => ! ($record?->isSynced() ?? false)),
                TextInput::make('email')->label(__('E-mail'))->email()->required()->unique(ignoreRecord: true)->maxLength(255)
                    ->disabled(fn (?Client $record) => $record?->isSynced() ?? false)->dehydrated(fn (?Client $record) => ! ($record?->isSynced() ?? false)),
                Select::make('implicit_package')->label(__('Implicit package'))->searchable()->native(false)
                    ->options(fn (?Client $record) => static::packageOptions($record?->implicit_package))
                    ->disabled(fn (?Client $record) => $record?->isSynced() ?? false)->dehydrated(fn (?Client $record) => ! ($record?->isSynced() ?? false))
                    ->helperText(fn (?Client $record) => $record?->isSynced()
                        ? __('Comes from EMD; change it there. Last EMD sync: :when', ['when' => $record->externalRecord?->last_seen_at?->diffForHumans() ?? '-'])
                        : __('Only names from the configured package lists can be chosen, so a typo cannot lock a client out.')),
                Select::make('explicit_package')->label(__('Explicit package'))->searchable()->native(false)
                    ->options(fn (?Client $record) => static::packageOptions($record?->explicit_package))
                    ->disabled(fn (?Client $record) => $record?->isSynced() ?? false)->dehydrated(fn (?Client $record) => ! ($record?->isSynced() ?? false))
                    ->helperText(fn (?Client $record) => $record?->isSynced()
                        ? __('Comes from EMD. For a rare, temporary exception use the "Override package" action on the client page.')
                        : __('Only names from the configured package lists can be chosen. Add a new name under Settings › Packages first.')),
                Textarea::make('notes')->label(__('Notes'))->rows(4)->maxLength(5000)->columnSpanFull()
                    ->helperText(__('Internal note for the helpdesk; shown on the identification page. The client never sees it.')),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $answers = app(ClientAnswers::class);

        return $schema->components([
            Section::make(__('Client'))->columns(3)->schema([
                TextEntry::make('name')->label(__('Name')),
                TextEntry::make('email')->label(__('E-mail')),
                TextEntry::make('closed_at')->label(__('Status'))->badge()
                    ->state(fn (Client $record) => $record->isClosed() ? __('Closed (:r)', ['r' => __($record->closed_reason)]) : __('Open'))
                    ->color(fn (Client $record) => $record->isClosed() ? 'danger' : 'success'),
                TextEntry::make('externalRecord.external_id')->label(__('External id'))->placeholder(__('not linked to EMD')),
                TextEntry::make('externalRecord.company')->label(__('Company'))->placeholder('-'),
                TextEntry::make('job_title')->label(__('Job title'))->placeholder('-')->state(fn (Client $record) => $record->externalRecord?->jobTitle()),
                TextEntry::make('department')->label(__('Department'))->placeholder('-')->state(fn (Client $record) => $record->externalRecord?->departmentName()),
                TextEntry::make('externalRecord.login_name')->label(__('Login name'))->visible(fn (Client $record) => filled($record->externalRecord?->login_name)),
                TextEntry::make('externalRecord.room')->label(__('Room'))->visible(fn (Client $record) => filled($record->externalRecord?->room)),
                TextEntry::make('externalRecord.employment_status')->label(__('Employment status'))->visible(fn (Client $record) => filled($record->externalRecord?->employment_status)),
                TextEntry::make('externalRecord.missing_since')->label(__('Missing since'))->since()->placeholder(__('present')),
                TextEntry::make('implicit_package')->label(__('Implicit package'))->placeholder('-'),
                TextEntry::make('explicit_package')->label(__('Explicit package'))->placeholder('-')
                    ->helperText(fn (Client $record) => $record->hasActivePackageOverride() ? __('EMD value; an override is in force, see below.') : null),
                TextEntry::make('package_override')->label(__('Package override'))->badge()->color('warning')
                    ->visible(fn (Client $record) => $record->hasActivePackageOverride())
                    ->helperText(fn (Client $record) => __(':reason · by :who · until :until', ['reason' => $record->package_override_reason, 'who' => $record->packageOverrideBy?->name ?? '-', 'until' => $record->package_override_until?->format(HuDate::DATETIME)])),
                TextEntry::make('tier')->label(__('Portal access'))->badge()
                    ->state(fn (Client $record) => app(ClientTiers::class)->tierFor($record)?->label() ?? __('None'))
                    ->color(fn (Client $record) => match (app(ClientTiers::class)->tierFor($record)) {
                        ClientTier::Premium => 'warning', ClientTier::Standard => 'success', default => 'gray',
                    }),
                TextEntry::make('externalRecord.email_domain')->label(__('E-mail domain'))->placeholder('-'),
                TextEntry::make('answers')->label(__('Security questions'))->badge()
                    ->state(fn (Client $record) => __(':n / :r answers', ['n' => $answers->usableCount($record), 'r' => $answers->requiredCount()]))
                    ->color(fn (Client $record) => $answers->isEligible($record) ? 'success' : 'gray'),
                IconEntry::make('pin_hash')->label(__('PIN set'))->boolean()->state(fn (Client $record) => $record->hasPin()),
                TextEntry::make('pin_locked_until')->label(__('PIN locked until'))->dateTime()->placeholder('-'),
                TextEntry::make('last_login_at')->label(__('Last login'))->since()->placeholder('-'),
                TextEntry::make('sponsor')->label(__('Linked to'))->placeholder(__('not linked'))
                    ->state(fn (Client $record) => $record->sponsor()?->name)
                    ->url(fn (Client $record) => ($s = $record->sponsor()) ? static::getUrl('view', ['record' => $s]) : null)
                    ->helperText(fn (Client $record) => static::linkWarning($record) ?? static::linkWarningMutedNote($record)),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('-')->columnSpanFull()->prose(),
            ]),
            Section::make(__('EMD data'))->columns(3)->description(__('Further columns of EMD, as chosen under Settings › Clients › EMD fields. Read-only.'))
                ->visible(fn () => app(ClientExporter::class)->directoryFields() !== [])
                ->schema(static::directoryFieldEntries()),
            Section::make(__('Support line for this client'))->columns(3)->description(__('The client should call the line of their own level; the support contact shown to them on the portal.'))
                ->schema([
                    TextEntry::make('support_phone')->label(__('Phone'))->placeholder('-')->state(fn (Client $record) => static::supportOf($record)['phone'] ?? null),
                    TextEntry::make('support_email')->label(__('E-mail'))->placeholder('-')->state(fn (Client $record) => static::supportOf($record)['email'] ?? null),
                    TextEntry::make('support_hours')->label(__('Hours'))->placeholder('-')->state(fn (Client $record) => static::supportOf($record)['hours'] ?? null),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $answers = app(ClientAnswers::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['externalRecord', 'phoneNumbers', 'activeSponsorLink.sponsor', 'linkWarningMutedBy'])->withCount('answers'))
            // Phone numbers must reach the search whole ("06 30 123 4567"); names split into words below.
            ->splitSearchTerms(false)
            ->columns([
                TextColumn::make('name')->label(__('Name'))->sortable()->weight('semibold')
                    ->searchable(query: fn (Builder $query, string $search) => static::searchNames($query, $search))
                    ->description(fn (Client $record) => new HtmlString(e($record->email).($record->externalRecord?->company ? '<br>'.e($record->externalRecord->company) : '')))
                    ->formatStateUsing(fn (string $state, Client $record) => new HtmlString(e($state).static::nameMarks($record)))
                    ->tooltip(fn (Client $record) => static::linkWarning($record) ?? static::linkWarningMutedNote($record) ?? ($record->isClosed() ? __('Closed (:r)', ['r' => __((string) $record->closed_reason)]) : app(ClientTiers::class)->tierFor($record)?->label())),
                TextColumn::make('phoneNumbers.number_e164')->label(__('Phone numbers'))->listWithLineBreaks()->limitList(2)->toggleable()
                    ->searchable(query: fn (Builder $query, string $search) => static::searchPhones($query, $search)),
                TextColumn::make('externalRecord.email_domain')->label(__('Domain'))->badge()->color('gray')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('externalRecord.external_id')->label(__('External id'))->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('job_title')->label(__('Job title'))->placeholder('-')->toggleable(isToggledHiddenByDefault: true)->state(fn (Client $record) => $record->externalRecord?->jobTitle()),
                TextColumn::make('department')->label(__('Department'))->placeholder('-')->toggleable(isToggledHiddenByDefault: true)->state(fn (Client $record) => $record->externalRecord?->departmentName()),
                TextColumn::make('implicit_package')->label(__('Implicit package'))->placeholder('-')->toggleable(),
                TextColumn::make('explicit_package')->label(__('Explicit package'))->placeholder('-')->toggleable(),
                TextColumn::make('answers_count')->label(__('Answers'))->badge()->sortable()->alignCenter()
                    ->color(fn (Client $record) => $answers->isEligible($record) ? 'success' : 'gray'),
                IconColumn::make('pin')->label(__('PIN'))->boolean()->alignCenter()->state(fn (Client $record) => $record->hasPin()),
                IconColumn::make('externalRecord.id')->label(__('Linked to EMD'))->boolean()->alignCenter()->toggleable()
                    ->color(fn (Client $record) => $record->externalRecord === null ? 'danger' : ($record->externalRecord->isMissing() ? 'warning' : 'success')),
                TextColumn::make('closed_at')->label(__('Closed'))->since()->placeholder(__('open'))->badge()->color('danger')->toggleable(isToggledHiddenByDefault: true),
                ...static::directoryFieldColumns(),
            ])
            ->filters([
                TernaryFilter::make('relevant')->label(__('Relevant clients only'))
                    ->default(fn () => app(Settings::class)->bool(SettingKey::ClientsListRelevantOnly) ? true : null)
                    ->trueLabel(__('Only relevant: portal access, closed or any history'))
                    ->falseLabel(__('Only EMD-only accounts'))
                    ->placeholder(__('Everyone'))
                    ->queries(
                        true: fn (Builder $query) => app(ClientTiers::class)->scopeRelevant($query),
                        false: fn (Builder $query) => $query->whereNotIn('id', static::relevantIds()),
                    ),
                SelectFilter::make('tier')->label(__('Level'))
                    ->visible(fn () => count(app(ClientTiers::class)->activeTiers()) > 1)
                    ->options(collect(app(ClientTiers::class)->activeTiers())->mapWithKeys(fn (ClientTier $t) => [$t->value => $t->label()])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, string $v) => app(ClientTiers::class)->scopeClients($q, [ClientTier::from($v)]))),
                SelectFilter::make('company')->label(__('Company'))->searchable()
                    ->options(fn () => ExternalRecord::query()->whereNotNull('company')->distinct()->orderBy('company')->pluck('company', 'company'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, string $v) => $q->whereHas('externalRecord', fn ($r) => $r->where('company', $v)))),
                SelectFilter::make('email_domain')->label(__('E-mail domain'))->searchable()
                    ->options(fn () => ExternalRecord::query()->whereNotNull('email_domain')->distinct()->orderBy('email_domain')->pluck('email_domain', 'email_domain'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, string $v) => $q->whereHas('externalRecord', fn ($r) => $r->where('email_domain', $v)))),
                SelectFilter::make('implicit_package')->label(__('Implicit package'))->searchable()
                    ->options(fn () => Client::query()->whereNotNull('implicit_package')->distinct()->orderBy('implicit_package')->pluck('implicit_package', 'implicit_package')),
                SelectFilter::make('explicit_package')->label(__('Explicit package'))->searchable()
                    ->options(fn () => Client::query()->whereNotNull('explicit_package')->distinct()->orderBy('explicit_package')->pluck('explicit_package', 'explicit_package')),
                TernaryFilter::make('unlinked_premium')->label(__('Explicit premium without a link'))->queries(
                    true: fn (Builder $query) => static::scopeUnlinkedPremium($query),
                    false: fn (Builder $query) => $query->whereNotIn('id', static::scopeUnlinkedPremium(Client::query()->select('id'))),
                ),
                TernaryFilter::make('link_warning_muted')->label(__('Link warning muted'))->queries(
                    true: fn (Builder $query) => $query->linkWarningMuted(true),
                    false: fn (Builder $query) => $query->linkWarningMuted(false),
                ),
                TernaryFilter::make('has_pin')->label(__('PIN set'))->queries(
                    true: fn (Builder $query) => $query->whereNotNull('pin_hash'),
                    false: fn (Builder $query) => $query->whereNull('pin_hash'),
                ),
                TernaryFilter::make('synced')->label(__('Linked to EMD'))->queries(
                    true: fn (Builder $query) => $query->whereHas('externalRecord', fn ($r) => $r->whereNull('missing_since')),
                    false: fn (Builder $query) => $query->whereDoesntHave('externalRecord', fn ($r) => $r->whereNull('missing_since')),
                ),
                TernaryFilter::make('closed')->label(__('Closed'))->queries(
                    true: fn (Builder $query) => $query->whereNotNull('closed_at'),
                    false: fn (Builder $query) => $query->whereNull('closed_at'),
                ),
                TrashedFilter::make(),
            ])
            ->filtersFormColumns(2)
            ->emptyStateHeading(__('No clients match'))
            ->emptyStateDescription(__('Change the filters above, or switch off "Relevant clients only" to see EMD-only accounts too.'))
            ->recordActions([
                static::identifyAction()->iconButton()->tooltip(__('Identify')),
                ViewAction::make()->iconButton()->tooltip(__('View')),
                ActionGroup::make([
                    EditAction::make(),
                    SetPinAction::make(),
                    SendMagicLinkAction::make(),
                    static::overrideAction(),
                    static::endOverrideAction(),
                    static::muteLinkWarningAction(),
                    static::unmuteLinkWarningAction(),
                    Action::make('clearPin')->label(__('Clear PIN'))->icon('heroicon-o-key')->color('gray')->requiresConfirmation()
                        ->visible(fn (Client $record) => $record->hasPin() && static::canEdit($record))
                        ->action(fn (Client $record) => app(PinService::class)->clearPin($record)),
                    Action::make('close')->label(__('Close account'))->icon('heroicon-o-lock-closed')->color('danger')->requiresConfirmation()
                        ->modalHeading(fn (Client $record) => __('Close the account of :name?', ['name' => $record->name]))
                        ->modalDescription(fn (Client $record) => static::closeWarning($record))
                        ->modalSubmitActionLabel(fn (Client $record) => $record->activeLinks()->exists() ? __('Close all of them') : __('Close account'))
                        ->visible(fn (Client $record) => ! $record->isClosed() && static::canEdit($record))
                        ->action(fn (Client $record) => $record->close('admin')),
                    Action::make('reopen')->label(__('Reopen'))->icon('heroicon-o-lock-open')->color('success')->requiresConfirmation()
                        ->visible(fn (Client $record) => $record->isClosed() && static::canEdit($record))
                        ->action(fn (Client $record) => $record->reopen()),
                    DeleteAction::make(),
                    RestoreAction::make(),
                ])->icon('heroicon-m-ellipsis-vertical')->color('gray')->tooltip(__('More')),
            ])
            ->defaultSort('name');
    }

    /**
     * Every word must match the name, the e-mail or the company, in any order.
     *
     * @param  Builder<Client>  $query
     */
    public static function searchNames(Builder $query, string $search): void
    {
        foreach (preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $word).'%';

            $query->where(fn (Builder $q) => $q
                ->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhereHas('externalRecord', fn ($r) => $r->where('company', 'like', $like)));
        }
    }

    /**
     * Phone search the way agents type numbers: "06 30 123 4567" is normalised
     * to E.164 first; anything shorter falls back to a digits-only contains.
     *
     * @param  Builder<Client>  $query
     */
    public static function searchPhones(Builder $query, string $search): void
    {
        $e164 = app(PhoneNormalizer::class)->normalize($search);
        $digits = preg_replace('/\D+/', '', $search) ?? '';

        if ($e164 !== null) {
            $query->whereHas('phoneNumbers', fn (Builder $q) => $q->where('number_e164', $e164));
        } elseif ($digits !== '') {
            $query->whereHas('phoneNumbers', fn (Builder $q) => $q->where('number_e164', 'like', '%'.$digits.'%'));
        } else {
            $query->whereRaw('1 = 0');
        }
    }

    /**
     * Ids of the clients the default list shows.
     *
     * @return Builder<Client>
     */
    public static function relevantIds(): Builder
    {
        $sub = Client::query()->select('id');
        app(ClientTiers::class)->scopeRelevant($sub);

        return $sub;
    }

    /**
     * Premium by explicit package only, without an active sponsor link.
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public static function scopeUnlinkedPremium(Builder $query): Builder
    {
        return app(ClientLinks::class)->scopeMissingSponsor($query);
    }

    /**
     * Open clients still waiting for a sponsor link; shown as a badge on the
     * menu and on the list tab so nobody has to hunt for the triangle.
     */
    public static function unlinkedPremiumCount(): int
    {
        return static::scopeUnlinkedPremium(Client::query()->open())->count();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! app(Settings::class)->bool(SettingKey::ClientsUnlinkedBadge)) {
            return null;
        }

        $count = Cache::remember('clients:unlinked-premium-count', 60, fn (): int => static::unlinkedPremiumCount());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Explicit premium clients without a sponsor link');
    }

    public static function getRelations(): array
    {
        return [
            LinkedClientsRelationManager::class,
            PhoneNumbersRelationManager::class,
            AnswersRelationManager::class,
            IdSessionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'view' => ViewClient::route('/{record}'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
