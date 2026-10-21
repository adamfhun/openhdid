<?php

namespace App\Policies;

use App\Auth\Permission;

/**
 * The EMD change records carry names and e-mail addresses from the directory,
 * like the runs they belong to: the sync managers read them, nobody edits.
 */
class SyncRunChangePolicy extends PermissionPolicy
{
    protected Permission $view = Permission::SyncManage;
}
