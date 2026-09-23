<?php

namespace App\Audit;

use Spatie\Permission\Models\Role;

/**
 * Spatie's Role model is not ours to make Auditable, so the panel calls
 * these around a save: snapshot the permission set before, record the
 * difference after. Granting `settings.manage` or `clients.package.override`
 * must leave a trace like any other privileged change.
 */
class RoleAudit
{
    /** @var array<int|string, list<string>> */
    private static array $before = [];

    public static function snapshot(Role $role): void
    {
        self::$before[$role->getKey()] = self::permissionNames($role);
    }

    public static function recordChanges(Role $role, string $event = 'role.updated'): void
    {
        $before = self::$before[$role->getKey()] ?? [];
        unset(self::$before[$role->getKey()]);
        $after = self::permissionNames($role->fresh(['permissions']) ?? $role);

        $granted = array_values(array_diff($after, $before));
        $revoked = array_values(array_diff($before, $after));

        if ($granted === [] && $revoked === [] && $event === 'role.updated') {
            return;
        }

        app(Auditor::class)->record($event, $role, [
            'role' => $role->name,
            'granted' => $granted,
            'revoked' => $revoked,
            'permissions' => $after,
        ]);
    }

    /**
     * @return list<string>
     */
    private static function permissionNames(Role $role): array
    {
        $names = $role->permissions()->pluck('name')->all();
        sort($names);

        return $names;
    }
}
