<?php

namespace App\Http\Controllers\Api\V1\CallCenter;

use App\CallCenter\CallCenterService;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Requests\Api\V1\CallCenter\CallEventRequest;
use App\Identification\PinService;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * IVR identification requests. Each call returns an immediate verdict.
 * Parameters are accepted from the query string or the body, GET or POST,
 * so that legacy IVR platforms can integrate without a JSON client.
 */
class IvrController extends Controller
{
    /** Checks per call and minute, counted after the key (and its signature) passed. */
    public const CHECKS_PER_CALL_PER_MINUTE = 10;

    public function __construct(private readonly CallCenterService $calls) {}

    /**
     * The per-call budget: ten PIN or code checks a minute for one call of
     * one partner. Counted here, behind the key check, so requests refused
     * with 401 never use it up; answered like the route throttle (429 with
     * Retry-After).
     */
    private function takeCallBudget(Request $request, ?string $callId): void
    {
        if ($callId === null || $callId === '') {
            return;
        }

        $key = 'ivr-call:'.AuthenticateApiKey::identityOf($request).':'.hash('sha256', $callId);

        if (RateLimiter::tooManyAttempts($key, self::CHECKS_PER_CALL_PER_MINUTE)) {
            $retryAfter = RateLimiter::availableIn($key);

            throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => $retryAfter, 'X-RateLimit-Limit' => self::CHECKS_PER_CALL_PER_MINUTE, 'X-RateLimit-Remaining' => 0]);
        }

        RateLimiter::hit($key, 60);
    }

    /**
     * Verify the PIN the caller typed into the IVR.
     *
     * The client is the one matched to the call by phone number (see the call
     * event or `GET calls/{call_id}`); with an unknown caller the result is
     * `unknown_caller` and the PIN is not checked.
     *
     * @response array{identified: bool, result: string, session_id: ?string, client_id: ?string, client_name: ?string}
     */
    public function verifyPin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'call_id' => ['required', 'string', 'max:'.CallEventRequest::CALL_ID_MAX_LENGTH],
            'pin' => ['required', 'string', 'max:'.PinService::MAX_ALLOWED_LENGTH],
        ]);
        $this->takeCallBudget($request, $data['call_id']);

        return response()->json($this->calls->ivrVerifyPin($data['call_id'], $data['pin'])->toArray());
    }

    /**
     * Verify the one-time identification code the caller generated in the portal, or the IVR code the mobile app backend requested and the app sent into the phone menu.
     *
     * The code alone identifies the client. Pass `call_id` to attach the
     * identified client to the call.
     *
     * @response array{identified: bool, result: string, session_id: ?string, client_id: ?string, client_name: ?string}
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'call_id' => ['nullable', 'string', 'max:'.CallEventRequest::CALL_ID_MAX_LENGTH],
            'code' => ['required', 'string', 'max:20'],
        ]);
        $this->takeCallBudget($request, $data['call_id'] ?? null);

        return response()->json($this->calls->ivrVerifyCode($data['call_id'] ?? null, $data['code'])->toArray());
    }
}
