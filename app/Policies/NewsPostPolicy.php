<?php

namespace App\Policies;

use App\Auth\Permission;

class NewsPostPolicy extends PermissionPolicy
{
    protected Permission $view = Permission::NewsManage;

    protected ?Permission $manage = Permission::NewsManage;
}
