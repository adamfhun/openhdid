<?php

namespace App\Filament\Admin\Resources\NewsPosts;

use App\Filament\Admin\Clusters\Content;
use App\Filament\Admin\Resources\BaseResource;
use App\Filament\Admin\Resources\NewsPosts\Pages\ManageNewsPosts;
use App\Models\NewsPost;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * News shown to clients on the portal. Clients see the title, the body and
 * the publication date; the author stays internal.
 */
class NewsPostResource extends BaseResource
{
    protected static ?string $model = NewsPost::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?string $cluster = Content::class;

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return __('news item');
    }

    public static function getPluralModelLabel(): string
    {
        return __('News');
    }

    public static function getNavigationLabel(): string
    {
        return __('News');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label(__('Title'))->required()->maxLength(255)->columnSpanFull(),
            MarkdownEditor::make('body')->label(__('Body'))->required()->columnSpanFull()
                ->toolbarButtons(['bold', 'italic', 'link', 'bulletList', 'orderedList', 'heading', 'blockquote']),
            DateTimePicker::make('published_at')->label(__('Published at'))->seconds(false)
                ->helperText(__('Leave empty to keep it as a draft; a future time schedules it.')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('author'))
            ->columns([
                TextColumn::make('title')->label(__('Title'))->searchable()->sortable()->wrap(),
                TextColumn::make('published_at')->label(__('Published at'))->dateTime()->sortable()->placeholder(__('draft'))
                    ->badge()->color(fn (NewsPost $record) => $record->isPublished() ? 'success' : ($record->published_at ? 'warning' : 'gray')),
                TextColumn::make('author.name')->label(__('Author'))->placeholder('-'),
                TextColumn::make('updated_at')->label(__('Updated'))->since(),
            ])
            ->filters([
                TernaryFilter::make('published')->label(__('Published'))->queries(
                    true: fn (Builder $query) => $query->published(),
                    false: fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '>', now())),
                ),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make(), RestoreAction::make()])
            ->defaultSort('published_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageNewsPosts::route('/')];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
