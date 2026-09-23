<?php

namespace App\Filament\Admin\Resources\Clients\RelationManagers;

use App\Models\ClientAnswer;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Admins see which questions are answered, never the answers themselves.
 */
class AnswersRelationManager extends RelationManager
{
    protected static string $relationship = 'answers';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Answers');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['question.currentVersion', 'questionVersion']))
            ->columns([
                TextColumn::make('questionVersion.text')->label(__('Question'))->wrap(),
                TextColumn::make('questionVersion.version')->label(__('Version'))->badge(),
                IconColumn::make('usable')->label(__('Usable'))->boolean()
                    ->state(fn (ClientAnswer $record) => $record->question->is_active && $record->question->current_version_id === $record->question_version_id),
                TextColumn::make('updated_at')->label(__('Answered'))->since(),
            ])
            ->recordActions([
                DeleteAction::make()->label(__('Remove answer')),
            ]);
    }
}
