<?php

namespace App\Http\Resources\V1;

use App\Clients\ClientTiers;
use App\Models\Call;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A call as the call center sees it: its state and, when the caller's number
 * is on file, the matched client with what the IVR needs to decide the next
 * step (can it ask for a PIN, is the caller already identified). `ambiguous`
 * is true when the number is on file for several clients: nobody is matched
 * then and the caller has to identify by code, PIN or an agent.
 *
 * @mixin Call
 */
class CallResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'call_id' => $this->external_call_id,
            'caller_number' => $this->caller_number_e164,
            'caller_number_raw' => $this->caller_number_raw,
            'status' => $this->status->value,
            'queue' => $this->queue,
            'tier' => $this->tier?->value,
            'client' => $this->whenLoaded('client', fn () => $this->client === null ? null : [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'tier' => app(ClientTiers::class)->tierFor($this->client)?->value,
                'has_pin' => $this->client->hasPin(),
                'pin_locked' => $this->client->pin_locked_until !== null && $this->client->pin_locked_until->isFuture(),
            ]),
            'ambiguous' => ($this->metadata['ambiguous_number'] ?? false) === true,
            'identified' => $this->relationLoaded('idSessions') ? $this->passedSession() !== null : null,
            'arrived_at' => $this->arrived_at,
            'ended_at' => $this->ended_at,
        ];
    }
}
