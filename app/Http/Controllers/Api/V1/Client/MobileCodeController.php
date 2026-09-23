<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Http\Controllers\Controller;
use App\Identification\MobileOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The client's identification code: generated on demand, read to the IVR
 * or the agent, valid for a few minutes.
 */
class MobileCodeController extends Controller
{
    /**
     * The live identification code, if any, and whether the last code was
     * accepted by the helpdesk recently.
     *
     * @response array{code: ?string, formatted: ?string, expires_at: ?string, length: int, ttl_minutes: int, identified: ?array{at: string, channel: string}}
     */
    public function show(Request $request, MobileOtpService $otp): JsonResponse
    {
        $identified = $otp->recentIdentification($request->user());

        return response()->json($this->payload($otp->current($request->user()), $otp) + [
            'identified' => $identified === null ? null : ['at' => $identified['at']->toIso8601String(), 'channel' => $identified['channel']],
        ]);
    }

    /**
     * Generate a new identification code, replacing any live one.
     *
     * @response array{code: string, formatted: string, expires_at: string, length: int, ttl_minutes: int}
     */
    public function store(Request $request, MobileOtpService $otp): JsonResponse
    {
        return response()->json($this->payload($otp->issue($request->user(), 'client'), $otp));
    }

    /**
     * @param  array<string, mixed>|null  $issued
     * @return array{code: ?string, formatted: ?string, expires_at: ?string, length: int, ttl_minutes: int}
     */
    private function payload(?array $issued, MobileOtpService $otp): array
    {
        return [
            'code' => $issued['code'] ?? null,
            'formatted' => $issued['formatted'] ?? null,
            'expires_at' => $issued === null ? null : $issued['expires_at']->toIso8601String(),
            'length' => $otp->length(),
            'ttl_minutes' => $otp->ttlMinutes(),
        ];
    }
}
