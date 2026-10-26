<?php

namespace App\Filament\Admin\Resources\ApiKeys\Pages;

use App\Enums\ApiKeyScope;
use App\Filament\Admin\Resources\ApiKeys\ApiKeyResource;
use App\Filament\Concerns\LimitsSearchInput;
use App\Models\ApiKey;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

class ManageApiKeys extends ManageRecords
{
    use LimitsSearchInput;

    protected static string $resource = ApiKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->documentationLinks(),
            Action::make('create')
                ->label(__('New API key'))
                ->icon('heroicon-o-plus')
                ->schema([
                    TextInput::make('name')->label(__('Name'))->required()->maxLength(100)->placeholder(__('e.g. IVR production')),
                    Select::make('scope')->label(__('Scope'))->required()
                        ->options(collect(ApiKeyScope::cases())->mapWithKeys(fn (ApiKeyScope $s) => [$s->value => $s->label()])->all())
                        ->helperText(__('A mobile app backend key comes with its signing secret: every request made with it has to be signed.').' '.__('The interface document of each scope opens from the "API documentation" button above.')),
                ])
                ->action(function (array $data): void {
                    ['plain' => $plain, 'secret' => $secret] = ApiKey::generate($data['name'], ApiKeyScope::from($data['scope']), auth()->user());

                    $body = '<span class="text-xs">'.__('Copy it now, it will not be shown again:').'</span><br><code class="break-all text-xs select-all">'.e($plain).'</code>';

                    if ($secret !== null) {
                        $body .= '<br><span class="text-xs">'.__('Signing secret (shown once as well; hand it over together with the key):').'</span><br><code class="break-all text-xs select-all">'.e($secret).'</code>';
                    }

                    Notification::make()
                        ->title($secret === null ? __('API key created') : __('API key and signing secret created'))
                        ->body(new HtmlString($body))
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }

    /**
     * The interface documents of the partners, each in a new tab, for whoever
     * may read them (the same gate as the documents themselves).
     */
    private function documentationLinks(): ActionGroup
    {
        $links = array_map(fn (ApiKeyScope $scope): Action => Action::make('docs_'.$scope->value)
            ->label(__(':scope interface', ['scope' => $scope->label()]))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->url(route($scope->docsRoute()), shouldOpenInNewTab: true), ApiKeyScope::cases());

        $links[] = Action::make('docs_all')
            ->label(__('Complete API (client portal, sign-in, all partners)'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->url(route('scramble.docs.ui'), shouldOpenInNewTab: true);

        return ActionGroup::make($links)
            ->label(__('API documentation'))
            ->icon('heroicon-o-book-open')
            ->color('gray')
            ->button()
            ->visible(fn (): bool => Gate::allows('viewApiDocs'));
    }
}
