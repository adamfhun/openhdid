<?php

namespace App\Filament\Admin\Resources\ApiKeys;

use App\Enums\ApiKeyScope;
use App\Filament\Admin\Clusters\System;
use App\Filament\Admin\Resources\ApiKeys\Pages\ManageApiKeys;
use App\Filament\Admin\Resources\BaseResource;
use App\Models\ApiKey;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Long-lived keys for the call center / IVR and the mobile app backend.
 * The secret is displayed once, right after creation.
 */
class ApiKeyResource extends BaseResource
{
    protected static ?string $model = ApiKey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $cluster = System::class;

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return __('API key');
    }

    public static function getPluralModelLabel(): string
    {
        return __('API keys');
    }

    public static function getNavigationLabel(): string
    {
        return __('API keys');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->weight('semibold'),
                TextColumn::make('scope')->label(__('Scope'))->badge()->formatStateUsing(fn (ApiKeyScope $state) => $state->label()),
                TextColumn::make('key_prefix')->label(__('Key'))->formatStateUsing(fn (string $state) => $state.'…')->fontFamily('mono'),
                TextColumn::make('hmac_secret')->label(__('Signature'))->badge()
                    ->state(fn (ApiKey $record) => $record->requiresSignature() ? __('Required') : __('None'))
                    ->color(fn (ApiKey $record) => $record->requiresSignature() ? 'success' : 'gray'),
                TextColumn::make('last_used_at')->label(__('Last used'))->since()->placeholder(__('never')),
                TextColumn::make('createdBy.name')->label(__('Created by'))->placeholder('-')->toggleable(),
                TextColumn::make('created_at')->label(__('Created'))->dateTime()->toggleable(),
                TextColumn::make('revoked_at')->label(__('Status'))->badge()
                    ->state(fn (ApiKey $record) => $record->isRevoked() ? __('Revoked') : __('Active'))
                    ->color(fn (ApiKey $record) => $record->isRevoked() ? 'danger' : 'success'),
            ])
            ->filters([
                SelectFilter::make('scope')->label(__('Scope'))->options(collect(ApiKeyScope::cases())->mapWithKeys(fn (ApiKeyScope $s) => [$s->value => $s->label()])->all()),
            ])
            ->recordActions([
                Action::make('rotateSecret')->label(__('Signing secret'))->icon('heroicon-o-finger-print')->color('gray')
                    ->modalHeading(__('Issue a new signing secret'))
                    ->modalDescription(__('Every request with this key must then carry X-Timestamp and X-Signature (HMAC-SHA256 of "timestamp, method, request URI and body, joined by newlines"); a signature is accepted once. The partner has to switch at the same time.'))
                    ->requiresConfirmation()
                    ->visible(fn (ApiKey $record) => ! $record->isRevoked())
                    ->action(function (ApiKey $record): void {
                        $secret = $record->rotateSigningSecret();

                        Notification::make()
                            ->title(__('Signing secret issued'))
                            ->body(new HtmlString('<span class="text-xs">'.__('Copy it now, it will not be shown again:').'</span><br><code class="break-all text-xs select-all">'.e($secret).'</code>'))
                            ->success()
                            ->persistent()
                            ->send();
                    }),
                Action::make('removeSecret')->label(__('Remove signature'))->icon('heroicon-o-lock-open')->color('warning')->requiresConfirmation()
                    ->visible(fn (ApiKey $record) => ! $record->isRevoked() && $record->requiresSignature())
                    ->action(fn (ApiKey $record) => $record->removeSigningSecret()),
                Action::make('revoke')->label(__('Revoke'))->icon('heroicon-o-no-symbol')->color('danger')->requiresConfirmation()
                    ->visible(fn (ApiKey $record) => ! $record->isRevoked())
                    ->action(fn (ApiKey $record) => $record->revoke()),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageApiKeys::route('/')];
    }
}
