<?php

namespace App\Filament\Concerns;

use App\Auth\Permission;
use App\Auth\RoleAssignment;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The server-side half of the greyed-out account buttons on the Users
 * list: an action mounted on a row the editor may not change (a stronger
 * account, RoleAssignment::canManage) is refused, recorded as
 * user.manage_rejected and explained, before Filament looks at it. The
 * button itself is disabled too, so this only meets a direct Livewire
 * call or a page drawn before the account got its stronger role.
 */
trait GuardsManagedAccounts
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $context
     */
    public function mountAction(string $name, array $arguments = [], array $context = []): mixed
    {
        $recordKey = $context['recordKey'] ?? null;

        if (is_scalar($recordKey) && $recordKey !== '' && ! $this->mayChangeAccount((string) $recordKey, $name)) {
            return null;
        }

        return parent::mountAction($name, $arguments, $context);
    }

    private function mayChangeAccount(string $recordKey, string $action): bool
    {
        $actor = auth()->user();
        $target = User::withTrashed()->find($recordKey);

        if (! $actor instanceof User || ! $target instanceof User || ! $actor->can(Permission::UsersManage->value)) {
            return true;
        }

        try {
            app(RoleAssignment::class)->assertManageable($actor, $target, $action);
        } catch (AuthorizationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return false;
        }

        return true;
    }
}
