<?php

namespace App\Policies;

use App\Auth\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps the standard resource abilities onto one "view" and one "manage"
 * permission. A null manage permission makes the resource read-only.
 */
abstract class PermissionPolicy
{
    protected Permission $view;

    protected ?Permission $manage = null;

    public function viewAny(User $user): bool
    {
        return $user->can($this->view->value);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->manage !== null && $user->can($this->manage->value);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->create($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->create($user);
    }

    public function restore(User $user, Model $model): bool
    {
        return $this->create($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->create($user);
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
