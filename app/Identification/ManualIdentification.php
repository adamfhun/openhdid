<?php

namespace App\Identification;

use App\Audit\Auditor;
use App\CallCenter\CallLock;
use App\Clients\ClientPhones;
use App\Clients\ClientTiers;
use App\Enums\IdChannel;
use App\Enums\IdMethod;
use App\Enums\IdSessionStatus;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\User;

/**
 * Identification by other means (a document, a callback to a registered
 * number, a supervisor's approval): a passed session with the agent's
 * reason, so it appears in every list and in the audit log. On a call it is
 * decided under the call's lock, on the call as it stands then.
 */
class ManualIdentification
{
    /** Characters of the reason, counted without the surrounding spaces. */
    public const MIN_REASON_LENGTH = 10;

    public const MAX_REASON_LENGTH = 500;

    public function __construct(
        private readonly ClientTiers $tiers,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @throws IdentificationException
     */
    public function record(Client $client, User $agent, ?Call $call, string $reason): IdSession
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw new IdentificationException(__('Give a reason of at least :n characters.', ['n' => self::MIN_REASON_LENGTH]));
        }

        $reason = mb_substr($reason, 0, self::MAX_REASON_LENGTH);

        return $call === null
            ? $this->recordOn($client, $agent, null, $reason)
            : CallLock::runForAgent($call, $agent, fn (Call $call): IdSession => $this->recordOn($client, $agent, $call, $reason));
    }

    private function recordOn(Client $client, User $agent, ?Call $call, string $reason): IdSession
    {
        $client->refresh();

        if ($client->isClosed() || ! $this->tiers->isEntitled($client)) {
            throw new IdentificationException(__('The client\'s account is closed or no longer entitled; it cannot be identified.'));
        }

        $session = IdSession::query()->create([
            'client_id' => $client->id,
            'agent_user_id' => $agent->id,
            'call_id' => $call?->id,
            'channel' => IdChannel::Manual,
            'method' => IdMethod::Manual,
            'status' => IdSessionStatus::Open,
            'started_at' => now(),
        ]);
        $session->forceFill(['status' => IdSessionStatus::Passed, 'outcome_reason' => 'manual:'.$reason, 'decided_at' => now()])->save();

        $this->auditor->record('id_session.manual', $session, ['client_id' => $client->id, 'call_id' => $call?->id, 'reason' => $reason], $agent);
        app(ClientPhones::class)->verifyFromIdentifiedCall($session);

        return $session;
    }
}
