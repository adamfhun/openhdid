<?php

namespace App\Policies;

use App\Auth\Permission;

class ExternalRecordPolicy extends PermissionPolicy
{
    protected Permission $view = Permission::ExternalRecordsView;
}
