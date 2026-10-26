<?php

namespace App\Filament\Admin\Resources\MessageTemplates\Pages;

use App\Filament\Admin\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Concerns\LimitsSearchInput;
use Filament\Resources\Pages\ListRecords;

class ListMessageTemplates extends ListRecords
{
    use LimitsSearchInput;

    protected static string $resource = MessageTemplateResource::class;

    public function mount(): void
    {
        MessageTemplateResource::ensureRows();

        parent::mount();
    }
}
