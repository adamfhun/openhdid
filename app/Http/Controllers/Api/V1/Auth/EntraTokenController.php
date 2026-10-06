<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\LoginRejectedException;
use App\Auth\LoginRejection;
use App\Auth\Oidc\MobileTokenExchange;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Mobile sign-in: the client app authenticates with MSAL and posts the fresh
 * Entra ID token once; the backend validates it and returns an API token.
 */
class EntraTokenController extends Controller
{
    public function __construct(private readonly MobileTokenExchange $exchange) {}

    /**
     * Exchange an Entra ID token for an API token. The ID token is accepted
     * once, within the configured minutes (15 by default) of its issue.
     *
     * @response array{token: string, principal: string, name: string, email: string}
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        try {
            ['token' => $token, 'account' => $account] = $this->exchange->exchange($data['id_token'], $data['device_name']);
        } catch (LoginRejectedException $e) {
            if ($e->reason !== LoginRejection::InvalidCredentials) {
                throw $e;
            }

            // Neutral on purpose: the reason (signature, age, replay) is in the audit log.
            throw ValidationException::withMessages(['id_token' => __('The token is not valid.')]);
        }

        return response()->json([
            'token' => $token,
            'principal' => $account->principalType()->value,
            'name' => $account->name,
            'email' => $account->getEmail(),
        ]);
    }
}
