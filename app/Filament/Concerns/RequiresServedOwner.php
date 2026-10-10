<?php

namespace App\Filament\Concerns;

use App\Clients\ClientTiers;
use App\Models\Client;

/**
 * A relation manager on a client page answers its own requests without the
 * page, so it checks on every request that the client is still served
 * (premium-only mode may have been switched on since the page was opened).
 */
trait RequiresServedOwner
{
    public function hydrateRequiresServedOwner(): void
    {
        $owner = $this->getOwnerRecord();

        abort_if($owner instanceof Client && ! app(ClientTiers::class)->isServed($owner), 404);
    }
}
