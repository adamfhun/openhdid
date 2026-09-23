<?php

namespace App\Http\Controllers\Api\V1\CallCenter;

use App\CallCenter\CallCenterService;
use App\Clients\ClientTiers;
use App\Enums\CallStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CallCenter\CallEventRequest;
use App\Http\Resources\V1\CallResource;
use App\Models\Call;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Call lifecycle notifications from the call center (create / update / end).
 */
class CallController extends Controller
{
    public function __construct(
        private readonly CallCenterService $calls,
        private readonly PhoneNormalizer $phones,
    ) {}

    /**
     * Create or update a call. Idempotent on `call_id`.
     *
     * The call center may name the client (`client_id` or
     * `client_external_id`); when it cannot, which is the usual case, the
     * caller's number is matched against the clients' phone numbers on the
     * spot, and again on every later event while the call is unmatched. The
     * response carries the matched `client` (or null) with `has_pin`, so the
     * IVR knows whether it may ask for a PIN.
     * Omitting `caller_number` preserves both stored number fields; an explicit
     * null clears them. A new value is stored raw and normalized again.
     */
    public function upsert(CallEventRequest $request): CallResource
    {
        $data = $request->validated();

        $call = $this->calls->upsertCall(
            externalCallId: $data['call_id'],
            callerNumber: $data['caller_number'] ?? null,
            status: CallStatus::from($data['status']),
            queue: $data['queue'] ?? null,
            metadata: $data['metadata'] ?? [],
            arrivedAt: isset($data['arrived_at']) ? Carbon::parse($data['arrived_at']) : null,
            knownClient: $this->calls->resolveNamedClient($data['client_id'] ?? null, isset($data['client_external_id']) ? (int) $data['client_external_id'] : null),
            callerNumberProvided: array_key_exists('caller_number', $data),
        );

        return new CallResource($call->load('client'));
    }

    /**
     * The current state of a call and the client matched to it.
     *
     * Use it any time after the call event: `client` is null while the caller
     * is unknown; `identified` tells whether an identification already passed
     * on this call (by PIN, code or an agent).
     */
    public function show(string $callId): CallResource|JsonResponse
    {
        $call = Call::query()->where('external_call_id', $callId)->with(['client', 'idSessions'])->first();

        if ($call === null) {
            return response()->json(['message' => 'Unknown call.'], 404);
        }

        return new CallResource($call);
    }

    /**
     * Look up a caller by phone number without recording a call.
     *
     * Same matching as the call event: the number is normalised (E.164) and
     * compared with every client's numbers, closed accounts excluded. When
     * several clients share the number, `client` is null and `ambiguous` true.
     *
     * @response array{caller_number: ?string, ambiguous: bool, client: ?array{id: string, name: string, tier: ?string, has_pin: bool, pin_locked: bool}}
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['caller_number' => ['required', 'string', 'max:50']]);
        $e164 = $this->phones->normalize($data['caller_number']);
        $match = $this->calls->match($e164);
        $client = $match->client;

        return response()->json([
            'caller_number' => $e164,
            'ambiguous' => $match->ambiguous,
            'client' => $client === null ? null : [
                'id' => $client->id,
                'name' => $client->name,
                'tier' => app(ClientTiers::class)->tierFor($client)?->value,
                'has_pin' => $client->hasPin(),
                'pin_locked' => $client->pin_locked_until !== null && $client->pin_locked_until->isFuture(),
            ],
        ]);
    }

    /**
     * Mark a call as ended.
     *
     * @response array{message: string}
     */
    public function end(Request $request, string $callId): JsonResponse
    {
        $data = $request->validate(['missed' => ['sometimes', 'boolean']]);
        $call = $this->calls->endCall($callId, (bool) ($data['missed'] ?? false));

        return response()->json(['message' => $call === null ? 'Unknown call.' : 'Call ended.'], $call === null ? 404 : 200);
    }
}
