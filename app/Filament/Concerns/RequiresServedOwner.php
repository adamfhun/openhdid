<?php

namespace App\Filament\Concerns;

use App\Clients\ClientTiers;
use App\Models\Client;

/**
 * A tab (relation manager) on a client page answers its own requests
 * without the page, so it checks on every request that the user may still
 * open it (403) and that the client is still served (404): premium-only
 * mode may have been switched on since the page was opened.
 *
 * The tabs are lazy: the page sends a placeholder whose snapshot loads the
 * tab later, and on that request Livewire skips the hydrate hooks (and
 * boot with them). So the check is not a hydrate hook: boot covers the
 * mount, the lazy load and every later request, and the updating, call
 * and rendering hooks, which Livewire runs on every request, cover a
 * placeholder snapshot replayed without the lazy load. It does not depend
 * on what the Filament version checks itself.
 */
trait RequiresServedOwner
{
    protected bool $hasCheckedServedOwner = false;

    public function bootRequiresServedOwner(): void
    {
        $this->ensureServedOwner();
    }

    public function updatingRequiresServedOwner(): void
    {
        $this->ensureServedOwner();
    }

    public function callRequiresServedOwner(): void
    {
        $this->ensureServedOwner();
    }

    public function renderingRequiresServedOwner(): void
    {
        $this->ensureServedOwner();
    }

    private function ensureServedOwner(): void
    {
        if ($this->hasCheckedServedOwner) {
            return;
        }

        $owner = $this->getOwnerRecord();

        abort_unless(static::canViewForRecord($owner, $this->pageClass ?? static::class), 403);
        abort_if($owner instanceof Client && ! app(ClientTiers::class)->isServed($owner), 404);

        $this->hasCheckedServedOwner = true;
    }
}
