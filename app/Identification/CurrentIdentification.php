<?php

namespace App\Identification;

use App\Clients\ClientTiers;
use App\Enums\IdSessionStatus;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which successful identification counts as the agent's current one. The
 * header shortcut, the "Identified" bar of the client record, the leave guard
 * and the identification page all read it from here, so they agree: a pass
 * is current while its call is still in progress in the agent's hands, or for
 * RECENT_MINUTES after the decision when no live call carries it. Older
 * passes are history, not proof.
 */
class CurrentIdentification
{
    /** A passed session without a live call counts as current for this long. */
    public const RECENT_MINUTES = 30;

    public function __construct(private readonly ClientTiers $tiers) {}

    /**
     * The agent's current identification, whichever client it is for: the
     * latest pass they made that is still current. Nothing when the client is
     * no longer served (premium-only mode), or when the call has since been
     * moved to another client, because a pass never vouches for a stranger.
     */
    public function forAgent(User $agent): ?IdSession
    {
        $session = IdSession::query()
            ->where('agent_user_id', $agent->id)
            ->where('status', IdSessionStatus::Passed)
            ->where(function (Builder $query) use ($agent): void {
                $query->where('decided_at', '>=', now()->subMinutes(self::RECENT_MINUTES))
                    ->orWhereHas('call', fn (Builder $call) => $call->ongoing()->where('agent_user_id', $agent->id));
            })
            ->with(['client', 'call'])
            ->latest('decided_at')
            ->first();

        if ($session === null || $session->client === null || ! $this->tiers->isServed($session->client)) {
            return null;
        }

        if ($session->call !== null && $session->call->client_id !== $session->client_id) {
            return null;
        }

        return $session;
    }

    /**
     * The pass that counts for THIS conversation with the client: the one
     * made on the current call (by whoever handled it), or, without a call,
     * the one this agent made in the last RECENT_MINUTES.
     */
    public function forClient(User $agent, Client $client, ?Call $call): ?IdSession
    {
        $query = IdSession::query()
            ->where('client_id', $client->id)
            ->where('status', IdSessionStatus::Passed)
            ->latest('decided_at');

        if ($call !== null) {
            return $query->where('call_id', $call->id)->first();
        }

        return $query
            ->where('agent_user_id', $agent->id)
            ->where('decided_at', '>=', now()->subMinutes(self::RECENT_MINUTES))
            ->first();
    }

    /**
     * Whether leaving the identified client's pages should ask first: only
     * while the call is in progress and the agent is the one holding it.
     * After the call, or without one, the header shortcut is way back enough.
     */
    public function guards(IdSession $session, User $agent): bool
    {
        $call = $session->call;

        return $call !== null && ! $call->status->isOver() && $call->isHeldBy($agent) && $call->client_id === $session->client_id;
    }
}
