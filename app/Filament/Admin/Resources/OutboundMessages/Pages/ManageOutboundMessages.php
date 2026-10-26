<?php

namespace App\Filament\Admin\Resources\OutboundMessages\Pages;

use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use App\Filament\Concerns\LimitsSearchInput;
use Filament\Resources\Pages\ManageRecords;

class ManageOutboundMessages extends ManageRecords
{
    use LimitsSearchInput;

    protected static string $resource = OutboundMessageResource::class;
}
