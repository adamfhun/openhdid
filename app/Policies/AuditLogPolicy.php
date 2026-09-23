<?php

namespace App\Policies;

use App\Auth\Permission;

class AuditLogPolicy extends PermissionPolicy
{
    protected Permission $view = Permission::AuditView;
}
