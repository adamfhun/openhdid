<?php

namespace App\Http\Controllers\Api\V1\CallCenter;

use App\CallCenter\CallCenterService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * IVR identification requests. Each call returns an immediate verdict.
 * Parameters are accepted from the query string or the body, GET or POST,
 * so that legacy IVR platforms can integrate without a JSON client.
 */
class IvrController extends Controller
{
    public function __construct(private readonly CallCenterService $calls) {}

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
            'call_id' => ['required', 'string', 'max:100'],
            'pin' => ['required', 'string', 'max:12'],
        ]);

        return response()->json($this->calls->ivrVerifyPin($data['call_id'], $data['pin'])->toArray());
    }

    /**
     * Verify the code the caller generated in the portal (dictated code) or received through the mobile app (IVR code).
     *
     * The code alone identifies the client. Pass `call_id` to attach the
     * identified client to the call.
     *
     * @response array{identified: bool, result: string, session_id: ?string, client_id: ?string, client_name: ?string}
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'call_id' => ['nullable', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20'],
        ]);

        return response()->json($this->calls->ivrVerifyCode($data['call_id'] ?? null, $data['code'])->toArray());
    }
}
