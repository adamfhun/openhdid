<?php

namespace App\Policies;

use App\Auth\Permission;

class RolePolicy extends PermissionPolicy
{
    protected Permission $view = Permission::RolesManage;

    protected ?Permission $manage = Permission::RolesManage;
}
