<?php

namespace App\Policies;

use App\Auth\Permission;

class IdSessionPolicy extends PermissionPolicy
{
    protected Permission $view = Permission::IdentificationView;
}
