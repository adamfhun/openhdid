<?php

namespace App\Auth;

use App\Audit\Auditor;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may grant the SuperAdmin role and who may touch a SuperAdmin account.
 * Admin and SuperAdmin carry the same permissions, so the guard goes by role
 * name, like the SuperAdmin-only export payload gate in Settings.
 */
class RoleAssignment
{
    public function __construct(private readonly Auditor $auditor) {}

    public function isSuperAdmin(User $user): bool
    {
        return $user->hasRole(Role::SuperAdmin->value);
    }

    /**
     * Whether the actor may grant the named role to anyone.
     */
    public function canGrant(User $actor, string $roleName): bool
    {
        return $roleName !== Role::SuperAdmin->value || $this->isSuperAdmin($actor);
    }

    /**
     * Whether the actor may edit, close, delete or restore the target account.
     */
    public function canManage(User $actor, User $target): bool
    {
        return ! $this->isSuperAdmin($target) || $this->isSuperAdmin($actor);
    }

    /**
     * Refuses (and audits) a role list the actor may not grant.
     *
     * @param  iterable<string>  $roleNames
     *
     * @throws AuthorizationException
     */
    public function assertAssignable(User $actor, iterable $roleNames, ?User $target = null): void
    {
        $names = collect($roleNames)->values()->all();
        $rejected = array_values(array_filter($names, fn (string $name): bool => ! $this->canGrant($actor, $name)));

        if ($rejected === []) {
            return;
        }

        $this->auditor->record('user.role_escalation_rejected', $target, [
            'roles' => $names,
            'rejected' => $rejected,
        ], $actor);

        throw new AuthorizationException(__('Only a SuperAdmin can grant the SuperAdmin role.'));
    }
}
