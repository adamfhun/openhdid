<?php

namespace App\Filament\Admin\Resources\Roles;

use App\Audit\RoleAudit;
use App\Auth\Permission;
use App\Auth\RoleAssignment;
use App\Filament\Admin\Clusters\Accounts;
use App\Filament\Admin\Resources\BaseResource;
use App\Filament\Admin\Resources\Roles\Pages\ManageRoles;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles and the permissions they grant. The four built-in roles can be
 * re-tuned but not renamed or deleted; custom roles can be added freely.
 * The SuperAdmin role only a SuperAdmin may edit, and an editor may only
 * grant or revoke the permissions they hold (RoleAssignment, RolePolicy).
 */
class RoleResource extends BaseResource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $cluster = Accounts::class;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('role');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Roles & permissions');
    }

    public static function getNavigationLabel(): string
    {
        return __('Roles & permissions');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(100)->unique(ignoreRecord: true)
                ->disabled(fn (?Role $record): bool => self::roles()->isBuiltIn($record))
                ->dehydrated(fn (?Role $record): bool => ! self::roles()->isBuiltIn($record))
                ->helperText(fn (?Role $record): ?string => self::roles()->isBuiltIn($record)
                    ? __('Built-in role: its name is fixed, because the permission checks rely on it.')
                    : null)
                ->rule(fn (?Role $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    if (! self::roles()->isBuiltIn($record) && is_string($value) && self::roles()->isReservedName($value)) {
                        $fail(__('This name belongs to a built-in role.'));
                    }
                }),
            Section::make(__('Permissions'))->schema([
                CheckboxList::make('permissions')
                    ->hiddenLabel()
                    ->relationship('permissions', 'name', fn (Builder $query) => $query->where('guard_name', 'web')->orderBy('name'))
                    ->getOptionLabelFromRecordUsing(fn ($record) => __('permission-group.'.Permission::from($record->name)->group()).' · '.Permission::from($record->name)->label())
                    ->getOptionDescriptionFromRecordUsing(fn ($record): ?string => self::holds($record->name) ? null : __('You do not hold this permission yourself.'))
                    ->disableOptionWhen(fn (int|string $value): bool => ! in_array((string) $value, self::heldPermissionIds(), true))
                    // A ticked permission the editor does not hold stays in the state (greyed out), so it must pass the options check.
                    ->in(fn (CheckboxList $component): array => array_keys($component->getOptions()))
                    ->helperText(fn (CheckboxList $component): ?string => count($component->getEnabledOptions()) < count($component->getOptions())
                        ? __('Greyed-out permissions are ones you do not hold yourself: you can neither grant nor revoke them.')
                        : null)
                    ->rule(fn (?Role $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        $names = PermissionModel::query()->whereKey((array) $value)->where('guard_name', 'web')->pluck('name');

                        try {
                            self::roles()->assertPermissionsGrantable(auth()->user(), $names, $record);
                        } catch (AuthorizationException $e) {
                            $fail($e->getMessage());
                        }
                    })
                    ->bulkToggleable()
                    ->searchable()
                    ->columns(2),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where('guard_name', 'web')->withCount(['permissions', 'users']))
            ->columns([
                TextColumn::make('name')->label(__('Name'))->weight('semibold')->sortable(),
                TextColumn::make('permissions_count')->label(__('Permissions'))->badge(),
                TextColumn::make('users_count')->label(__('Users'))->badge()->color('gray'),
                TextColumn::make('updated_at')->label(__('Updated'))->since(),
            ])
            ->recordActions([
                EditAction::make()
                    // The SuperAdmin row stays visible to an Admin, greyed out with the reason.
                    ->authorizationTooltip()
                    ->authorizationMessage(fn (Role $record): ?string => self::roles()->roleEditRefusal(auth()->user(), $record))
                    // A built-in role keeps its name whatever the request carries.
                    ->mutateDataUsing(fn (array $data, Role $record): array => (self::roles()->isBuiltIn($record) ? [] : ['name' => $data['name'] ?? $record->name]) + ['guard_name' => 'web'])
                    ->before(fn (Role $record) => RoleAudit::snapshot($record))
                    ->after(function (Role $record): void {
                        app(PermissionRegistrar::class)->forgetCachedPermissions();
                        RoleAudit::recordChanges($record);
                    }),
                // Greyed out with the reason, not hidden: a built-in role, or one that still has users.
                DeleteAction::make()
                    ->disabled(fn (Role $record): bool => self::roles()->isBuiltIn($record) || $record->users()->exists())
                    ->tooltip(fn (Role $record): ?string => match (true) {
                        self::roles()->isBuiltIn($record) => __('A built-in role cannot be deleted.'),
                        $record->users()->exists() => __('The role still has users; take it from them first.'),
                        default => null,
                    })
                    ->before(fn (Role $record) => RoleAudit::snapshot($record))
                    ->after(fn (Role $record) => RoleAudit::recordChanges($record, 'role.deleted')),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageRoles::route('/')];
    }

    private static function roles(): RoleAssignment
    {
        return app(RoleAssignment::class);
    }

    private static function holds(string $permission): bool
    {
        return self::roles()->canGrantPermission(auth()->user(), $permission);
    }

    /**
     * Keys of the permissions the signed-in editor holds, as the checkbox list keys them.
     *
     * @return list<string>
     */
    private static function heldPermissionIds(): array
    {
        return auth()->user()->getAllPermissions()->where('guard_name', 'web')
            ->map(fn (PermissionModel $permission): string => (string) $permission->getKey())->values()->all();
    }
}
