<?php

namespace App\Policies;

use App\Auth\Permission;

class OutboundMessagePolicy extends PermissionPolicy
{
    protected Permission $view = Permission::AuditView;

    protected ?Permission $manage = Permission::SettingsManage;
}
