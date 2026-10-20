<?php

namespace App\Filament\Actions;

use App\Auth\Contracts\Principal;
use App\Auth\LoginLockout;
use App\Auth\Mfa\StaffMfa;
use App\Auth\Permission;
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
 * the client management permission. While nothing is locked the button stays
 * in place, disabled, and says so.
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
            ->visible(fn (Principal&Model $record): bool => self::allowed($record))
            ->authorize(fn (Principal&Model $record): bool => self::allowed($record))
            ->disabled(fn (Principal&Model $record): bool => self::lockedUntil($record) === null)
            ->tooltip(fn (Principal&Model $record): ?string => self::lockedUntil($record) !== null ? null : __('The login of this account is not locked.'))
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
    private static function lockedUntil(Principal&Model $record): ?CarbonInterface
    {
        $until = array_filter([
            $record->isLoginLocked() ? $record->locked_until : null,
            $record instanceof User && app(StaffMfa::class)->isLocked($record) ? $record->mfa_locked_until : null,
        ]);

        return $until === [] ? null : max($until);
    }

    private static function allowed(Principal&Model $record): bool
    {
        $permission = $record instanceof Client ? Permission::ClientsManage : Permission::UsersManage;

        return auth()->user()?->can($permission->value) ?? false;
    }
}
