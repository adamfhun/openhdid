<?php

namespace App\Filament\Admin\Resources\ApiKeys\Pages;

use App\Enums\ApiKeyScope;
use App\Filament\Admin\Resources\ApiKeys\ApiKeyResource;
use App\Models\ApiKey;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\HtmlString;

class ManageApiKeys extends ManageRecords
{
    protected static string $resource = ApiKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('New API key'))
                ->icon('heroicon-o-plus')
                ->schema([
                    TextInput::make('name')->label(__('Name'))->required()->maxLength(100)->placeholder(__('e.g. IVR production')),
                    Select::make('scope')->label(__('Scope'))->required()
                        ->options(collect(ApiKeyScope::cases())->mapWithKeys(fn (ApiKeyScope $s) => [$s->value => $s->label()])->all()),
                ])
                ->action(function (array $data): void {
                    ['plain' => $plain] = ApiKey::generate($data['name'], ApiKeyScope::from($data['scope']), auth()->user());

                    Notification::make()
                        ->title(__('API key created'))
                        ->body(new HtmlString('<span class="text-xs">'.__('Copy it now, it will not be shown again:').'</span><br><code class="break-all text-xs select-all">'.e($plain).'</code>'))
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
