<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ClientResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Client passwordless sign-in for the web client site (session cookie).
 */
class PasswordlessController extends Controller
{
    public function __construct(private readonly ClientPasswordlessLogin $passwordless) {}

    /**
     * Request a magic link by e-mail. Always succeeds to avoid account enumeration.
     *
     * @response array{message: string}
     */
    public function requestMagicLink(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $this->passwordless->requestMagicLink($data['email']);

        return response()->json(['message' => __('If the account exists, a login link has been sent.')]);
    }

    /**
     * Sign in with the e-mailed link: the landing page posts the token from
     * its address when the client presses the button. Each link works once.
     */
    public function consumeMagicLink(Request $request): ClientResource
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:100']]);

        $client = $this->passwordless->consumeMagicLink($data['token']);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return new ClientResource($client->load('phoneNumbers'));
    }

    /**
     * Request an SMS code. Always succeeds to avoid account enumeration.
     *
     * @response array{message: string}
     */
    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $this->passwordless->requestOtp($data['email']);

        return response()->json(['message' => __('If the account exists and has a phone number, a code has been sent.')]);
    }

    /**
     * Verify an SMS code and start the session.
     */
    public function verifyOtp(Request $request): ClientResource
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'max:10'],
        ]);

        $client = $this->passwordless->verifyOtp($data['email'], $data['code']);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return new ClientResource($client->load('phoneNumbers'));
    }
}
