<?php

namespace App\Filament\Actions;

use App\Auth\AccountLogin;
use App\Auth\LoginRejectedException;
use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Auth\Permission;
use App\Enums\PrincipalType;
use App\Models\Client;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * E-mail a one-time login link to a client. While the app runs in debug
 * mode the link is also shown to the operator so it can be handed over.
 */
class SendMagicLinkAction
{
    public static function make(string $name = 'sendMagicLink'): Action
    {
        return Action::make($name)
            ->label(__('Send login link'))
            ->icon('heroicon-o-envelope')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading(fn (Client $record) => __('Send a login link to :email?', ['email' => $record->email]))
            ->modalDescription(__('The link signs the client in once and expires after the configured minutes.'))
            ->visible(fn (Client $record): bool => auth()->user()?->can(Permission::ClientsManage->value)
                && ! $record->isClosed()
                && app(ClientPasswordlessLogin::class)->magicLinkEnabled())
            ->action(function (Client $record): void {
                try {
                    app(AccountLogin::class)->assertEligible($record, PrincipalType::Client, 'magic_link.admin');
                } catch (LoginRejectedException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                $url = app(ClientPasswordlessLogin::class)->issueMagicLink($record, auth()->user()?->email);

                $notification = Notification::make()->title(__('Login link sent to :email', ['email' => $record->email]))->success();

                if (config('app.debug')) {
                    $notification
                        ->persistent()
                        ->body(new HtmlString('<span class="text-xs">'.__('Debug mode: the link is').'</span><br><code class="break-all text-xs select-all">'.e($url).'</code>'));
                }

                $notification->send();
            });
    }
}
