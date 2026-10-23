<?php

namespace App\Filament\Actions;

use App\Auth\Contracts\Principal;
use App\Auth\Oidc\SsoSubjectBinding;
use App\Models\SsoIdentity;
use App\Support\HuDate;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * Clears the directory identity an account's company sign-in is bound to
 * (SsoSubjectBinding), so that the next eligible sign-in binds it anew.
 * Staff accounts follow the user policy (a SuperAdmin's only by a
 * SuperAdmin), clients the client policy. While nothing is bound the
 * button stays in place, disabled, and says so.
 */
class UnbindSsoAction
{
    public static function make(string $name = 'unbindSso'): Action
    {
        return Action::make($name)
            ->label(__('Clear sign-in binding'))
            ->icon('heroicon-o-link-slash')
            ->color('warning')
            ->visible(fn (Principal&Model $record): bool => self::allowed($record))
            ->authorize(fn (Principal&Model $record): bool => self::allowed($record))
            ->disabled(fn (Principal&Model $record): bool => $record->ssoIdentities->isEmpty())
            ->tooltip(fn (Principal&Model $record): ?string => $record->ssoIdentities->isNotEmpty() ? null : __('This account is not bound to a sign-in identity yet.'))
            ->requiresConfirmation()
            ->modalHeading(fn (Principal&Model $record): string => __('Clear the sign-in binding of :name?', ['name' => $record->getAttribute('name')]))
            ->modalDescription(fn (Principal&Model $record): string => __('The account is bound to the directory identity of its company sign-in (:providers). Clearing it lets the next company sign-in under this account\'s e-mail address or employee id bind the account to whatever identity it then comes from; do this only once you know why the identity changed (a re-created directory account, a replaced registration). The account is not signed out and its second factor is untouched. Recorded in the audit log.', ['providers' => self::summary($record)]))
            ->action(function (Principal&Model $record): void {
                abort_unless(self::allowed($record), 403);
                app(SsoSubjectBinding::class)->unbind($record, null, auth()->user());
                Notification::make()->title(__('Sign-in binding cleared'))->success()->send();
            });
    }

    /**
     * The providers the account is bound to, for a badge or a sentence.
     */
    public static function summary(Principal&Model $record): string
    {
        $providers = $record->ssoIdentities->map(fn (SsoIdentity $identity): string => $identity->provider->label());

        return $providers->isEmpty() ? __('not bound') : $providers->implode(', ');
    }

    /**
     * One line per binding: provider, when and by what it was bound, the
     * last use and the sign-ins the binding refused (another identity under
     * this account's address, or this identity under another account).
     *
     * @return list<string>
     */
    public static function lines(Principal&Model $record): array
    {
        return $record->ssoIdentities->sortBy('bound_at')->values()->map(function (SsoIdentity $identity): string {
            $line = __(':provider: bound :at by :via', [
                'provider' => $identity->provider->label(),
                'at' => $identity->bound_at->format(HuDate::DATETIME),
                'via' => $identity->bound_via->label(),
            ]);

            if ($identity->last_used_at !== null) {
                $line .= ' · '.__('last used :at', ['at' => $identity->last_used_at->format(HuDate::DATETIME)]);
            }

            if ($identity->last_mismatch_at !== null) {
                $line .= ' · '.__('the binding refused :n sign-in(s), last :at', ['n' => $identity->mismatch_count, 'at' => $identity->last_mismatch_at->format(HuDate::DATETIME)]);
            }

            return $line;
        })->all();
    }

    private static function allowed(Principal&Model $record): bool
    {
        return auth()->user()?->can('update', $record) ?? false;
    }
}
