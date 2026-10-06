<?php

namespace App\Filament\Admin\Resources\Clients\RelationManagers;

use App\Clients\ClientPhones;
use App\Enums\PhoneNumberSource;
use App\Enums\PhoneVerificationSource;
use App\Models\Client;
use App\Models\ClientPhoneNumber;
use App\Support\HuDate;
use App\Support\PhoneNormalizer;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The numbers the phone menu recognises the caller by. A number that
 * another client also carries recognises nobody, so adding one asks the
 * operator to acknowledge that; every number shows what verified it.
 */
class PhoneNumbersRelationManager extends RelationManager
{
    protected static string $relationship = 'phoneNumbers';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Phone numbers');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('number_e164')
                ->label(__('Phone number'))
                ->required()
                ->live(onBlur: true)
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (app(PhoneNormalizer::class)->normalize($value) === null) {
                        $fail(__('This is not a valid phone number.'));
                    }
                })
                ->dehydrateStateUsing(fn (string $state): string => app(PhoneNormalizer::class)->normalize($state) ?? $state),
            Text::make(fn (Get $get): string => __('This number is already on file for :names. If you continue, the phone menu will not recognise the caller from this number, so the PIN check there will not work for any of these clients.', ['names' => $this->otherHolders($get('number_e164'))->pluck('name')->implode(', ')]))
                ->color('warning')
                ->weight('semibold')
                ->visible(fn (Get $get): bool => $this->otherHolders($get('number_e164'))->isNotEmpty()),
            Checkbox::make('acknowledge_shared')
                ->label(__('I understand: from this number the PIN check in the phone menu will not work'))
                ->visible(fn (Get $get): bool => $this->otherHolders($get('number_e164'))->isNotEmpty())
                ->required(fn (Get $get): bool => $this->otherHolders($get('number_e164'))->isNotEmpty())
                ->accepted()
                ->dehydrated(false),
            TextInput::make('label')->label(__('Label'))->maxLength(50),
            Toggle::make('is_primary')->label(__('Primary'))->helperText(__('Only one number can be primary; switching it on here removes the flag from the others.')),
        ]);
    }

    public function table(Table $table): Table
    {
        $phones = app(ClientPhones::class);

        return $table
            ->recordTitleAttribute('number_e164')
            ->modelLabel(__('phone number'))
            ->pluralModelLabel(__('phone numbers'))
            ->modifyQueryUsing(fn ($query) => $query->with('verifiedBy'))
            ->columns([
                TextColumn::make('number_e164')->label(__('Number'))->copyable(),
                TextColumn::make('label')->label(__('Label'))->placeholder('-'),
                TextColumn::make('source')->label(__('Source'))->badge()->formatStateUsing(fn (PhoneNumberSource $state): string => $state->label()),
                IconColumn::make('is_primary')->label(__('Primary'))->boolean(),
                TextColumn::make('verified_at')->label(__('Verification'))->badge()
                    ->state(fn (ClientPhoneNumber $record): string => $record->verificationLabel())
                    ->color(fn (ClientPhoneNumber $record): string => $record->isVerified() ? 'success' : 'warning')
                    ->tooltip(fn (ClientPhoneNumber $record): ?string => $record->isVerified()
                        ? trim($record->verified_at->format(HuDate::DATETIME).' '.($record->verifiedBy?->name ?? ''))
                        : __('Ranks below verified numbers when a caller is matched. Confirm it here, or the client confirms it by SMS code when that is switched on.')),
                TextColumn::make('shared')->label(__('Shared'))->badge()->color('warning')
                    ->state(fn (ClientPhoneNumber $record): ?string => $phones->isShared($record) ? __('shared') : null)
                    ->placeholder('-')
                    ->tooltip(fn (ClientPhoneNumber $record): ?string => $phones->isShared($record) ? __('Also on file for another client: the phone menu cannot recognise the caller from this number, so no PIN is asked there. See the Shared phone numbers page.') : null),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => $data + ['source' => PhoneNumberSource::Admin, 'verified_at' => now(), 'verified_via' => PhoneVerificationSource::Admin, 'verified_by_user_id' => auth()->id()])
                    ->after(fn (ClientPhoneNumber $record) => $this->recordSharedAcknowledgement($record)),
            ])
            ->recordActions([
                // A custom action carries no policy check of its own: without
                // authorize() Filament allows it, and the primary number is
                // where the login code and the PIN text message go.
                Action::make('makePrimary')->label(__('Make primary'))->icon('heroicon-o-star')
                    ->authorize(fn (ClientPhoneNumber $record) => auth()->user()?->can('update', $record) ?? false)
                    ->visible(fn (ClientPhoneNumber $record) => ! $record->is_primary)
                    ->action(fn (ClientPhoneNumber $record) => $record->makePrimary()),
                Action::make('confirm')->label(__('Confirm number'))->icon('heroicon-o-check-badge')->color('success')
                    ->authorize(fn (ClientPhoneNumber $record) => auth()->user()?->can('update', $record) ?? false)
                    ->visible(fn (ClientPhoneNumber $record) => ! $record->isVerified())
                    ->requiresConfirmation()
                    ->modalHeading(fn (ClientPhoneNumber $record) => __('Confirm :number as the client\'s number?', ['number' => $record->number_e164]))
                    ->modalDescription(__('Do this when you have made sure the number is the client\'s (for example after a call back to it). A verified number ranks first when a caller is matched; the confirmation is written to the audit log with your name.'))
                    ->modalSubmitActionLabel(__('Confirm number'))
                    ->action(fn (ClientPhoneNumber $record) => $phones->verify($record, PhoneVerificationSource::Staff, auth()->user())),
                EditAction::make()->visible(fn ($record) => $record->source !== PhoneNumberSource::Sync)
                    // A changed number is a new number: the old one's SMS or
                    // call confirmation does not carry over; the operator
                    // vouches for it, as on create.
                    ->mutateDataUsing(fn (array $data, ClientPhoneNumber $record): array => ($data['number_e164'] ?? $record->number_e164) === $record->number_e164
                        ? $data
                        : $data + ['verified_at' => now(), 'verified_via' => PhoneVerificationSource::Admin, 'verified_by_user_id' => auth()->id()])
                    ->after(fn (ClientPhoneNumber $record) => $this->recordSharedAcknowledgement($record)),
                DeleteAction::make()->visible(fn ($record) => $record->source !== PhoneNumberSource::Sync)
                    ->modalDescription(fn (ClientPhoneNumber $record) => $record->client->hasPin() && $record->client->phoneNumbers()->count() === 1
                        ? __('This is the client\'s last registered number. Removing it also removes the PIN, because the phone menu could no longer use it.')
                        : null),
            ]);
    }

    /**
     * Open clients other than this one that carry the number typed so far.
     *
     * @return Collection<int, Client>
     */
    private function otherHolders(mixed $number): Collection
    {
        $e164 = is_string($number) ? app(PhoneNormalizer::class)->normalize($number) : null;

        if ($e164 === null) {
            return new Collection;
        }

        /** @var Client $owner */
        $owner = $this->getOwnerRecord();

        return app(ClientPhones::class)->otherHolders($e164, $owner);
    }

    private function recordSharedAcknowledgement(ClientPhoneNumber $record): void
    {
        $holders = $this->otherHolders($record->number_e164);

        if ($holders->isNotEmpty()) {
            app(ClientPhones::class)->recordSharedAcknowledgement($record, $holders, auth()->user());
        }
    }
}
