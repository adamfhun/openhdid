<?php

namespace App\Policies;

use App\Auth\Permission;

/**
 * Removing an answer takes away one of the client's means of identification,
 * so it belongs to client management; reading which questions are answered
 * goes with viewing the client.
 */
class ClientAnswerPolicy extends PermissionPolicy
{
    protected Permission $view = Permission::ClientsView;

    protected ?Permission $manage = Permission::ClientsManage;
}
