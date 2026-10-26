<?php

namespace App\Filament\Admin\Resources\Users;

use App\Auth\Mfa\StaffMfa;
use App\Auth\Oidc\SsoAccess;
use App\Auth\Permission;
use App\Auth\Role;
use App\Auth\RoleAssignment;
use App\Clients\ClientTiers;
use App\Enums\ClientTier;
use App\Enums\MfaMethod;
use App\Enums\PrincipalType;
use App\Filament\Actions\UnbindSsoAction;
use App\Filament\Actions\UnlockLoginAction;
use App\Filament\Admin\Clusters\Accounts;
use App\Filament\Admin\Resources\BaseResource;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Support\EmailAddressRule;
use App\Support\HuDate;
use App\System\NotDemoAddressRule;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Spatie\Permission\Models\Role as RoleModel;

class UserResource extends BaseResource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $cluster = Accounts::class;

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return __('user');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Users');
    }

    public static function getNavigationLabel(): string
    {
        return __('Users');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Account'))->columns(2)->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                // The e-mail is the account's identity: on an account linked to EMD or bound
                // to a company sign-in only the sync changes it (LinksToDirectory).
                TextInput::make('email')->label(__('E-mail'))->type('email')->required()->unique(ignoreRecord: true)->maxLength(EmailAddressRule::MAX_LENGTH)->rule(new EmailAddressRule)->rule(new NotDemoAddressRule)
                    ->disabled(fn (?User $record): bool => $record?->manualEmailLock() !== null)
                    ->dehydrated(fn (?User $record): bool => $record?->manualEmailLock() === null)
                    ->helperText(fn (?User $record) => match (true) {
                        $record === null => __('Must match the e-mail claim of the SSO provider and the EMD record. If EMD already holds a staff record with this e-mail, saving links the account at once.'),
                        $record->manualEmailLock() !== null => EditUser::emailLockText($record),
                        default => trim(__('Must match the e-mail claim of the SSO provider and the EMD record. Saving a new address signs the account out everywhere (the Save button asks first); if EMD holds a staff record with the new address, the account is linked to it, and the password, second factors and sign-in binding set up before are removed.').' '.(EditUser::handOverNote($record) ?? '')),
                    }),
                TextInput::make('password')->label(__('Password'))->password()->revealable()->minLength(12)->maxLength(255)
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $operation) => static::passwordRequired($operation))
                    ->helperText(fn (string $operation) => static::passwordRequired($operation)
                        ? __('Required: no staff SSO provider is switched on and configured, so a password is the only way to sign in.')
                        : __('Only needed for password login; leave empty for SSO-only accounts.')),
                Select::make('roles')->label(__('Roles'))->relationship('roles', 'name')->multiple()->preload()->required()
                    // Greyed out: a role the editor may neither grant nor take away (RoleAssignment::canGrant).
                    ->disableOptionWhen(fn (int|string $value): bool => array_key_exists($value, app(RoleAssignment::class)->lockedRoles(auth()->user())))
                    // A held role that is greyed out stays in the state, so it must pass the options check.
                    ->in(fn (Select $component): array => array_keys($component->getOptions()))
                    ->helperText(fn (?User $record): ?string => static::roleGrantHelp($record))
                    ->rule(fn (?User $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        $names = RoleModel::query()->whereKey((array) $value)->pluck('name');

                        try {
                            app(RoleAssignment::class)->assertAssignable(auth()->user(), $names, $record);
                        } catch (AuthorizationException $e) {
                            $fail($e->getMessage());
                        }
                    }),
                CheckboxList::make('handles_tiers')->label(__('Handles calls of'))
                    ->options(collect(app(ClientTiers::class)->activeTiers())->mapWithKeys(fn (ClientTier $t) => [$t->value => $t->label()])->all())
                    ->default(array_map(fn (ClientTier $t) => $t->value, app(ClientTiers::class)->activeTiers()))
                    ->formatStateUsing(fn ($state) => filled($state) ? $state : array_map(fn (ClientTier $t) => $t->value, app(ClientTiers::class)->activeTiers()))
                    ->visible(fn () => count(app(ClientTiers::class)->activeTiers()) > 1)
                    ->helperText(__('Which client levels this person may take calls for. They switch the current one with the badge in the header.')),
            ]),
            Section::make(__('EMD data'))->columns(2)->description(__('Comes from EMD sync; change it there. Read-only.'))
                ->visible(fn (?User $record) => $record?->externalRecord !== null)
                ->schema([
                    TextEntry::make('directory_external_id')->label(__('External id'))->placeholder('-')->state(fn (?User $record) => $record?->externalRecord?->external_id),
                    TextEntry::make('directory_company')->label(__('Company'))->placeholder('-')->state(fn (?User $record) => $record?->externalRecord?->company),
                    TextEntry::make('directory_job_title')->label(__('Job title'))->placeholder('-')->state(fn (?User $record) => $record?->externalRecord?->jobTitle()),
                    TextEntry::make('directory_department')->label(__('Department'))->placeholder('-')->state(fn (?User $record) => $record?->externalRecord?->departmentName()),
                    TextEntry::make('directory_login_name')->label(__('Login name'))->state(fn (?User $record) => $record?->externalRecord?->login_name)->visible(fn (?User $record) => filled($record?->externalRecord?->login_name)),
                    TextEntry::make('directory_room')->label(__('Room'))->state(fn (?User $record) => $record?->externalRecord?->room)->visible(fn (?User $record) => filled($record?->externalRecord?->room)),
                    TextEntry::make('directory_employment_status')->label(__('Employment status'))->state(fn (?User $record) => $record?->externalRecord?->employment_status)->visible(fn (?User $record) => filled($record?->externalRecord?->employment_status)),
                ]),
            Section::make(__('Company sign-in binding'))
                ->key('ssoBinding')
                ->description(__('The directory identity (Entra object id, ADFS subject) the account\'s company sign-in is bound to. The first eligible sign-in binds it; afterwards a sign-in under the same e-mail address from another identity is refused. "Clear sign-in binding" next to it lets the next sign-in bind anew.'))
                ->visible(fn (?User $record) => $record !== null)
                // The button stands with the binding it clears, and only while there is one.
                ->afterHeader(fn (?User $record): array => $record === null ? [] : [UnbindSsoAction::make()->button()->size(Size::ExtraSmall)])
                ->schema([
                    TextEntry::make('sso_bindings')->hiddenLabel()->listWithLineBreaks()
                        ->state(fn (?User $record) => $record === null ? [] : UnbindSsoAction::lines($record))
                        ->placeholder(__('Not bound yet: the first company sign-in binds the account.')),
                ]),
        ]);
    }

    /**
     * The factors a staff member set up (or why none can be used), for the list.
     */
    public static function mfaState(User $record): string
    {
        $mfa = app(StaffMfa::class);

        if ($mfa->isLocked($record)) {
            return __('locked until :time', ['time' => $record->mfa_locked_until->format(HuDate::DATETIME)]);
        }

        $methods = $mfa->enrolledMethods($record);

        return $methods === [] ? __('none') : implode(', ', array_map(fn (MfaMethod $method): string => $method->label(), $methods));
    }

    /**
     * Under the roles field: the rule of granting, the greyed-out roles with
     * how many of their permissions the editor lacks, and the held ones the
     * account keeps whatever the editor sends. Nothing for a SuperAdmin, who
     * may grant every role.
     */
    public static function roleGrantHelp(?User $record): ?string
    {
        $roles = app(RoleAssignment::class);
        $actor = auth()->user();

        if (! $actor instanceof User || $roles->isSuperAdmin($actor)) {
            return null;
        }

        $locked = collect($roles->lockedRoles($actor));
        $lacking = $locked
            ->reject(fn (array $role): bool => $role['name'] === Role::SuperAdmin->value)
            ->map(fn (array $role): string => __(':role (missing permissions: :count)', ['role' => $role['name'], 'count' => count($role['missing'])]))
            ->implode(', ');
        $kept = $record?->exists
            ? $record->roles->pluck('name')->intersect($locked->pluck('name'))->implode(', ')
            : '';

        return implode(' ', array_filter([
            __('You can only grant or remove a role whose every permission you hold, and only a SuperAdmin can grant the SuperAdmin role.'),
            $lacking !== '' ? __('Greyed out for you: :roles.', ['roles' => $lacking]) : null,
            $kept !== '' ? __('Stays on this account as it is: :roles.', ['roles' => $kept]) : null,
        ]));
    }

    /**
     * A new account needs a password only while staff cannot sign in any other way.
     */
    public static function passwordRequired(string $operation): bool
    {
        return $operation === 'create' && ! app(SsoAccess::class)->anyAvailable(PrincipalType::User);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['roles', 'externalRecord', 'ssoIdentities']))
            // One search over everything the list shows; the words are split below.
            ->splitSearchTerms(false)
            ->columns([
                TextColumn::make('name')->label(__('Name'))->sortable()
                    ->searchable(query: fn (Builder $query, string $search) => static::searchAccounts($query, $search)),
                TextColumn::make('email')->label(__('E-mail'))->sortable(),
                TextColumn::make('roles.name')->badge()->label(__('Roles')),
                TextColumn::make('externalRecord.external_id')->label(__('External id'))->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('job_title')->label(__('Job title'))->placeholder('-')->toggleable()->state(fn (User $record) => $record->externalRecord?->jobTitle()),
                TextColumn::make('department')->label(__('Department'))->placeholder('-')->toggleable()->state(fn (User $record) => $record->externalRecord?->departmentName()),
                IconColumn::make('externalRecord.id')->label(__('Linked to EMD'))->boolean()
                    ->tooltip(fn (User $record) => $record->externalRecord?->isMissing() ? __('Missing from the last EMD sync') : null)
                    ->color(fn (User $record) => $record->externalRecord === null ? 'danger' : ($record->externalRecord->isMissing() ? 'warning' : 'success')),
                TextColumn::make('mfa')->label(__('Second factor'))->badge()->toggleable()
                    ->state(fn (User $record): string => static::mfaState($record))
                    ->color(fn (User $record): string => match (true) {
                        app(StaffMfa::class)->isLocked($record) => 'danger',
                        app(StaffMfa::class)->enrolledMethods($record) !== [] => 'success',
                        default => app(StaffMfa::class)->isEnabled() ? 'warning' : 'gray',
                    }),
                TextColumn::make('sso_binding')->label(__('Sign-in binding'))->badge()->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (User $record): string => UnbindSsoAction::summary($record))
                    ->color(fn (User $record): string => $record->ssoIdentities->isNotEmpty() ? 'success' : 'gray'),
                TextColumn::make('closed_at')->label(__('Closed'))->since()->placeholder(__('open'))->badge()->color('danger'),
                TextColumn::make('last_login_at')->label(__('Last login'))->since()->placeholder('-'),
            ])
            ->filters([
                TernaryFilter::make('closed')->label(__('Closed'))->queries(
                    true: fn (Builder $query) => $query->whereNotNull('closed_at'),
                    false: fn (Builder $query) => $query->whereNull('closed_at'),
                ),
                TrashedFilter::make(),
            ])
            ->recordActions([
                // An account the editor may not change (a stronger one) stays listed, its
                // buttons greyed out with the reason (RoleAssignment::canManage).
                static::guarded(EditAction::make()),
                ActionGroup::make([
                    static::guarded(Action::make('close')->label(__('Close account'))->icon('heroicon-o-lock-closed')->color('danger')->requiresConfirmation()
                        ->modalHeading(fn (User $record) => __('Close the account of :name?', ['name' => $record->name]))
                        ->modalDescription(__('The staff member is signed out of every browser and device at once and cannot sign in by any method; the account can be reopened later.'))
                        ->authorize(fn (User $record) => static::canEdit($record))
                        ->visible(fn (User $record) => ! $record->isClosed())
                        ->action(fn (User $record) => $record->close('admin'))),
                    static::guarded(Action::make('reopen')->label(__('Reopen'))->icon('heroicon-o-lock-open')->color('success')
                        ->authorize(fn (User $record) => static::canEdit($record))
                        ->visible(fn (User $record) => $record->isClosed())
                        ->action(fn (User $record) => $record->reopen())),
                    static::guarded(Action::make('resetMfa')->label(__('Reset second factor'))->icon('heroicon-o-shield-exclamation')->color('danger')
                        ->authorize(fn (User $record) => static::canEdit($record))
                        ->disabled(fn (User $record) => ! app(StaffMfa::class)->hasAnyFactor($record) && ! app(StaffMfa::class)->isLocked($record))
                        ->tooltip(fn (User $record) => app(StaffMfa::class)->hasAnyFactor($record) || app(StaffMfa::class)->isLocked($record) ? null : __('This staff member has no second factor.'))
                        ->requiresConfirmation()
                        ->modalHeading(fn (User $record) => __('Reset the second factor of :name?', ['name' => $record->name]))
                        ->modalDescription(__('All second factors of the account are removed, the staff member is signed out everywhere, and sets up a new factor at the next sign-in (an app needs a code from their e-mail too). Use it when the device or number was lost. Recorded in the audit log.'))
                        ->action(function (User $record): void {
                            abort_unless(static::canEdit($record), 403);
                            app(StaffMfa::class)->reset($record, auth()->user());
                            Notification::make()->title(__('Second factor reset'))->success()->send();
                        })),
                    UnlockLoginAction::make(),
                    UnbindSsoAction::make(),
                    static::guarded(DeleteAction::make()),
                    static::guarded(RestoreAction::make()),
                ])->icon('heroicon-m-ellipsis-vertical')->color('gray')->tooltip(__('More')),
            ]);
    }

    /**
     * Greys an account action out with the reason when the editor may not
     * change the account (it holds a role they could not grant); hidden
     * stays only for the lack of the permission itself.
     */
    public static function guarded(Action $action): Action
    {
        return $action
            ->authorizationTooltip()
            ->authorizationMessage(fn (?Model $record): ?string => static::manageRefusal($record));
    }

    /**
     * Why the signed-in editor may not change the account, or null.
     */
    public static function manageRefusal(?Model $record): ?string
    {
        $actor = auth()->user();

        return $record instanceof User && $actor instanceof User && $actor->can(Permission::UsersManage->value)
            ? app(RoleAssignment::class)->manageRefusal($actor, $record)
            : null;
    }

    /**
     * Every word of the search must appear in one of the fields the list
     * shows: name, e-mail, role, and the EMD record's external id, company,
     * job title, department or login name.
     *
     * @param  Builder<User>  $query
     */
    public static function searchAccounts(Builder $query, string $search): void
    {
        foreach (preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $word).'%';

            $query->where(fn (Builder $q) => $q
                ->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhereHas('roles', fn (Builder $roles) => $roles->where('name', 'like', $like))
                ->orWhereHas('externalRecord', fn (Builder $record) => $record
                    ->where('external_id', 'like', $like)
                    ->orWhere('company', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('department', 'like', $like)
                    ->orWhere('login_name', 'like', $like)));
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
