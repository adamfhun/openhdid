<?php

namespace App\Filament\Admin\Resources\Questions\RelationManagers;

use App\Models\QuestionVersion;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Version history');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('publishedBy')->withCount('answers'))
            ->columns([
                TextColumn::make('version')->label(__('Version'))->badge()
                    ->color(fn (QuestionVersion $record) => $record->id === $this->getOwnerRecord()->current_version_id ? 'success' : 'gray'),
                TextColumn::make('text')->label(__('Question'))->wrap(),
                TextColumn::make('hint')->label(__('Hint'))->placeholder('-')->wrap(),
                IconColumn::make('keeps_answers')->label(__('Kept answers'))->boolean(),
                TextColumn::make('answers_count')->label(__('Answers on this version'))->badge(),
                TextColumn::make('publishedBy.name')->label(__('Published by'))->placeholder('-'),
                TextColumn::make('created_at')->label(__('Published'))->dateTime(),
            ])
            ->defaultSort('version', 'desc');
    }
}
