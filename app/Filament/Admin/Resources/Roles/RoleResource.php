<?php

namespace App\Filament\Admin\Resources\Roles;

use App\Audit\RoleAudit;
use App\Auth\Permission;
use App\Auth\Role as RoleEnum;
use App\Filament\Admin\Clusters\Accounts;
use App\Filament\Admin\Resources\BaseResource;
use App\Filament\Admin\Resources\Roles\Pages\ManageRoles;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

/**
 * Roles and the permissions they grant. The four built-in roles can be
 * re-tuned but not deleted; custom roles can be added freely.
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
                ->disabled(fn (?Role $record) => $record !== null && RoleEnum::tryFrom($record->name) !== null)
                ->dehydrated(),
            Section::make(__('Permissions'))->schema([
                CheckboxList::make('permissions')
                    ->hiddenLabel()
                    ->relationship('permissions', 'name', fn (Builder $query) => $query->where('guard_name', 'web')->orderBy('name'))
                    ->getOptionLabelFromRecordUsing(fn ($record) => __('permission-group.'.Permission::from($record->name)->group()).' · '.Permission::from($record->name)->label())
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
                EditAction::make()->mutateDataUsing(fn (array $data) => ['name' => $data['name'] ?? null] + ['guard_name' => 'web'])
                    ->before(fn (Role $record) => RoleAudit::snapshot($record))
                    ->after(fn (Role $record) => RoleAudit::recordChanges($record)),
                DeleteAction::make()->visible(fn (Role $record) => RoleEnum::tryFrom($record->name) === null && $record->users_count === 0)
                    ->before(fn (Role $record) => RoleAudit::snapshot($record))
                    ->after(fn (Role $record) => RoleAudit::recordChanges($record, 'role.deleted')),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageRoles::route('/')];
    }
}
