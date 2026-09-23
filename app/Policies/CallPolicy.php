<?php

namespace App\Policies;

use App\Auth\Permission;

class CallPolicy extends PermissionPolicy
{
    protected Permission $view = Permission::CallsView;
}
