<?php

namespace App\Filament\Admin\Widgets\Concerns;

/**
 * A widget answers its own requests without the page, so it asks canView()
 * on every one (403): a user who lost the permission while the page stayed
 * open gets nothing more from it.
 *
 * Most widgets are lazy: the page sends a placeholder whose snapshot loads
 * the widget later, and on that request Livewire skips the hydrate hooks
 * (and boot with them). Filament 5.9 checked only in a hydrate hook. So
 * the check is not a hydrate hook: boot covers the mount, the lazy load
 * and every later request, and the updating, call and rendering hooks,
 * which Livewire runs on every request, cover a placeholder snapshot
 * replayed without the lazy load. It does not depend on what the Filament
 * version checks itself.
 */
trait AuthorizesEveryRequest
{
    protected bool $hasCheckedWidgetAccess = false;

    public function bootAuthorizesEveryRequest(): void
    {
        $this->ensureWidgetAccess();
    }

    public function updatingAuthorizesEveryRequest(): void
    {
        $this->ensureWidgetAccess();
    }

    public function callAuthorizesEveryRequest(): void
    {
        $this->ensureWidgetAccess();
    }

    public function renderingAuthorizesEveryRequest(): void
    {
        $this->ensureWidgetAccess();
    }

    private function ensureWidgetAccess(): void
    {
        if ($this->hasCheckedWidgetAccess) {
            return;
        }

        abort_unless(static::canView(), 403);

        $this->hasCheckedWidgetAccess = true;
    }
}
