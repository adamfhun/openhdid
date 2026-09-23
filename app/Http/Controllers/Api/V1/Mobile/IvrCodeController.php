<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Enums\OneTimeCodePurpose;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiKey;
use App\Identification\MobileOtpService;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Server-to-server endpoint for the mobile app backend: issue an IVR
 * identification code for a client it has already authenticated.
 */
class IvrCodeController extends Controller
{
    /**
     * Issue an IVR identification code for a client.
     *
     * Identify the client by `email` or by `external_id`. Any live IVR code
     * of the client is replaced. The code is accepted by the IVR only, not by
     * the agent's verification page.
     *
     * @response array{code: string, formatted: string, expires_at: string, client_id: string}
     */
    public function __invoke(Request $request, MobileOtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required_without:external_id', 'nullable', 'email'],
            'external_id' => ['required_without:email', 'nullable', 'integer'],
        ]);

        $client = Client::query()->open()
            ->when(isset($data['email']), fn ($query) => $query->where('email', mb_strtolower(trim($data['email']))))
            ->when(isset($data['external_id']), fn ($query) => $query->whereHas('externalRecord', fn ($record) => $record->where('external_id', $data['external_id'])))
            ->first();

        if ($client === null) {
            throw ValidationException::withMessages(['client' => __('No open client account matches the given identity.')]);
        }

        $issued = $otp->issue($client, 'mobile_backend', OneTimeCodePurpose::IvrCode, [
            'api_key' => AuthenticateApiKey::identityOf($request),
        ]);

        return response()->json([
            'code' => $issued['code'],
            'formatted' => $issued['formatted'],
            'expires_at' => $issued['expires_at']->toIso8601String(),
            'client_id' => $client->id,
        ]);
    }
}
