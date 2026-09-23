<?php

namespace App\Filament\Admin\Resources\Clients\RelationManagers;

use App\Filament\Admin\Resources\IdSessions\IdSessionResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class IdSessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'idSessions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Identification sessions');
    }

    public function table(Table $table): Table
    {
        return IdSessionResource::table($table)->defaultSort('id', 'desc');
    }

    /**
     * The eye icon opens the same details as the Identification sessions page;
     * without this the modal would be empty.
     */
    public function infolist(Schema $schema): Schema
    {
        return IdSessionResource::infolist($schema);
    }
}
