<?php

namespace App\Policies;

use App\Auth\Permission;

class ClientPhoneNumberPolicy extends PermissionPolicy
{
    protected Permission $view = Permission::ClientsView;

    protected ?Permission $manage = Permission::ClientsManage;
}
