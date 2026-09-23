<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ClientResource;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SessionController extends Controller
{
    /**
     * The signed-in client.
     */
    public function me(Request $request): ClientResource
    {
        /** @var Client $client */
        $client = $request->user();

        return new ClientResource($client->load('phoneNumbers'));
    }

    /**
     * Sign out (session and current API token).
     *
     * @response array{message: string}
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user();
        $client->currentAccessToken()?->delete();

        if ($request->hasSession()) {
            Auth::guard('client')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => __('Signed out.')]);
    }

    /**
     * Sign out everywhere: every API token, browser session and remember-me
     * cookie of the client.
     *
     * @response array{message: string}
     */
    public function logoutAll(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user();
        $client->revokeAccess();

        if ($request->hasSession()) {
            Auth::guard('client')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => __('Signed out everywhere.')]);
    }
}
