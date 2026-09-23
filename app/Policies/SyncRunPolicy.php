<?php

namespace App\Policies;

use App\Auth\Permission;

class SyncRunPolicy extends PermissionPolicy
{
    protected Permission $view = Permission::SyncManage;

    protected ?Permission $manage = Permission::SyncManage;
}
