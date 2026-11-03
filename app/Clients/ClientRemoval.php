<?php

namespace App\Clients;

use App\Models\Client;
use App\Models\User;

/**
 * An administrator deletes a client: the account is closed with the
 * administrator's reason and signed out everywhere first (a sponsor's
 * linked clients close with it, as on "Close account"), then the row goes
 * to the trash. The sync never brings it back; only a restore by an
 * administrator does, and the account stays closed until reopened.
 */
class ClientRemoval
{
    /**
     * True when the row went to the trash (Filament's delete action reports
     * a failure otherwise).
     */
    public function delete(Client $client, ?User $by = null): bool
    {
        $client->refresh();
        $client->closeForRemoval();

        return (bool) $client->delete();
    }
}
