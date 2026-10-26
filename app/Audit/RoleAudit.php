<?php

namespace App\Audit;

use Spatie\Permission\Models\Role;

/**
 * Spatie's Role model is not ours to make Auditable, so the panel calls
 * these around a save: snapshot the name and permission set before, record
 * the difference after. Granting `settings.manage` or
 * `clients.package.override` must leave a trace like any other privileged
 * change, and so must a plain rename.
 */
class RoleAudit
{
    /** @var array<int|string, array{name: string, permissions: list<string>}> */
    private static array $before = [];

    public static function snapshot(Role $role): void
    {
        self::$before[$role->getKey()] = [
            'name' => (string) $role->getOriginal('name', $role->name),
            'permissions' => self::permissionNames($role),
        ];
    }

    public static function recordChanges(Role $role, string $event = 'role.updated'): void
    {
        $before = self::$before[$role->getKey()] ?? null;
        unset(self::$before[$role->getKey()]);
        $fresh = $role->fresh(['permissions']) ?? $role;
        $after = self::permissionNames($fresh);
        $beforePermissions = $before['permissions'] ?? [];

        $granted = array_values(array_diff($after, $beforePermissions));
        $revoked = array_values(array_diff($beforePermissions, $after));
        $renamedFrom = $before !== null && $before['name'] !== $fresh->name ? $before['name'] : null;

        if ($granted === [] && $revoked === [] && $renamedFrom === null && $event === 'role.updated') {
            return;
        }

        app(Auditor::class)->record($event, $role, [
            'role' => $fresh->name,
            ...($renamedFrom !== null ? ['renamed_from' => $renamedFrom] : []),
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
