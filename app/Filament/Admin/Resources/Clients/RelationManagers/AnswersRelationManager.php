<?php

namespace App\Filament\Admin\Resources\Clients\RelationManagers;

use App\Filament\Concerns\LimitsSearchInput;
use App\Filament\Concerns\RequiresServedOwner;
use App\Identification\ClientAnswers;
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
    use LimitsSearchInput;
    use RequiresServedOwner;

    protected static string $relationship = 'answers';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Answers');
    }

    public function table(Table $table): Table
    {
        return $table
            // A deleted question keeps its answers (it can be restored): load it anyway, so the tab says why they cannot be used.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['questionEvenDeleted', 'questionVersion']))
            ->columns([
                TextColumn::make('questionVersion.text')->label(__('Question'))->wrap(),
                TextColumn::make('questionVersion.version')->label(__('Version'))->badge(),
                IconColumn::make('usable')->label(__('Usable'))->boolean()
                    ->state(fn (ClientAnswer $record): bool => ClientAnswers::unusableReason($record) === null)
                    ->tooltip(fn (ClientAnswer $record): ?string => ClientAnswers::unusableReason($record)),
                TextColumn::make('unusable_reason')->label(__('Why not usable'))->wrap()->placeholder('-')
                    ->state(fn (ClientAnswer $record): ?string => ClientAnswers::unusableReason($record)),
                TextColumn::make('updated_at')->label(__('Answered'))->since(),
            ])
            ->recordActions([
                DeleteAction::make()->label(__('Remove answer'))
                    ->modalDescription(__('The answer is deleted for good and the helpdesk can no longer ask this question; the client can give a new answer on the portal. The removal is recorded in the audit log.'))
                    ->using(fn (ClientAnswer $record): bool => app(ClientAnswers::class)->remove($this->getOwnerRecord(), $record->questionEvenDeleted, actor: auth('web')->user(), via: 'staff')),
            ]);
    }
}
