<?php

namespace App\Filament\Actions;

use App\Auth\AccountLogin;
use App\Auth\LoginRejectedException;
use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Auth\Permission;
use App\Enums\PrincipalType;
use App\Filament\NotificationText;
use App\Models\Client;
use App\Settings\SettingKey;
use App\Settings\Settings;
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
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(fn (Client $record) => __('Send a login link to :email?', ['email' => $record->email]))
            ->modalDescription(__('The link signs the client in once and expires after the configured minutes. Any login link sent to the client earlier stops working.'))
            ->visible(fn (Client $record): bool => auth()->user()?->can(Permission::ClientsManage->value)
                && ! $record->isClosed()
                && app(ClientPasswordlessLogin::class)->magicLinkEnabled())
            ->action(function (Client $record): void {
                try {
                    app(AccountLogin::class)->assertEligible($record, PrincipalType::Client, 'magic_link.admin');
                } catch (LoginRejectedException $e) {
                    Notification::make()->title(NotificationText::of($e->getMessage()))->danger()->send();

                    return;
                }

                $passwordless = app(ClientPasswordlessLogin::class);
                $reveal = $passwordless->revealsLinkInPanel();
                $url = $passwordless->issueMagicLink($record, auth()->user()?->email, revealed: $reveal);

                $notification = Notification::make()->title(NotificationText::of(__('Login link sent to :email', ['email' => $record->email])))->success();

                if ($reveal) {
                    // Say which of the two shows it: the setting, or debug mode alone.
                    $lead = app(Settings::class)->bool(SettingKey::ClientLoginMagicLinkPreviewInPanel)
                        ? __('Link preview is on; the link is')
                        : __('Debug mode (APP_DEBUG=true) shows the link; it is');
                    $notification
                        ->persistent()
                        ->body(new HtmlString('<span class="text-xs">'.e($lead).'</span><br><code class="break-all text-xs select-all">'.e($url).'</code>'));
                }

                $notification->send();
            });
    }
}
