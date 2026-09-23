<?php

namespace App\Localization;

use App\Models\Client;
use Illuminate\Auth\Events\Authenticated;

/**
 * Keeps the client's preferred language on the account, so an e-mail or
 * SMS sent later (by an agent, by the IVR flow) is in the client's language
 * and not in the sender's. Fires on every authenticated request; writes
 * only when the language actually changed.
 */
class RememberClientLocale
{
    public function handle(Authenticated $event): void
    {
        $client = $event->user;

        if (! $client instanceof Client) {
            return;
        }

        $locale = app()->getLocale();

        if (! in_array($locale, SetLocale::SUPPORTED, true) || $client->locale === $locale) {
            return;
        }

        $client->forceFill(['locale' => $locale])->saveQuietly();
    }
}
