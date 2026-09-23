<?php

namespace App\Policies;

use App\Auth\Permission;

class ApiKeyPolicy extends PermissionPolicy
{
    protected Permission $view = Permission::ApiKeysManage;

    protected ?Permission $manage = Permission::ApiKeysManage;
}
