<?php

namespace App\Policies;

use App\Auth\Permission;
use App\Clients\ClientTiers;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Clients by permission, and only those the system serves: in premium-only
 * mode a standard client is out of reach, even on a page opened before the
 * switch (Filament asks again on every request of a record page). A client's
 * phone numbers and answers follow the same rule through their client.
 */
class ClientPolicy extends PermissionPolicy
{
    protected Permission $view = Permission::ClientsView;

    protected ?Permission $manage = Permission::ClientsManage;

    /**
     * A phone number is created for a client: the phone numbers tab passes
     * that client, and one the system does not serve takes none. Without a
     * client (a new client) the permission alone decides. The answers tab
     * has no create action; linking a client asks update on the sponsor.
     */
    public function create(User $user, ?Client $client = null): bool
    {
        return parent::create($user) && ($client === null || $this->served($client));
    }

    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) && $this->served($model);
    }

    public function update(User $user, Model $model): bool
    {
        return parent::update($user, $model) && $this->served($model);
    }

    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model) && $this->served($model);
    }

    public function restore(User $user, Model $model): bool
    {
        return parent::restore($user, $model) && $this->served($model);
    }

    private function served(Model $model): bool
    {
        $client = $model instanceof Client ? $model : $model->getAttribute('client');

        return ! $client instanceof Client || app(ClientTiers::class)->isServed($client);
    }
}
