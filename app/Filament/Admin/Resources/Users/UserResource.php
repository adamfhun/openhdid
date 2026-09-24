<?php

namespace App\Filament\Admin\Resources\Users;

use App\Auth\RoleAssignment;
use App\Clients\ClientTiers;
use App\Enums\ClientTier;
use App\Filament\Admin\Clusters\Accounts;
use App\Filament\Admin\Resources\BaseResource;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\User;
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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
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
                TextInput::make('email')->label(__('E-mail'))->email()->required()->unique(ignoreRecord: true)->maxLength(255)
                    ->helperText(__('Must match the e-mail claim of the SSO provider and the EMD record.')),
                TextInput::make('password')->label(__('Password'))->password()->revealable()->minLength(12)->maxLength(255)
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $operation) => $operation === 'create')
                    ->helperText(__('Only needed for password login; leave empty for SSO-only accounts.')),
                Select::make('roles')->label(__('Roles'))->relationship('roles', 'name')->multiple()->preload()->required()
                    ->disableOptionWhen(fn (string $label) => ! app(RoleAssignment::class)->canGrant(auth()->user(), $label))
                    ->helperText(fn () => app(RoleAssignment::class)->isSuperAdmin(auth()->user()) ? null : __('The SuperAdmin role can only be granted by a SuperAdmin.'))
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
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['roles', 'externalRecord']))
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('email')->label(__('E-mail'))->searchable()->sortable(),
                TextColumn::make('roles.name')->badge()->label(__('Roles')),
                TextColumn::make('externalRecord.external_id')->label(__('External id'))->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('job_title')->label(__('Job title'))->placeholder('-')->toggleable()->state(fn (User $record) => $record->externalRecord?->jobTitle()),
                TextColumn::make('department')->label(__('Department'))->placeholder('-')->toggleable()->state(fn (User $record) => $record->externalRecord?->departmentName()),
                IconColumn::make('externalRecord.id')->label(__('Linked to EMD'))->boolean()
                    ->tooltip(fn (User $record) => $record->externalRecord?->isMissing() ? __('Missing from the last EMD sync') : null)
                    ->color(fn (User $record) => $record->externalRecord === null ? 'danger' : ($record->externalRecord->isMissing() ? 'warning' : 'success')),
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
                EditAction::make(),
                ActionGroup::make([
                    Action::make('close')->label(__('Close account'))->icon('heroicon-o-lock-closed')->color('danger')->requiresConfirmation()
                        ->authorize(fn (User $record) => static::canEdit($record))
                        ->visible(fn (User $record) => ! $record->isClosed())
                        ->action(fn (User $record) => $record->close('admin')),
                    Action::make('reopen')->label(__('Reopen'))->icon('heroicon-o-lock-open')->color('success')->requiresConfirmation()
                        ->authorize(fn (User $record) => static::canEdit($record))
                        ->visible(fn (User $record) => $record->isClosed())
                        ->action(fn (User $record) => $record->reopen()),
                    DeleteAction::make(),
                    RestoreAction::make(),
                ])->icon('heroicon-m-ellipsis-vertical')->color('gray')->tooltip(__('More')),
            ]);
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
