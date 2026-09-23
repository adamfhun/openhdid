<?php

namespace App\CallCenter;

use App\Audit\Auditor;
use App\Clients\ClientTiers;
use App\Enums\CallStatus;
use App\Enums\IdChannel;
use App\Enums\OneTimeCodePurpose;
use App\Identification\MobileOtpService;
use App\Identification\PinService;
use App\Models\Call;
use App\Models\Client;
use App\Models\ClientPhoneNumber;
use App\Models\IdSession;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Support\PhoneNormalizer;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inbound side of the call-center integration: calls pushed by the PBX/CTI,
 * IVR verification requests, and agent claiming from the dashboard.
 */
class CallCenterService
{
    public function __construct(
        private readonly PhoneNormalizer $phones,
        private readonly PinService $pins,
        private readonly MobileOtpService $mobileOtp,
        private readonly Settings $settings,
        private readonly Auditor $auditor,
        private readonly ClientTiers $tiers,
    ) {}

    /**
     * Create or update a call from the call-center's notification.
     *
     * Events for one call are serialised on a lock, so an IVR retry racing
     * the original event updates instead of colliding on the unique call id.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function upsertCall(string $externalCallId, ?string $callerNumber, CallStatus $status, ?string $queue = null, array $metadata = [], ?Carbon $arrivedAt = null, ?Client $knownClient = null, bool $callerNumberProvided = true): Call
    {
        $apply = fn (): Call => $this->applyCallEvent($externalCallId, $callerNumber, $status, $queue, $metadata, $arrivedAt, $knownClient, $callerNumberProvided);

        return Cache::lock('hdid:call:'.$externalCallId, 10)->block(5, function () use ($apply): Call {
            try {
                return $apply();
            } catch (UniqueConstraintViolationException) {
                return $apply();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function applyCallEvent(string $externalCallId, ?string $callerNumber, CallStatus $status, ?string $queue, array $metadata, ?Carbon $arrivedAt, ?Client $knownClient, bool $callerNumberProvided): Call
    {
        // The unique external_call_id also covers soft-deleted rows, so a
        // pruned call id has to be revived instead of inserted again.
        $call = Call::withTrashed()->firstOrNew(['external_call_id' => $externalCallId]);
        $isNew = ! $call->exists;

        if ($call->trashed()) {
            $call->deleted_at = null;
        }

        if ($callerNumberProvided || $isNew) {
            $call->fill([
                'caller_number_raw' => $callerNumber,
                'caller_number_e164' => $this->phones->normalize($callerNumber),
            ]);
        }
        $e164 = $call->caller_number_e164;

        // Once a call has ended it stays ended: a late or out-of-order
        // "ringing" / "active" event must not resurrect it on the dashboard.
        $alreadyOver = $call->ended_at !== null;
        $effectiveStatus = $alreadyOver && ! $status->isOver() ? $call->status : $status;

        $call->fill([
            'status' => $effectiveStatus,
            'queue' => $queue ?? $call->queue,
            'metadata' => $metadata + ($call->metadata ?? []),
            'arrived_at' => $call->arrived_at ?? $arrivedAt ?? now(),
            'ended_at' => $call->ended_at ?? ($status->isOver() ? now() : null),
        ]);

        // The call center may name the client; when it cannot (the usual
        // case), the caller's number decides, retried on every event while
        // the call is still unmatched.
        if ($call->client_id === null) {
            $match = $knownClient !== null ? new PhoneMatch($knownClient) : $this->match($e164);
            $call->client_id = $match->client?->id;

            if ($match->ambiguous) {
                $call->metadata = ['ambiguous_number' => true] + ($call->metadata ?? []);
            } elseif (isset($call->metadata['ambiguous_number'])) {
                $call->metadata = array_diff_key($call->metadata, ['ambiguous_number' => true]);
            }
        }

        $call->tier = $this->tiers->tierForCall($call->queue, $call->client_id ? Client::query()->find($call->client_id) : null);
        $call->save();

        if ($isNew) {
            $this->auditor->record('call.arrived', $call, ['number' => $e164, 'matched' => $call->client_id !== null]);

            if (($call->metadata['ambiguous_number'] ?? false) === true) {
                $this->auditor->record('call.ambiguous_number', $call, ['number' => $e164]);
            }
        }

        return $call;
    }

    /**
     * The end of a call is written under the same lock as the call events:
     * an "active" event racing this one would otherwise read the still open
     * row, and reopen the call it has just finished.
     */
    public function endCall(string $externalCallId, bool $missed = false): ?Call
    {
        return Cache::lock('hdid:call:'.$externalCallId, 10)->block(5, function () use ($externalCallId, $missed): ?Call {
            $call = Call::query()->where('external_call_id', $externalCallId)->first();

            if ($call === null) {
                return null;
            }

            $status = $missed || ($call->agent_user_id === null && $call->answered_at === null) ? CallStatus::Missed : CallStatus::Ended;
            $call->forceFill(['status' => $status, 'ended_at' => $call->ended_at ?? now()])->save();

            return $call;
        });
    }

    /**
     * Runs a write against one call inside that call's lock, on a freshly
     * read row, so agent actions and call-center events cannot overwrite
     * each other.
     *
     * @param  Closure(Call): Call  $write
     */
    private function underCallLock(Call $call, Closure $write): Call
    {
        return Cache::lock('hdid:call:'.$call->external_call_id, 10)->block(5, function () use ($call, $write): Call {
            $call->refresh();

            return $write($call);
        });
    }

    /**
     * An agent takes the call. A call another agent already holds is only
     * handed over when the agent explicitly asks for it.
     *
     * @throws CallAlreadyClaimedException
     */
    public function claim(Call $call, User $agent, bool $takeOver = false): Call
    {
        return $this->underCallLock($call, function (Call $call) use ($agent, $takeOver): Call {
            $holder = $call->agent_user_id !== null && $call->agent_user_id !== $agent->id ? $call->agent : null;

            if ($holder !== null && ! $takeOver) {
                throw new CallAlreadyClaimedException($holder);
            }

            $call->forceFill([
                'agent_user_id' => $agent->id,
                'status' => $call->status->isOver() ? $call->status : CallStatus::Active,
                'answered_at' => $call->answered_at ?? now(),
            ])->save();

            $this->auditor->record($holder !== null ? 'call.taken_over' : 'call.claimed', $call, $holder !== null ? ['from_user_id' => $holder->id] : [], $agent);

            return $call;
        });
    }

    /**
     * The agent lets go of a call they hold (wrong pick-up, hand-back to the
     * queue); the call stays ongoing without an agent.
     */
    /**
     * @throws ValidationException when the caller has already been identified on this call
     */
    public function release(Call $call, User $agent): Call
    {
        return $this->underCallLock($call, function (Call $call) use ($agent): Call {
            if ($call->agent_user_id === null) {
                return $call;
            }

            if ($call->isIdentified()) {
                throw ValidationException::withMessages(['call' => __('The caller has already been identified on this call, so an agent has really worked with them; the call cannot be handed back to the queue.')]);
            }

            $call->forceFill([
                'agent_user_id' => null,
                'status' => $call->status->isOver() ? $call->status : CallStatus::Ringing,
            ])->save();

            $this->auditor->record('call.released', $call, [], $agent);

            return $call;
        });
    }

    public function attachClient(Call $call, Client $client, ?User $agent = null): Call
    {
        $call->forceFill(['client_id' => $client->id])->save();
        $this->auditor->record('call.client_attached', $call, ['client_id' => $client->id], $agent);

        return $call;
    }

    /**
     * A supplied call must be held by this agent and belong to a handled tier.
     * A missing or forbidden id must never silently become a call-free check.
     */
    public function heldCallForAgent(string $callId, User $agent, bool $lock = false): Call
    {
        $call = Call::query()->whereKey($callId)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();

        if ($call === null || ! $call->isHeldBy($agent)
            || ! in_array($call->tier, $agent->handledTiers(), true)) {
            throw new AuthorizationException;
        }

        return $call;
    }

    public function verifyCodeForAgent(string $code, User $agent, ?string $callId = null): ?IdSession
    {
        return DB::transaction(function () use ($code, $agent, $callId): ?IdSession {
            $call = $callId === null ? null : $this->heldCallForAgent($callId, $agent, lock: true);
            $session = $this->mobileOtp->verifyCode($code, IdChannel::Manual, $agent, $call);

            if ($session !== null && $call !== null && $call->client_id !== $session->client_id) {
                $this->attachClient($call, $session->client, $agent);
            }

            return $session;
        });
    }

    /**
     * IVR: caller typed a PIN.
     */
    public function ivrVerifyPin(string $externalCallId, string $pin): IvrResult
    {
        $call = Call::query()->where('external_call_id', $externalCallId)->first();
        $client = $call?->client;

        if ($call === null || $client === null) {
            return IvrResult::unknownCaller();
        }

        return IvrResult::fromSession($this->pins->verify($client, $pin, IdChannel::Ivr, call: $call));
    }

    /**
     * IVR: caller typed the code generated in the portal (dictated code) or
     * requested by the mobile app backend (IVR code). The code alone
     * identifies the client; when the call is known the client is attached.
     */
    public function ivrVerifyCode(?string $externalCallId, string $code): IvrResult
    {
        $call = $externalCallId === null ? null : Call::query()->where('external_call_id', $externalCallId)->first();
        $session = $this->mobileOtp->verifyCode($code, IdChannel::Ivr, call: $call, purposes: [OneTimeCodePurpose::MobileOtp, OneTimeCodePurpose::IvrCode]);

        if ($session === null) {
            return IvrResult::unknownCode();
        }

        if ($call !== null && $call->client_id !== $session->client_id) {
            $this->attachClient($call, $session->client);
        }

        return IvrResult::fromSession($session);
    }

    /**
     * Soft-delete ended calls older than the retention window.
     */
    public function pruneEnded(): int
    {
        $cutoff = now()->subHours($this->settings->int(SettingKey::CallsRetentionHours));

        return Call::query()->whereIn('status', [CallStatus::Ended, CallStatus::Missed])->where('ended_at', '<', $cutoff)->delete();
    }

    /**
     * Calls the call center never ended (lost end event, PBX restart) are
     * closed as missed once they are older than the configured age, so that
     * they do not sit on the dashboard forever.
     */
    public function expireStale(?int $hours = null): int
    {
        $hours ??= max(1, $this->settings->int(SettingKey::CallsStaleAfterHours));
        $cutoff = now()->subHours($hours);
        $count = 0;

        // chunkById pages on the key; an extra orderBy would let rows slip
        // through, and the processed rows leave the filter anyway.
        Call::query()->ongoing()->whereNull('ended_at')->where('arrived_at', '<', $cutoff)
            ->chunkById(200, function ($calls) use (&$count, $hours): void {
                foreach ($calls as $call) {
                    $call->forceFill(['status' => $call->agent_user_id === null ? CallStatus::Missed : CallStatus::Ended, 'ended_at' => now()])->save();
                    $this->auditor->record('call.expired', $call, ['after_hours' => $hours]);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Resolve the client the call center named in the event, if any and if open.
     */
    public function resolveNamedClient(?string $clientId, ?int $externalId): ?Client
    {
        if ($clientId !== null) {
            return Client::query()->open()->find($clientId);
        }

        if ($externalId !== null) {
            return Client::query()->open()->whereHas('externalRecord', fn ($q) => $q->where('external_id', $externalId))->first();
        }

        return null;
    }

    public function matchClient(?string $e164): ?Client
    {
        return $this->match($e164)->client;
    }

    /**
     * The open client behind a caller's number. Verified numbers outrank
     * unverified ones and primary numbers outrank secondary ones; when the
     * best candidates still belong to different clients the number is
     * ambiguous and nobody is matched — the caller has to identify.
     */
    public function match(?string $e164): PhoneMatch
    {
        if ($e164 === null) {
            return PhoneMatch::none();
        }

        $candidates = ClientPhoneNumber::query()
            ->where('number_e164', $e164)
            ->whereHas('client', fn ($q) => $q->open())
            ->with('client')
            ->get()
            ->sortBy([
                fn (ClientPhoneNumber $a, ClientPhoneNumber $b) => ($b->verified_at !== null) <=> ($a->verified_at !== null),
                fn (ClientPhoneNumber $a, ClientPhoneNumber $b) => $b->is_primary <=> $a->is_primary,
            ])
            ->values();

        if ($candidates->isEmpty()) {
            return PhoneMatch::none();
        }

        $best = $candidates->first();
        $rank = fn (ClientPhoneNumber $p): array => [$p->verified_at !== null, $p->is_primary];
        $tied = $candidates->filter(fn (ClientPhoneNumber $p) => $rank($p) === $rank($best));

        if ($tied->pluck('client_id')->unique()->count() > 1) {
            return new PhoneMatch(null, ambiguous: true);
        }

        return new PhoneMatch($best->client);
    }
}
