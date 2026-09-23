<?php

namespace App\Filament\Admin\Resources\OutboundMessages\Pages;

use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use Filament\Resources\Pages\ManageRecords;

class ManageOutboundMessages extends ManageRecords
{
    protected static string $resource = OutboundMessageResource::class;
}
