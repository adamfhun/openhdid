<?php

namespace App\Auth;

use App\Auth\Contracts\Principal;
use App\Enums\PrincipalType;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * A browser session ends after an absolute lifetime (per account type), and
 * as soon as the account's session epoch moves on (revokeAccess: sign out
 * everywhere, closure, lost entitlement), whatever the session store. A
 * remember-me cookie, when allowed and still valid, signs the account in
 * again with a fresh session; revokeAccess rotates it, so it cannot.
 */
class AuthSessions
{
    private const STARTED = 'auth_session.started_at.';

    private const EPOCH = 'auth_session.epoch.';

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Settings $settings,
        private readonly AccountLogin $login,
    ) {}

    /**
     * Every session login (form, SSO, link, code, remember-me cookie) stamps
     * the session with its start and the account's epoch.
     */
    public function stamp(Login $event): void
    {
        if (! $event->user instanceof Principal || ! request()->hasSession()) {
            return;
        }

        request()->session()->put([
            self::STARTED.$event->guard => now()->getTimestamp(),
            self::EPOCH.$event->guard => (int) $event->user->session_epoch,
        ]);
    }

    /**
     * Ends an expired or revoked session before the request is authenticated.
     */
    public function enforce(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $session = $request->session();

        foreach (PrincipalType::cases() as $type) {
            $guard = $this->auth->guard($type->guard());
            $this->ignoreRememberCookieWhileOff($request, $guard, $type);
            if (! $guard instanceof SessionGuard || ($id = $session->get($guard->getName())) === null) {
                continue;
            }

            // Read from the table, not from a cached model: the epoch may have
            // moved on since the account was loaded.
            $account = $type->modelClass()::query()->whereKey($id)->first(['session_epoch', 'closed_at']);
            $started = $session->get(self::STARTED.$type->guard());

            // A session that started before this check existed gets its stamp now.
            if ($started === null && $account !== null) {
                $session->put([self::STARTED.$type->guard() => now()->getTimestamp(), self::EPOCH.$type->guard() => (int) $account->session_epoch]);

                continue;
            }

            // A closed account is signed out by the per-request checks, which
            // also tell the reason (account_closed); its epoch moved on too.
            if ($account?->closed_at !== null) {
                continue;
            }

            $expired = now()->getTimestamp() - (int) $started > $this->maxHours($type) * 3600;
            $revoked = $account === null || (int) $account->session_epoch !== (int) $session->get(self::EPOCH.$type->guard());

            if ($expired || $revoked) {
                $this->endGuardSession($request, $guard, $type);
            }
        }
    }

    /**
     * Signs one guard out of the session. The other guard's login (a staff
     * member who also uses the portal in the same browser) stays; the
     * session itself is only invalidated once nobody is left in it. An
     * allowed remember-me cookie may sign the account in again (after the
     * absolute limit, by design); after a revocation it cannot, because
     * revokeAccess() rotated the token.
     */
    private function endGuardSession(Request $request, SessionGuard $guard, PrincipalType $type): void
    {
        $session = $request->session();
        $session->forget([$guard->getName(), 'password_hash_'.$type->guard(), self::STARTED.$type->guard(), self::EPOCH.$type->guard()]);
        $guard->forgetUser();

        $othersLoggedIn = collect(PrincipalType::cases())
            ->filter(fn (PrincipalType $other): bool => $other !== $type)
            ->contains(function (PrincipalType $other) use ($session): bool {
                $otherGuard = $this->auth->guard($other->guard());

                return $otherGuard instanceof SessionGuard && $session->has($otherGuard->getName());
            });

        if (! $othersLoggedIn) {
            $session->invalidate();
            $session->regenerateToken();
        }
    }

    /**
     * While remember-me is switched off, a remember cookie (issued earlier,
     * or before the switch existed) signs nobody in and is cleared.
     */
    private function ignoreRememberCookieWhileOff(Request $request, mixed $guard, PrincipalType $type): void
    {
        if (! $guard instanceof SessionGuard || ! $request->cookies->has($name = $guard->getRecallerName()) || $this->login->rememberMinutes($type) !== null) {
            return;
        }

        $request->cookies->remove($name);
        Cookie::queue(Cookie::forget($name));
    }

    private function maxHours(PrincipalType $type): int
    {
        return $this->settings->int($type === PrincipalType::User ? SettingKey::UserLoginSessionMaxHours : SettingKey::ClientLoginSessionMaxHours);
    }
}
