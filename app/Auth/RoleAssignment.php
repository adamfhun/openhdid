<?php

namespace App\Auth;

use App\Audit\Auditor;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who may grant which role and who may touch a SuperAdmin account.
 * A role goes only from someone holding every one of its permissions, and
 * a held role the actor could not grant they cannot take away either
 * (decision of 2026-10-25), so `users.manage` never grants a role beyond
 * the actor's own rights. Changing an account (canManage) follows the same
 * subset rule (decision of 2026-10-26): only an account whose every role
 * the actor could grant, so a narrower `users.manage` holder cannot take
 * over a stronger account through its password, second factor, e-mail,
 * lock or binding. Admin and SuperAdmin carry the same permissions, so the SuperAdmin
 * guard goes by role name, like the SuperAdmin-only export payload gate in
 * Settings; a SuperAdmin may grant every role.
 *
 * Because the guard goes by name, the names of the built-in roles are fixed
 * and reserved: no save writes them back, and no custom role may take one.
 * The SuperAdmin role itself (name and permissions) only a SuperAdmin may
 * edit, and a role editor may only grant or revoke the permissions they
 * hold themselves, so `roles.manage` is never a quiet way to every right.
 */
class RoleAssignment
{
    public function __construct(private readonly Auditor $auditor) {}

    public function isSuperAdmin(User $user): bool
    {
        return $user->hasRole(Role::SuperAdmin->value);
    }

    /**
     * Whether the actor may grant the role to anyone (or take it away from
     * anyone): the SuperAdmin role only a SuperAdmin, any other role only
     * someone holding every one of its permissions. An unknown role is
     * refused.
     */
    public function canGrant(User $actor, RoleModel|string $role): bool
    {
        $name = $role instanceof RoleModel ? (string) $role->getOriginal('name', $role->name) : $role;

        if ($this->isSuperAdmin($actor)) {
            return true;
        }

        if ($name === Role::SuperAdmin->value) {
            return false;
        }

        $model = $role instanceof RoleModel ? $role : RoleModel::query()->where('name', $name)->where('guard_name', 'web')->first();

        return $model !== null && $this->missingPermissions($actor, $model) === [];
    }

    /**
     * The permissions of the role the actor does not hold, in the order of
     * the Permission enum. A SuperAdmin lacks none: they may grant every
     * role, whatever their own role holds.
     *
     * @return list<string>
     */
    public function missingPermissions(User $actor, RoleModel $role): array
    {
        if ($this->isSuperAdmin($actor)) {
            return [];
        }

        $names = $role->relationLoaded('permissions')
            ? $role->permissions->pluck('name')->all()
            : $role->permissions()->pluck('name')->all();

        return $this->inEnumOrder(array_filter($names, fn (string $name): bool => ! $this->canGrantPermission($actor, $name)));
    }

    /**
     * The roles the actor may neither grant nor take away, keyed by role id,
     * with the permissions of each the actor does not hold. The roles field
     * of the Users page greys these out and names them.
     *
     * @return array<int|string, array{name: string, missing: list<string>}>
     */
    public function lockedRoles(User $actor): array
    {
        if ($this->isSuperAdmin($actor)) {
            return [];
        }

        $locked = [];

        foreach (RoleModel::query()->where('guard_name', 'web')->with('permissions')->orderBy('name')->get() as $role) {
            $missing = $this->missingPermissions($actor, $role);

            if ($missing !== [] || $role->name === Role::SuperAdmin->value) {
                $locked[$role->getKey()] = ['name' => (string) $role->name, 'missing' => $missing];
            }
        }

        return $locked;
    }

    /**
     * Whether the actor may change the target account at all: edit it (its
     * e-mail and password too), unlock it, reset its second factor, clear
     * its sign-in binding, close, reopen, delete or restore it. Only an
     * account whose every role the actor could grant (decision of
     * 2026-10-26), so `users.manage` never takes over a stronger account.
     */
    public function canManage(User $actor, User $target): bool
    {
        return $this->manageRefusal($actor, $target) === null;
    }

    /**
     * Why the actor may not change the target account, or null when they
     * may. A SuperAdmin may change every account.
     */
    public function manageRefusal(User $actor, User $target): ?string
    {
        if ($this->isSuperAdmin($actor)) {
            return null;
        }

        if ($this->isSuperAdmin($target)) {
            return __('Only a SuperAdmin can change a SuperAdmin account.');
        }

        $beyond = $this->rolesBeyond($actor, $target);

        return $beyond === [] ? null : __('You cannot change this account: it holds roles you could not grant (:roles). Only someone who may grant every role of an account may change, unlock, reset, close or delete it.', ['roles' => implode(', ', $beyond)]);
    }

    /**
     * The roles of the target account the actor could not grant, by name.
     *
     * @return list<string>
     */
    public function rolesBeyond(User $actor, User $target): array
    {
        if ($this->isSuperAdmin($actor)) {
            return [];
        }

        $target->loadMissing('roles.permissions');

        return $target->roles
            ->reject(fn (RoleModel $role): bool => $this->canGrant($actor, $role))
            ->map(fn (RoleModel $role): string => (string) $role->name)
            ->sort()->values()->all();
    }

    /**
     * Refuses (and audits as user.manage_rejected) a change of an account
     * the actor may not manage: the server-side half of the greyed-out
     * buttons, for a direct Livewire call or a stale page.
     *
     * @throws AuthorizationException
     */
    public function assertManageable(User $actor, User $target, string $action): void
    {
        $refusal = $this->manageRefusal($actor, $target);

        if ($refusal === null) {
            return;
        }

        $this->auditor->record('user.manage_rejected', $target, [
            'action' => $action,
            'roles' => $this->isSuperAdmin($target) ? [Role::SuperAdmin->value] : $this->rolesBeyond($actor, $target),
        ], $actor);

        throw new AuthorizationException($refusal);
    }

    /**
     * Refuses (and audits) a role list that would grant the target a role,
     * or take one away from them, the actor may not grant. A role the
     * target already holds and keeps is no change, so a greyed-out role
     * may stay in the list; a refused save changes nothing.
     *
     * @param  iterable<string>  $roleNames  the full set the target would get
     *
     * @throws AuthorizationException
     */
    public function assertAssignable(User $actor, iterable $roleNames, ?User $target = null): void
    {
        $requested = collect($roleNames)->map(fn ($name): string => (string) $name)->unique()->values()->all();
        $current = $target?->exists ? $target->roles()->pluck('name')->map(fn ($name): string => (string) $name)->all() : [];

        $added = array_values(array_diff($requested, $current));
        $removed = array_values(array_diff($current, $requested));
        $changed = RoleModel::query()->where('guard_name', 'web')->whereIn('name', [...$added, ...$removed])->with('permissions')->get()->keyBy('name');

        $refusedAdded = array_values(array_filter($added, fn (string $name): bool => ! $this->canGrant($actor, $changed->get($name) ?? $name)));
        $refusedRemoved = array_values(array_filter($removed, fn (string $name): bool => ! $this->canGrant($actor, $changed->get($name) ?? $name)));

        if ($refusedAdded === [] && $refusedRemoved === []) {
            return;
        }

        $missing = [];
        foreach ([...$refusedAdded, ...$refusedRemoved] as $name) {
            $role = $changed->get($name);
            $lacking = $role === null || $name === Role::SuperAdmin->value ? [] : $this->missingPermissions($actor, $role);

            if ($lacking !== []) {
                $missing[$name] = $lacking;
            }
        }

        $rejected = array_values(array_unique([...$refusedAdded, ...$refusedRemoved]));
        sort($rejected);

        $this->auditor->record('user.role_escalation_rejected', $target?->exists ? $target : null, [
            'roles' => $requested,
            'rejected' => $rejected,
            ...($refusedRemoved !== [] ? ['not_removable' => $refusedRemoved] : []),
            ...($missing !== [] ? ['missing' => $missing] : []),
        ], $actor);

        $messages = [
            ...array_map(fn (string $name): string => $this->refusalMessage($name, $missing[$name] ?? [], removing: false), $refusedAdded),
            ...array_map(fn (string $name): string => $this->refusalMessage($name, $missing[$name] ?? [], removing: true), $refusedRemoved),
        ];

        throw new AuthorizationException(implode(' ', $messages));
    }

    /**
     * @param  list<string>  $missing
     */
    private function refusalMessage(string $roleName, array $missing, bool $removing): string
    {
        if ($roleName === Role::SuperAdmin->value) {
            return $removing
                ? __('Only a SuperAdmin can remove the SuperAdmin role.')
                : __('Only a SuperAdmin can grant the SuperAdmin role.');
        }

        if ($missing === []) {
            return __('The role :role does not exist.', ['role' => $roleName]);
        }

        $parameters = [
            'role' => $roleName,
            'permissions' => implode(', ', array_map(fn (string $name): string => Permission::tryFrom($name)?->label() ?? $name, $missing)),
        ];

        return $removing
            ? __('You cannot remove the role :role: you do not hold these of its permissions: :permissions.', $parameters)
            : __('You cannot grant the role :role: you do not hold these of its permissions: :permissions.', $parameters);
    }

    /**
     * @param  array<string>  $permissionNames
     * @return list<string>
     */
    private function inEnumOrder(array $permissionNames): array
    {
        $order = array_flip(array_map(fn (Permission $permission): string => $permission->value, Permission::cases()));
        $names = array_values($permissionNames);

        usort($names, fn (string $a, string $b): int => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);

        return $names;
    }

    /**
     * Whether the role is one of the built-in roles (its name is then fixed).
     */
    public function isBuiltIn(?RoleModel $role): bool
    {
        return $role !== null && Role::tryFrom((string) $role->getOriginal('name', $role->name)) !== null;
    }

    /**
     * Whether a custom role may not take this name: it reads like a built-in
     * role's name, whatever the case, accents, full-width or invisible
     * characters and spaces (the MariaDB collation equates "süper-admin"
     * with "super-admin", so the two could not even live side by side).
     */
    public function isReservedName(string $name): bool
    {
        return Role::tryFrom(self::nameKey($name)) !== null;
    }

    /**
     * A role name as the database collation compares it, near enough:
     * lower case, ASCII, without invisible characters and spaces.
     */
    private static function nameKey(string $name): string
    {
        $folded = \Normalizer::normalize(mb_strtolower(trim($name)), \Normalizer::FORM_KC);

        return (string) preg_replace('/[\s\p{Cf}]+/u', '', Str::ascii(is_string($folded) ? $folded : $name));
    }

    /**
     * Why the actor may not edit the role, or null when they may.
     */
    public function roleEditRefusal(User $actor, RoleModel $role): ?string
    {
        if ($role->getOriginal('name', $role->name) === Role::SuperAdmin->value && ! $this->isSuperAdmin($actor)) {
            return __('Only a SuperAdmin can change the SuperAdmin role.');
        }

        return null;
    }

    public function canEditRole(User $actor, RoleModel $role): bool
    {
        return $this->roleEditRefusal($actor, $role) === null;
    }

    /**
     * Whether the actor may put the permission on, or take it off, a role:
     * only one they hold themselves.
     */
    public function canGrantPermission(User $actor, string $permission): bool
    {
        return $actor->checkPermissionTo($permission, 'web');
    }

    /**
     * Refuses (and audits) a permission set that would grant or revoke a
     * permission the actor does not hold. The role's other permissions are
     * untouched: a refused save changes nothing.
     *
     * @param  iterable<string>  $permissionNames  the full set the role would get
     *
     * @throws AuthorizationException
     */
    public function assertPermissionsGrantable(User $actor, iterable $permissionNames, ?RoleModel $role = null): void
    {
        $requested = collect($permissionNames)->map(fn ($name): string => (string) $name)->unique()->values()->all();
        $current = $role?->exists ? $role->permissions()->pluck('name')->all() : [];

        $changed = array_merge(array_diff($requested, $current), array_diff($current, $requested));
        $rejected = array_values(array_filter($changed, fn (string $name): bool => ! $this->canGrantPermission($actor, $name)));
        sort($rejected);

        if ($rejected === []) {
            return;
        }

        $this->auditor->record('role.permission_change_rejected', $role?->exists ? $role : null, [
            'role' => $role?->getOriginal('name', $role->name),
            'requested' => $requested,
            'rejected' => $rejected,
        ], $actor);

        throw new AuthorizationException(__('You can only grant or revoke permissions you hold yourself. Not held: :permissions.', [
            'permissions' => implode(', ', array_map(fn (string $name): string => Permission::tryFrom($name)?->label() ?? $name, $rejected)),
        ]));
    }

    /**
     * The command-line rescue (hdid:make-admin): the SuperAdmin role exists
     * under its own name and holds every permission again, whatever the
     * Roles page did to it. A renamed old role is left as a custom role.
     * The role is found by its exact name: a look-alike one ("süper-admin")
     * the collation equates with it is not the SuperAdmin role, and since a
     * second one could not be created beside it, the rescue stops naming it.
     *
     * @return array{created: bool, restored: list<string>}
     *
     * @throws RuntimeException
     */
    public function restoreSuperAdminRole(): array
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $found = RoleModel::query()->where('name', Role::SuperAdmin->value)->where('guard_name', 'web')->get();
        $role = $found->first(fn (RoleModel $role): bool => $role->name === Role::SuperAdmin->value);

        if ($role === null && $found->isNotEmpty()) {
            throw new RuntimeException(__('A role with a look-alike name exists: :name. The database treats it as the SuperAdmin role\'s name, so that role cannot be recreated beside it. Rename or delete that role (the Roles page, or the database), then run the command again.', ['name' => $found->first()->name]));
        }

        $role ??= RoleModel::create(['name' => Role::SuperAdmin->value, 'guard_name' => 'web']);
        $held = $role->permissions()->pluck('name')->all();

        $restored = [];
        foreach (Role::SuperAdmin->permissions() as $permission) {
            if (! in_array($permission->value, $held, true)) {
                PermissionModel::findOrCreate($permission->value, 'web');
                $restored[] = $permission->value;
            }
        }

        if ($restored !== []) {
            $role->givePermissionTo($restored);
        }

        $registrar->forgetCachedPermissions();

        return ['created' => $role->wasRecentlyCreated, 'restored' => $restored];
    }
}
