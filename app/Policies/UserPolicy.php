<?php

namespace App\Policies;

use App\Auth\Permission;

class UserPolicy extends PermissionPolicy
{
    protected Permission $view = Permission::UsersManage;

    protected ?Permission $manage = Permission::UsersManage;
}
