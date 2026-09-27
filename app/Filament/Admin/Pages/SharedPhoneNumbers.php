<?php

namespace App\Filament\Admin\Pages;

use App\Auth\Permission;
use App\Clients\ClientPhones;
use App\Enums\PhoneNumberSource;
use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Models\ClientPhoneNumber;
use App\Support\HuDate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * Phone numbers on file for more than one open client. From such a number
 * the phone menu recognises nobody, so the PIN check never happens there:
 * a service degradation the client managers can sort out here.
 */
class SharedPhoneNumbers extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoneXMark;

    protected static ?int $navigationSort = 3;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Helpdesk;

    protected static ?string $slug = 'shared-phone-numbers';

    protected string $view = 'filament.admin.shared-phone-numbers';

    public static function getNavigationLabel(): string
    {
        return __('Shared phone numbers');
    }

    public function getTitle(): string
    {
        return __('Shared phone numbers');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ClientsManage->value) ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Cache::remember('clients:shared-phone-number-count', 60, fn (): int => app(ClientPhones::class)->sharedNumberCount());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'gray';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Phone numbers on file for more than one client');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(ClientPhones::class)->sharedNumbersQuery()->with(['client.externalRecord', 'verifiedBy']))
            ->defaultGroup(Group::make('number_e164')->label(__('Number'))->titlePrefixedWithLabel(false))
            ->groupingSettingsHidden()
            ->defaultSort('number_e164')
            ->emptyStateHeading(__('No shared phone numbers'))
            ->emptyStateDescription(__('Every registered number belongs to one client, so the phone menu can recognise each caller.'))
            ->columns([
                TextColumn::make('client.name')->label(__('Client'))->weight('semibold')->searchable()
                    ->description(fn (ClientPhoneNumber $record) => $record->client->email)
                    ->url(fn (ClientPhoneNumber $record) => ClientResource::getUrl('view', ['record' => $record->client])),
                TextColumn::make('client.externalRecord.company')->label(__('Company'))->placeholder('-'),
                TextColumn::make('source')->label(__('Source'))->badge()
                    ->formatStateUsing(fn (PhoneNumberSource $state): string => $state->label())
                    ->color(fn (PhoneNumberSource $state): string => $state === PhoneNumberSource::Sync ? 'danger' : 'gray')
                    ->tooltip(fn (ClientPhoneNumber $record): ?string => $record->source === PhoneNumberSource::Sync ? __('Comes from Enterprise Master Data: the directory itself lists this number for several people, so it has to be corrected there; the next sync applies the correction.') : null),
                TextColumn::make('verified_at')->label(__('Verification'))->badge()
                    ->state(fn (ClientPhoneNumber $record): string => $record->verificationLabel())
                    ->color(fn (ClientPhoneNumber $record): string => $record->isVerified() ? 'success' : 'warning')
                    ->tooltip(fn (ClientPhoneNumber $record): ?string => $record->isVerified() ? $record->verified_at->format(HuDate::DATETIME) : null),
                IconColumn::make('is_primary')->label(__('Primary'))->boolean(),
                TextColumn::make('created_at')->label(__('Added'))->dateTime(),
            ])
            ->recordActions([
                Action::make('open')->label(__('Open client'))->icon('heroicon-o-user')->color('gray')
                    ->url(fn (ClientPhoneNumber $record) => ClientResource::getUrl('view', ['record' => $record->client])),
                DeleteAction::make()->label(__('Remove number'))
                    ->visible(fn (ClientPhoneNumber $record) => $record->source !== PhoneNumberSource::Sync)
                    ->modalDescription(fn (ClientPhoneNumber $record) => __('The number is removed from :name only. If it was their last registered number, their PIN is removed too.', ['name' => $record->client->name])),
            ]);
    }
}
