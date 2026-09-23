<?php

namespace App\Filament\Admin\Resources\Questions\Pages;

use App\Filament\Admin\Resources\Questions\QuestionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewQuestion extends ViewRecord
{
    protected static string $resource = QuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [QuestionResource::newVersionAction()];
    }
}
