<?php

namespace App\Identification;

use App\Audit\Auditor;
use App\Auth\Passwordless\OneTimeCodes;
use App\Clients\ClientTiers;
use App\Enums\IdChannel;
use App\Enums\IdMethod;
use App\Enums\IdSessionStatus;
use App\Enums\OneTimeCodePurpose;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Support\Carbon;

/**
 * Short-lived, system-wide unique identification codes. Two kinds share the
 * same mechanics: the dictated code the client generates in the portal (read
 * to the agent, or typed into the IVR) and the IVR code the mobile app
 * backend requests (accepted by the IVR only). A wrong code matches no
 * record, so there is nothing to lock; brute force is stopped by the rate
 * limits on the verifying endpoints and, for agents, by the hourly limit
 * of failed checks per agent.
 */
class MobileOtpService
{
    /** A code is consumed on first match, so one attempt is all it ever has. */
    private const MAX_ATTEMPTS = 1;

    /** Fewer digits would make a system-wide unique code guessable. */
    public const MIN_LENGTH = 8;

    public function __construct(
        private readonly OneTimeCodes $codes,
        private readonly Settings $settings,
        private readonly Auditor $auditor,
        private readonly ClientTiers $tiers,
        private readonly AgentAttemptLimiter $agentLimiter,
    ) {}

    public function length(OneTimeCodePurpose $purpose = OneTimeCodePurpose::MobileOtp): int
    {
        $key = $purpose === OneTimeCodePurpose::IvrCode ? SettingKey::IvrCodeLength : SettingKey::MobileOtpLength;

        return max(self::MIN_LENGTH, $this->settings->int($key));
    }

    public function ttlMinutes(): int
    {
        return $this->settings->int(SettingKey::MobileOtpTtlMinutes);
    }

    /**
     * @param  array<string, mixed>  $context  extra audit context (e.g. the API key that asked)
     * @return array{code: string, formatted: string, expires_at: Carbon}
     */
    public function issue(Client $client, ?string $source = null, OneTimeCodePurpose $purpose = OneTimeCodePurpose::MobileOtp, array $context = []): array
    {
        ['code' => $code, 'record' => $record] = $this->codes->issueUnique(
            $client,
            $purpose,
            fn (): string => DictationCode::generate($this->length($purpose)),
            $this->ttlMinutes(),
            self::MAX_ATTEMPTS,
        );

        $this->auditor->record('client.mobile_otp_issued', $client, array_filter(['source' => $source, 'purpose' => $purpose->value] + $context), $client);

        return ['code' => $code, 'formatted' => DictationCode::format($code), 'expires_at' => $record->expires_at];
    }

    /**
     * The client's live code, if any.
     *
     * @return array{code: string, formatted: string, expires_at: Carbon}|null
     */
    public function current(Client $client): ?array
    {
        $current = $this->codes->current($client, OneTimeCodePurpose::MobileOtp);

        if ($current === null) {
            return null;
        }

        return ['code' => $current['code'], 'formatted' => DictationCode::format($current['code']), 'expires_at' => $current['record']->expires_at];
    }

    /**
     * When the client's last dictated code was accepted recently: when and
     * through which channel, so the portal can tell them they are identified.
     *
     * @return array{at: Carbon, channel: string}|null
     */
    public function recentIdentification(Client $client, int $withinMinutes = 15): ?array
    {
        $session = IdSession::query()
            ->where('client_id', $client->id)
            ->where('method', IdMethod::MobileOtp)
            ->where('status', IdSessionStatus::Passed)
            ->where('decided_at', '>=', now()->subMinutes($withinMinutes))
            ->latest('decided_at')
            ->first();

        return $session === null ? null : ['at' => $session->decided_at, 'channel' => $session->channel->value];
    }

    /**
     * Verify a code of the given kinds. When it matches a live code the client
     * is identified and a passed session is recorded; otherwise null.
     *
     * @param  list<OneTimeCodePurpose>  $purposes
     */
    public function verifyCode(string $code, IdChannel $channel, ?User $agent = null, ?Call $call = null, array $purposes = [OneTimeCodePurpose::MobileOtp]): ?IdSession
    {
        if ($channel === IdChannel::Manual && $agent !== null) {
            $this->agentLimiter->assertAllowed($agent);
        }

        $code = DictationCode::normalize($code);
        $record = null;
        $purpose = null;

        foreach ($code === '' ? [] : $purposes as $purpose) {
            if (($record = $this->codes->consume($purpose, $code)) !== null) {
                break;
            }
        }

        if ($record === null || $record->client === null) {
            if ($channel === IdChannel::Manual && $agent !== null) {
                $this->agentLimiter->hit($agent);
            }

            $this->auditor->record('id_session.code_unknown', null, ['channel' => $channel->value, 'call_id' => $call?->id], $agent);

            return null;
        }

        // The code was live, but the account behind it is no longer allowed
        // in: consumed all the same, refused, and recorded against the client.
        if ($record->client->isClosed() || ! $this->tiers->isEntitled($record->client)) {
            $this->auditor->record('id_session.code_refused', $record->client, ['channel' => $channel->value, 'call_id' => $call?->id, 'reason' => 'account_closed'], $agent);

            return null;
        }

        $session = IdSession::query()->create([
            'client_id' => $record->client_id,
            'agent_user_id' => $agent?->id,
            'call_id' => $call?->id,
            'channel' => $channel,
            'method' => $purpose === OneTimeCodePurpose::IvrCode ? IdMethod::IvrCode : IdMethod::MobileOtp,
            'status' => IdSessionStatus::Passed,
            'started_at' => now(),
        ]);
        $session->forceFill(['outcome_reason' => 'code_ok', 'decided_at' => now()])->save();

        $this->auditor->record('id_session.finished', $session, ['status' => $session->status->value, 'reason' => $session->outcome_reason, 'method' => $session->method->value], $agent);

        return $session->setRelation('client', $record->client);
    }
}
