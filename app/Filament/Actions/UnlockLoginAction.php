<?php

namespace App\Filament\Actions;

use App\Auth\Contracts\Principal;
use App\Auth\LoginLockout;
use App\Auth\Mfa\StaffMfa;
use App\Auth\Permission;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Client;
use App\Models\User;
use App\Support\HuDate;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * Lifts the lockout that repeated wrong passwords or SMS codes put on an
 * account (LoginLockout). Staff accounts need the user management, clients
 * the client management permission, and a staff account only someone who
 * may change it (RoleAssignment::canManage; greyed out with the reason
 * otherwise). The button appears only while a lock is
 * in force: it remedies a state, and the state itself is shown on the
 * account (status badge, list column), so there is nothing to explain while
 * nothing is locked.
 */
class UnlockLoginAction
{
    public static function make(string $name = 'unlockLogin'): Action
    {
        return Action::make($name)
            ->label(fn (Principal&Model $record): string => ($until = self::lockedUntil($record)) !== null
                ? __('Unlock login (locked until :until)', ['until' => $until->format(HuDate::DATETIME)])
                : __('Unlock login'))
            ->icon('heroicon-o-lock-open')
            ->color('warning')
            ->visible(fn (Principal&Model $record): bool => self::permitted($record) && self::lockedUntil($record) !== null)
            ->authorize(fn (Principal&Model $record): bool => self::allowed($record))
            // A stronger staff account stays shown as locked, the button greyed out with the reason.
            ->authorizationTooltip()
            ->authorizationMessage(fn (Principal&Model $record): ?string => UserResource::manageRefusal($record))
            ->requiresConfirmation()
            ->modalHeading(__('Unlock the login?'))
            ->modalDescription(__('The account was locked after repeated wrong passwords, SMS codes or second-factor codes. Unlocking lets it sign in again at once; the unlock is recorded in the audit log.'))
            ->action(function (Principal&Model $record): void {
                abort_unless(self::allowed($record) && self::lockedUntil($record) !== null, 403);

                if ($record->isLoginLocked()) {
                    app(LoginLockout::class)->unlock($record);
                }

                if ($record instanceof User && app(StaffMfa::class)->isLocked($record)) {
                    app(StaffMfa::class)->unlock($record, auth()->user());
                }

                Notification::make()->title(__('Login unlocked'))->success()->send();
            });
    }

    /**
     * The later end of the password (or SMS code) lock and the second-factor lock.
     */
    public static function lockedUntil(Principal&Model $record): ?CarbonInterface
    {
        $until = array_filter([
            $record->isLoginLocked() ? $record->locked_until : null,
            $record instanceof User && app(StaffMfa::class)->isLocked($record) ? $record->mfa_locked_until : null,
        ]);

        return $until === [] ? null : max($until);
    }

    /**
     * The permission of the account kind (clients or users management).
     */
    private static function permitted(Principal&Model $record): bool
    {
        $permission = $record instanceof Client ? Permission::ClientsManage : Permission::UsersManage;

        return auth()->user()?->can($permission->value) ?? false;
    }

    /**
     * A staff account also only by someone who may change it: one whose
     * every role they could grant (RoleAssignment::canManage).
     */
    private static function allowed(Principal&Model $record): bool
    {
        return self::permitted($record) && ($record instanceof Client || (auth()->user()?->can('update', $record) ?? false));
    }
}
