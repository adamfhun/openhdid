<?php

namespace App\Policies;

use App\Auth\Permission;
use App\Auth\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * roles.manage covers every role except the SuperAdmin role, which only a
 * SuperAdmin may edit; the built-in roles are never deleted, nor a role that
 * still has users (the page greys the button out with the reason). Spatie's Role
 * is not discovered, the binding is in AppServiceProvider (Gate::policy).
 */
class RolePolicy extends PermissionPolicy
{
    protected Permission $view = Permission::RolesManage;

    protected ?Permission $manage = Permission::RolesManage;

    public function __construct(private readonly RoleAssignment $roles) {}

    public function update(User $user, Model $model): bool
    {
        return parent::update($user, $model) && $model instanceof RoleModel && $this->roles->canEditRole($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model) && $model instanceof RoleModel
            && ! $this->roles->isBuiltIn($model) && $this->roles->canEditRole($user, $model)
            && ! $model->users()->exists();
    }
}
