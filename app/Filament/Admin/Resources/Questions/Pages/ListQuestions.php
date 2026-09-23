<?php

namespace App\Filament\Admin\Resources\Questions\Pages;

use App\Filament\Admin\Resources\Questions\QuestionResource;
use App\Identification\QuestionCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;

class ListQuestions extends ListRecords
{
    protected static string $resource = QuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('New question'))
                ->icon('heroicon-o-plus')
                ->visible(fn () => QuestionResource::canCreate())
                ->schema([
                    TextInput::make('text')->label(__('Question'))->required()->maxLength(500),
                    TextInput::make('hint')->label(__('Hint for the agent'))->maxLength(500),
                    Toggle::make('is_active')->label(__('Active'))->default(true),
                ])
                ->action(fn (array $data) => app(QuestionCatalog::class)->create($data['text'], $data['hint'] ?? null, auth()->user(), (bool) $data['is_active'])),
        ];
    }
}
