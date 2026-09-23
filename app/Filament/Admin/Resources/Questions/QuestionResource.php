<?php

namespace App\Filament\Admin\Resources\Questions;

use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Resources\BaseResource;
use App\Filament\Admin\Resources\Questions\Pages\ListQuestions;
use App\Filament\Admin\Resources\Questions\Pages\ViewQuestion;
use App\Filament\Admin\Resources\Questions\RelationManagers\VersionsRelationManager;
use App\Identification\QuestionCatalog;
use App\Models\Question;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class QuestionResource extends BaseResource
{
    protected static ?string $model = Question::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Identification;

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return __('question');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Questions');
    }

    public static function getNavigationLabel(): string
    {
        return __('Questions');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Current wording'))->columns(3)->schema([
                TextEntry::make('currentVersion.text')->label(__('Question'))->columnSpan(2),
                TextEntry::make('currentVersion.version')->label(__('Version'))->badge(),
                TextEntry::make('currentVersion.hint')->label(__('Hint for the agent'))->placeholder('-')->columnSpan(2),
                IconEntry::make('is_active')->label(__('Active'))->boolean(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('currentVersion')->withCount('answers'))
            ->reorderable('position')
            ->defaultSort('position')
            ->columns([
                TextColumn::make('currentVersion.text')->label(__('Question'))->wrap()->searchable(),
                TextColumn::make('currentVersion.version')->label(__('Version'))->badge(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                TextColumn::make('answers_count')->label(__('Answers'))->badge(),
                TextColumn::make('updated_at')->label(__('Updated'))->since(),
            ])
            ->filters([TrashedFilter::make()])
            ->recordActions([
                ViewAction::make(),
                static::newVersionAction(),
                Action::make('toggleActive')
                    ->label(fn (Question $record) => $record->is_active ? __('Deactivate') : __('Activate'))
                    ->icon(fn (Question $record) => $record->is_active ? 'heroicon-o-pause' : 'heroicon-o-play')
                    ->color('gray')
                    ->visible(fn (Question $record) => static::canEdit($record))
                    ->action(fn (Question $record) => $record->update(['is_active' => ! $record->is_active])),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }

    public static function newVersionAction(): Action
    {
        return Action::make('newVersion')
            ->label(__('New version'))
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (Question $record) => static::canEdit($record))
            ->modalHeading(__('Publish a new wording'))
            ->fillForm(fn (Question $record): array => [
                'text' => $record->currentVersion?->text,
                'hint' => $record->currentVersion?->hint,
                'keeps_answers' => true,
            ])
            ->schema([
                TextInput::make('text')->label(__('Question'))->required()->maxLength(500),
                TextInput::make('hint')->label(__('Hint for the agent'))->maxLength(500),
                Toggle::make('keeps_answers')
                    ->label(__('Existing answers stay valid'))
                    ->helperText(__('Keep on for a typo fix. Switch off when the meaning changed: clients will have to answer this question again.')),
            ])
            ->action(function (Question $record, array $data): void {
                $version = app(QuestionCatalog::class)->publishVersion($record, $data['text'], $data['hint'] ?? null, (bool) $data['keeps_answers'], auth()->user());
                Notification::make()->title(__('Version :v published', ['v' => $version->version]))->success()->send();
            });
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuestions::route('/'),
            'view' => ViewQuestion::route('/{record}'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
