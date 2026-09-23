<?php

namespace App\Http\Controllers\Api\V1\Auth;

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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Mobile sign-in: the app authenticates with MSAL and posts the Entra ID
 * token; the backend validates it and returns an API token.
 */
class EntraTokenController extends Controller
{
    public function __construct(
        private readonly OidcClient $oidc,
        private readonly AccountLogin $login,
        private readonly SsoAccess $access,
    ) {}

    /**
     * Exchange an Entra ID token for an API token.
     *
     * @response array{token: string, principal: string, name: string, email: string}
     */
    public function __invoke(Request $request, PrincipalType $principal): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $this->access->assertEnabled($principal, OidcProvider::Entra, 'sso.entra.mobile');

        $config = OidcProvider::Entra->configFor($principal);

        try {
            $claims = $this->oidc->validateIdToken($config, $data['id_token'], $config->mobileClientId ?? $config->clientId);
        } catch (OidcUnavailableException $e) {
            Log::warning('SSO provider unavailable', ['principal' => $principal->value, 'provider' => OidcProvider::Entra->value, 'error' => $e->getMessage()]);

            throw new LoginRejectedException(LoginRejection::ProviderUnavailable);
        } catch (OidcException $e) {
            throw ValidationException::withMessages(['id_token' => $e->getMessage()]);
        }

        $email = $this->oidc->email($claims, $config);

        if ($email === null) {
            throw ValidationException::withMessages(['id_token' => 'The token carries no e-mail claim.']);
        }

        $account = $this->login->resolveByEmail($principal, $email, 'sso.entra.mobile');
        $this->access->assertEnabled($principal, OidcProvider::Entra, 'sso.entra.mobile', $account);
        $token = $this->login->issueToken($account, 'sso.entra.mobile', $data['device_name']);

        return response()->json([
            'token' => $token,
            'principal' => $principal->value,
            'name' => $account->name,
            'email' => $account->getEmail(),
        ]);
    }
}
