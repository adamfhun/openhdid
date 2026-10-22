<?php

namespace App\Policies;

use App\Auth\Permission;

class QueuedJobPolicy extends PermissionPolicy
{
    protected Permission $view = Permission::SettingsManage;

    protected ?Permission $manage = Permission::SettingsManage;
}
