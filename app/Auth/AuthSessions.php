<?php

namespace App\Auth;

use App\Auth\Contracts\Principal;
use App\Enums\PrincipalType;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\System\Installation;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * A browser session ends after an absolute lifetime (per account type), and
 * as soon as the account's session epoch moves on (revokeAccess: sign out
 * everywhere, closure, lost entitlement), whatever the session store. A
 * remember-me cookie, when allowed and still valid, signs the account in
 * again with a fresh session; revokeAccess rotates it, so it cannot.
 *
 * Sessions and remember-me cookies belong to the installation that issued
 * them (Installation::id()): another installation reading the same session
 * store, or a clone of the database with the same APP_KEY and remember
 * tokens, accepts neither. A session without the installation stamp (from
 * before it existed) ends once.
 */
class AuthSessions
{
    private const STARTED = 'auth_session.started_at.';

    private const EPOCH = 'auth_session.epoch.';

    private const INSTALLATION = 'auth_session.installation.';

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Settings $settings,
        private readonly AccountLogin $login,
    ) {}

    /**
     * The session guards (staff and client), built as the framework builds
     * them (AuthManager::createSessionDriver), except that the password part
     * of a remember-me cookie is keyed with APP_KEY and this installation's
     * id: a cookie another installation issued, a clone with the same key
     * and the same remember tokens, signs nobody in here.
     *
     * @param  array<string, mixed>  $config
     */
    public static function sessionGuard(AuthManager $auth, Application $app, string $name, array $config): SessionGuard
    {
        $guard = new SessionGuard(
            $name,
            $auth->createUserProvider($config['provider'] ?? null),
            $app['session.store'],
            rehashOnLogin: $app['config']->get('hashing.rehash_on_login', true),
            timeboxDuration: $app['config']->get('auth.timebox_duration', 200000),
            hashKey: (string) $app['config']->get('app.key').'|'.Installation::id(),
        );

        $guard->setCookieJar($app['cookie']);
        $guard->setDispatcher($app['events']);
        $guard->setRequest($app->refresh('request', $guard, 'setRequest'));

        if (isset($config['remember'])) {
            $guard->setRememberDuration($config['remember']);
        }

        return $guard;
    }

    /**
     * Every session login (form, SSO, link, code, remember-me cookie) stamps
     * the session with its start, the account's epoch and this installation.
     */
    public function stamp(Login $event): void
    {
        if (! $event->user instanceof Principal || ! request()->hasSession()) {
            return;
        }

        request()->session()->put([
            self::STARTED.$event->guard => now()->getTimestamp(),
            self::EPOCH.$event->guard => (int) $event->user->session_epoch,
            self::INSTALLATION.$event->guard => Installation::id(),
        ]);
    }

    /**
     * Ends an expired or revoked session, another installation's, and a
     * closed staff member's, before the request is authenticated.
     *
     * @return list<PrincipalType> the account types whose session ended because the account is closed
     */
    public function enforce(Request $request): array
    {
        if (! $request->hasSession()) {
            return [];
        }

        $session = $request->session();
        $endedByClosure = [];

        foreach (PrincipalType::cases() as $type) {
            $guard = $this->auth->guard($type->guard());
            $this->ignoreRememberCookieWhileOff($request, $guard, $type);
            if (! $guard instanceof SessionGuard || ($id = $session->get($guard->getName())) === null) {
                continue;
            }

            // Another installation's session (a shared session store, a cloned
            // database), or one from before the stamp named the installation
            // (signed out once at the upgrade), is not this installation's.
            if ($session->get(self::INSTALLATION.$type->guard()) !== Installation::id()) {
                $this->endGuardSession($request, $guard, $type);

                continue;
            }

            // Read from the table, not from a cached model: the epoch may have
            // moved on since the account was loaded.
            $account = $type->modelClass()::query()->whereKey($id)->first(['session_epoch', 'closed_at']);
            $started = $session->get(self::STARTED.$type->guard());

            // A closed client is signed out by the per-request entitlement
            // check, which also tells the portal the reason (account_closed).
            // A closed staff member's session ends here, on every route and
            // every session store: the routes outside the panel (interface
            // documents, the call-view switch) have no closure check of their
            // own. The caller tells the reason on the panel's login page,
            // since the password form there stays neutral.
            if ($account?->closed_at !== null) {
                if ($type !== PrincipalType::Client) {
                    $this->endGuardSession($request, $guard, $type);
                    $endedByClosure[] = $type;
                }

                continue;
            }

            $expired = now()->getTimestamp() - (int) $started > $this->maxHours($type) * 3600;
            $revoked = $account === null || (int) $account->session_epoch !== (int) $session->get(self::EPOCH.$type->guard());

            if ($expired || $revoked) {
                $this->endGuardSession($request, $guard, $type);
            }
        }

        return $endedByClosure;
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
        $session->forget([$guard->getName(), 'password_hash_'.$type->guard(), self::STARTED.$type->guard(), self::EPOCH.$type->guard(), self::INSTALLATION.$type->guard()]);
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
