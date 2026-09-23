<?php

namespace App\Http\Controllers\Auth;

use App\Auth\AccountLogin;
use App\Auth\LoginRejectedException;
use App\Auth\LoginRejection;
use App\Auth\Oidc\OidcClient;
use App\Auth\Oidc\OidcException;
use App\Auth\Oidc\OidcProvider;
use App\Auth\Oidc\OidcUnavailableException;
use App\Auth\Oidc\SsoAccess;
use App\Enums\PrincipalType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Browser-based OpenID Connect login for both principal types.
 */
class SsoController extends Controller
{
    public function __construct(
        private readonly OidcClient $oidc,
        private readonly AccountLogin $login,
        private readonly SsoAccess $access,
    ) {}

    public function redirect(Request $request, PrincipalType $principal, OidcProvider $provider): RedirectResponse
    {
        try {
            $this->access->assertEnabled($principal, $provider, 'sso.'.$provider->value);
            $auth = $this->oidc->authorizationRequest($provider->configFor($principal), $this->callbackUrl($principal, $provider));
        } catch (LoginRejectedException $e) {
            return $this->failed($principal, $e->reason);
        } catch (OidcException $e) {
            // Nothing of the user was checked yet: an unreadable discovery
            // document is as much an outage as an unreachable provider.
            return $this->unavailable($principal, $provider, $e);
        }

        $request->session()->put('oidc.pending', [
            'state' => $auth['state'],
            'nonce' => $auth['nonce'],
            'verifier' => $auth['verifier'],
            'principal' => $principal->value,
            'provider' => $provider->value,
        ]);

        return redirect()->away($auth['url']);
    }

    public function callback(Request $request, PrincipalType $principal, OidcProvider $provider): RedirectResponse
    {
        $pending = $request->session()->pull('oidc.pending');

        if (! is_array($pending)
            || $pending['principal'] !== $principal->value
            || $pending['provider'] !== $provider->value
            || ! hash_equals((string) $pending['state'], (string) $request->query('state'))
            || ! is_string($request->query('code'))) {
            return $this->failed($principal, LoginRejection::InvalidCredentials);
        }

        try {
            $this->access->assertEnabled($principal, $provider, 'sso.'.$provider->value);
            $config = $provider->configFor($principal);
            $claims = $this->oidc->exchangeCode($config, $request->query('code'), $this->callbackUrl($principal, $provider), $pending['verifier'], $pending['nonce']);
            $email = $this->oidc->email($claims, $config);

            if ($email === null) {
                return $this->failed($principal, LoginRejection::InvalidCredentials);
            }

            $account = $this->login->resolveByEmail($principal, $email, 'sso.'.$provider->value);
            $this->access->assertEnabled($principal, $provider, 'sso.'.$provider->value, $account);
            $this->login->loginToSession($account, 'sso.'.$provider->value, remember: true);
        } catch (LoginRejectedException $e) {
            return $this->failed($principal, $e->reason);
        } catch (OidcUnavailableException $e) {
            return $this->unavailable($principal, $provider, $e);
        } catch (OidcException) {
            return $this->failed($principal, LoginRejection::InvalidCredentials);
        }

        $request->session()->regenerate();

        return redirect()->intended($this->homeFor($account));
    }

    private function callbackUrl(PrincipalType $principal, OidcProvider $provider): string
    {
        return route('sso.callback', ['principal' => $principal->value, 'provider' => $provider->value]);
    }

    private function unavailable(PrincipalType $principal, OidcProvider $provider, OidcException $e): RedirectResponse
    {
        Log::warning('SSO provider unavailable', ['principal' => $principal->value, 'provider' => $provider->value, 'error' => $e->getMessage()]);

        return $this->failed($principal, LoginRejection::ProviderUnavailable);
    }

    private function failed(PrincipalType $principal, LoginRejection $reason): RedirectResponse
    {
        if ($principal === PrincipalType::User) {
            // The panel login is a Livewire page that never reads the flashed
            // error bag, and with SSO buttons the e-mail field is folded away:
            // a notification is the one place the reason is sure to show.
            Notification::make()->danger()->persistent()
                ->title(__('Login failed.'))
                ->body(__($reason->message()))
                ->send();

            return redirect()->route('filament.admin.auth.login');
        }

        return redirect('/login?error='.$reason->value);
    }

    private function homeFor(mixed $account): string
    {
        if ($account instanceof User) {
            return '/admin';
        }

        return '/';
    }
}
