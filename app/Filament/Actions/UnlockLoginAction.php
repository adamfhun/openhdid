<?php

namespace App\Filament\Actions;

use App\Auth\Contracts\Principal;
use App\Auth\LoginLockout;
use App\Auth\Permission;
use App\Models\Client;
use App\Support\HuDate;
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
            ->label(fn (Principal&Model $record): string => $record->isLoginLocked()
                ? __('Unlock login (locked until :until)', ['until' => $record->locked_until->format(HuDate::DATETIME)])
                : __('Unlock login'))
            ->icon('heroicon-o-lock-open')
            ->color('warning')
            ->visible(fn (Principal&Model $record): bool => self::allowed($record))
            ->authorize(fn (Principal&Model $record): bool => self::allowed($record))
            ->disabled(fn (Principal&Model $record): bool => ! $record->isLoginLocked())
            ->tooltip(fn (Principal&Model $record): ?string => $record->isLoginLocked() ? null : __('The login of this account is not locked.'))
            ->requiresConfirmation()
            ->modalHeading(__('Unlock the login?'))
            ->modalDescription(__('The account was locked after repeated wrong passwords or SMS codes. Unlocking lets it sign in again at once; the unlock is recorded in the audit log.'))
            ->action(function (Principal&Model $record): void {
                abort_unless(self::allowed($record) && $record->isLoginLocked(), 403);

                app(LoginLockout::class)->unlock($record);

                Notification::make()->title(__('Login unlocked'))->success()->send();
            });
    }

    private static function allowed(Principal&Model $record): bool
    {
        $permission = $record instanceof Client ? Permission::ClientsManage : Permission::UsersManage;

        return auth()->user()?->can($permission->value) ?? false;
    }
}
