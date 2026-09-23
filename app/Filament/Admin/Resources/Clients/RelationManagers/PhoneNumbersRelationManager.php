<?php

namespace App\Filament\Admin\Resources\Clients\RelationManagers;

use App\Enums\PhoneNumberSource;
use App\Models\ClientPhoneNumber;
use App\Support\PhoneNormalizer;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

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
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (app(PhoneNormalizer::class)->normalize($value) === null) {
                        $fail(__('This is not a valid phone number.'));
                    }
                })
                ->dehydrateStateUsing(fn (string $state): string => app(PhoneNormalizer::class)->normalize($state) ?? $state),
            TextInput::make('label')->label(__('Label'))->maxLength(50),
            Toggle::make('is_primary')->label(__('Primary'))->helperText(__('Only one number can be primary; switching it on here removes the flag from the others.')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number_e164')
            ->columns([
                TextColumn::make('number_e164')->label(__('Number'))->copyable(),
                TextColumn::make('label')->label(__('Label'))->placeholder('-'),
                TextColumn::make('source')->label(__('Source'))->badge()->formatStateUsing(fn (PhoneNumberSource $state): string => $state->label()),
                IconColumn::make('is_primary')->label(__('Primary'))->boolean(),
                IconColumn::make('verified_at')->label(__('Verified'))->boolean()->state(fn ($record) => $record->verified_at !== null),
            ])
            ->headerActions([
                CreateAction::make()->mutateDataUsing(fn (array $data): array => $data + ['source' => PhoneNumberSource::Admin, 'verified_at' => now()]),
            ])
            ->recordActions([
                // A custom action carries no policy check of its own: without
                // authorize() Filament allows it, and the primary number is
                // where the login code and the PIN text message go.
                Action::make('makePrimary')->label(__('Make primary'))->icon('heroicon-o-star')
                    ->authorize(fn (ClientPhoneNumber $record) => auth()->user()?->can('update', $record) ?? false)
                    ->visible(fn (ClientPhoneNumber $record) => ! $record->is_primary)
                    ->action(fn (ClientPhoneNumber $record) => $record->makePrimary()),
                EditAction::make()->visible(fn ($record) => $record->source !== PhoneNumberSource::Sync),
                DeleteAction::make()->visible(fn ($record) => $record->source !== PhoneNumberSource::Sync),
            ]);
    }
}
