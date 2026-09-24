<?php

namespace App\Policies;

use App\Auth\Permission;
use App\Auth\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * users.manage covers every account except a SuperAdmin's: that one only
 * another SuperAdmin may edit, close, delete or restore.
 */
class UserPolicy extends PermissionPolicy
{
    protected Permission $view = Permission::UsersManage;

    protected ?Permission $manage = Permission::UsersManage;

    public function __construct(private readonly RoleAssignment $roles) {}

    public function update(User $user, Model $model): bool
    {
        return parent::update($user, $model) && $this->canManage($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model) && $this->canManage($user, $model);
    }

    public function restore(User $user, Model $model): bool
    {
        return parent::restore($user, $model) && $this->canManage($user, $model);
    }

    private function canManage(User $user, Model $model): bool
    {
        return $model instanceof User && $this->roles->canManage($user, $model);
    }
}
