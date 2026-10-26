<?php

namespace App\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The roles of a staff account are a pivot, which the Auditable trait of
 * the account does not see: a save on the Users page records what it
 * granted and took away as user.roles_changed (added, removed), by the
 * staff member who saved. Nothing is written when the roles stayed.
 */
final class UserRolesAudit
{
    /**
     * @return list<string>
     */
    public static function rolesOf(Model $user): array
    {
        if (! $user instanceof User || ! $user->exists) {
            return [];
        }

        return $user->roles()->pluck('name')->map(fn ($name): string => (string) $name)->sort()->values()->all();
    }

    /**
     * @param  list<string>  $before
     */
    public static function record(Model $user, array $before): void
    {
        if (! $user instanceof User) {
            return;
        }

        $after = self::rolesOf($user->unsetRelation('roles'));
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added === [] && $removed === []) {
            return;
        }

        app(Auditor::class)->record('user.roles_changed', $user, ['added' => $added, 'removed' => $removed]);
    }
}
