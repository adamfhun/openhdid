<?php

namespace App\Identification;

use App\Audit\Auditor;
use App\Auth\Passwordless\OneTimeCodes;
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
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\System\Installation;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pre-defined PIN identification, with lockout after repeated failures.
 * Each lockout doubles the previous one (capped at a day) until a correct
 * PIN resets the streak. Agent-entered PINs are only accepted when enabled
 * in settings, and each agent is limited to a number of checks per hour.
 */
class PinService
{
    private const MAX_LOCKOUT_MINUTES = 24 * 60;

    /** PINs refused as already in use, per account and day, before PIN changes stop for the day. */
    public const TAKEN_REFUSALS_PER_DAY = 5;

    private const TAKEN_REFUSALS_PREFIX = 'hdid:pin-taken:';

    public function __construct(
        private readonly Settings $settings,
        private readonly Auditor $auditor,
        private readonly OneTimeCodes $codes,
        private readonly ClientTiers $tiers,
        private readonly AgentAttemptLimiter $agentLimiter,
    ) {}

    public const MIN_ALLOWED_LENGTH = 6;

    public const MAX_ALLOWED_LENGTH = 12;

    public function minLength(): int
    {
        return max(self::MIN_ALLOWED_LENGTH, min(self::MAX_ALLOWED_LENGTH, $this->settings->int(SettingKey::PinMinLength)));
    }

    public function maxLength(): int
    {
        return max($this->minLength(), min(self::MAX_ALLOWED_LENGTH, $this->settings->int(SettingKey::PinMaxLength)));
    }

    public function agentVerificationEnabled(): bool
    {
        return $this->settings->bool(SettingKey::PinAgentVerificationEnabled);
    }

    public function clientChangesEnabled(): bool
    {
        return $this->settings->bool(SettingKey::PinClientChangesEnabled);
    }

    public function uniquenessRequired(): bool
    {
        return $this->settings->bool(SettingKey::PinUniqueRequired);
    }

    private function isPinTaken(Client $client, string $pin): bool
    {
        return Client::withTrashed()->whereKeyNot($client->id)->where('pin_lookup', $this->lookupOf($pin))->exists();
    }

    public function lookupOf(string $pin): string
    {
        return hash_hmac('sha256', 'client-pin:'.$pin, (string) config('app.key'));
    }

    public function generatePin(Client $client): string
    {
        $length = $this->minLength();

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $pin = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

            if (! $this->uniquenessRequired() || ! $this->isPinTaken($client, $pin)) {
                return $pin;
            }
        }

        throw new IdentificationException(__('Could not generate an unused PIN. Please try again.'));
    }

    /**
     * The phone menu recognises the caller by number before it asks for the
     * PIN, so a PIN without a registered number can never be used.
     */
    public function canSetPin(Client $client): bool
    {
        return $client->phoneNumbers()->exists();
    }

    /**
     * Why a PIN cannot be set right now, or null when it can.
     */
    public function setPinBlocker(Client $client): ?string
    {
        return $this->canSetPin($client) ? null : __('A PIN needs at least one registered phone number: the phone menu recognises the caller by the number before it asks for the PIN.');
    }

    public function setPin(Client $client, string $pin): void
    {
        if (($blocker = $this->setPinBlocker($client)) !== null) {
            throw ValidationException::withMessages(['pin' => $blocker]);
        }

        if (! preg_match('/^\d{'.$this->minLength().','.$this->maxLength().'}$/', $pin)) {
            throw ValidationException::withMessages(['pin' => __('The PIN must be :min to :max digits.', ['min' => $this->minLength(), 'max' => $this->maxLength()])]);
        }

        // The slow hash first; the number check is repeated on the locked
        // row, because the last number may go while the PIN is hashed (the
        // removal clears a PIN on the same row lock).
        $hash = Hash::make($pin);

        Cache::lock(Installation::lockName('hdid:pin-assignment'), 30)->block(5, function () use ($client, $pin, $hash): void {
            // Outside the transaction, so that the refusal's audit row stays.
            if ($this->uniquenessRequired()) {
                $this->refuseTakenPin($client, $pin);
            }

            DB::transaction(function () use ($client, $pin, $hash): void {
                $record = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();

                if (! $record->phoneNumbers()->exists()) {
                    throw ValidationException::withMessages(['pin' => __('A PIN needs at least one registered phone number: the phone menu recognises the caller by the number before it asks for the PIN.')]);
                }

                $record->forceFill([
                    'pin_hash' => $hash,
                    'pin_lookup' => $this->lookupOf($pin),
                    'pin_set_at' => now(),
                    'pin_failed_attempts' => 0,
                    'pin_locked_until' => null,
                    'pin_lockout_count' => 0,
                ])->save();
                $this->codes->revokeAll($record);
                $this->auditor->record('client.pin_set', $record);
            });
        });

        $client->refresh();
    }

    /**
     * With unique PINs, "already in use" tells that somebody has that PIN. The
     * refusals are audited, and after a few in a day every PIN change of the
     * account is refused until the next day, a free PIN too: otherwise an
     * account could keep probing and collect the PINs in use.
     *
     * @throws ValidationException
     */
    private function refuseTakenPin(Client $client, string $pin): void
    {
        $key = self::TAKEN_REFUSALS_PREFIX.$client->id;

        if (RateLimiter::tooManyAttempts($key, self::TAKEN_REFUSALS_PER_DAY)) {
            $this->auditor->record('client.pin_change_capped', $client, ['limit' => self::TAKEN_REFUSALS_PER_DAY]);

            throw ValidationException::withMessages(['pin' => __('Too many PINs already in use were tried today. Try again tomorrow.')]);
        }

        if ($this->isPinTaken($client, $pin)) {
            RateLimiter::hit($key, 86400);
            $this->auditor->record('client.pin_taken_refused', $client);

            throw ValidationException::withMessages(['pin' => __('This PIN is already in use. Choose another PIN.')]);
        }
    }

    /**
     * @param  string|null  $reason  why the PIN goes, when not an operator's or the client's own choice
     */
    public function clearPin(Client $client, ?string $reason = null): void
    {
        DB::transaction(function () use ($client, $reason): void {
            $client->forceFill(['pin_hash' => null, 'pin_lookup' => null, 'pin_set_at' => null, 'pin_failed_attempts' => 0, 'pin_locked_until' => null, 'pin_lockout_count' => 0])->save();
            $this->codes->revokeAll($client);
            $this->auditor->record('client.pin_cleared', $client, array_filter(['reason' => $reason]));
        });
    }

    public function isLocked(Client $client): bool
    {
        return $client->pin_locked_until !== null && $client->pin_locked_until->isFuture();
    }

    /**
     * Verify a PIN and record the attempt as an identification session. On a
     * call the decision runs under the call's lock, on the call as it stands
     * then: an agent's only while the call is still theirs, the phone menu's
     * only while the call is still matched to this client.
     *
     * @throws IdentificationException
     * @throws LockTimeoutException on the phone menu's path while the call is busy
     */
    public function verify(Client $client, string $pin, IdChannel $channel, ?User $agent = null, ?Call $call = null): IdSession
    {
        if ($channel === IdChannel::Manual && ! $this->agentVerificationEnabled()) {
            throw new IdentificationException(__('Agents may not verify PINs; the caller has to use the phone menu.'));
        }

        // An agent's check takes its attempt before the compare and gets it
        // back when it passes or cannot run.
        $limited = $channel === IdChannel::Manual && $agent !== null;

        if ($limited) {
            $this->agentLimiter->reserve($agent);
        }

        try {
            $session = match (true) {
                $call === null => $this->verifyOn($client, $pin, $channel, $agent, null),
                $agent !== null && $channel === IdChannel::Manual => CallLock::runForAgent($call, $agent, fn (Call $call): IdSession => $this->verifyOn($client, $pin, $channel, $agent, $call)),
                default => CallLock::run($call, function (Call $call) use ($client, $pin, $channel, $agent): IdSession {
                    if ($call->client_id !== $client->id) {
                        throw new IdentificationException(__('The call is no longer matched to this client.'));
                    }

                    return $this->verifyOn($client, $pin, $channel, $agent, $call);
                }),
            };
        } catch (Throwable $e) {
            if ($limited) {
                $this->agentLimiter->giveBack($agent);
            }

            throw $e;
        }

        if ($limited && $session->status === IdSessionStatus::Passed) {
            $this->agentLimiter->giveBack($agent);
        }

        return $session;
    }

    private function verifyOn(Client $client, string $pin, IdChannel $channel, ?User $agent, ?Call $call): IdSession
    {
        $session = IdSession::query()->create([
            'client_id' => $client->id,
            'agent_user_id' => $agent?->id,
            'call_id' => $call?->id,
            'channel' => $channel,
            'method' => IdMethod::Pin,
            'status' => IdSessionStatus::Open,
            'started_at' => now(),
        ]);

        [$status, $reason] = $this->check($client, $pin, $channel, $call);

        // Only a session still open becomes the verdict: one a take-over or
        // a release closed meanwhile stays closed.
        $decided = IdSession::query()->whereKey($session->id)->where('status', IdSessionStatus::Open)
            ->update(['status' => $status, 'outcome_reason' => $reason, 'updated_at' => now()] + IdSession::instantColumns('decided_at', now()));
        $session->refresh();

        if ($decided !== 1) {
            return $session;
        }

        $this->auditor->record('id_session.finished', $session, ['status' => $status->value, 'reason' => $reason, 'method' => 'pin']
            + ($reason === 'account_closed' && ! $client->isClosed() && $this->tiers->heldBackByPremiumOnly($client) ? ['premium_only' => true] : []), $agent);
        app(ClientPhones::class)->verifyFromIdentifiedCall($session);

        return $session;
    }

    /**
     * One client's checks run one at a time: the lock, the hash compare and
     * the counter update form a single critical section, so overlapping
     * wrong attempts cannot lose updates or slip past the lockout.
     *
     * @return array{0: IdSessionStatus, 1: string}
     */
    private function check(Client $client, string $pin, IdChannel $channel, ?Call $call): array
    {
        return Cache::lock(Installation::lockName('hdid:pin:'.$client->id), 10)->block(5, function () use ($client, $pin, $channel, $call): array {
            $client->refresh();

            return $this->checkLocked($client, $pin, $channel, $call);
        });
    }

    /**
     * @return array{0: IdSessionStatus, 1: string}
     */
    private function checkLocked(Client $client, string $pin, IdChannel $channel, ?Call $call): array
    {
        if ($client->isClosed() || ! $this->tiers->isEntitled($client)) {
            return [IdSessionStatus::Failed, 'account_closed'];
        }

        if (! $client->hasPin()) {
            return [IdSessionStatus::Failed, 'no_pin'];
        }

        if ($this->isLocked($client)) {
            return [IdSessionStatus::Failed, 'locked'];
        }

        if (! Hash::check($pin, $client->pin_hash)) {
            $attempts = $client->pin_failed_attempts + 1;
            $locked = $attempts >= $this->settings->int(SettingKey::PinMaxFailedAttempts);
            $lockouts = $client->pin_lockout_count + ($locked ? 1 : 0);

            $client->forceFill([
                'pin_failed_attempts' => $locked ? 0 : $attempts,
                'pin_locked_until' => $locked ? now()->addMinutes($this->lockoutMinutes($lockouts)) : null,
                'pin_lockout_count' => $lockouts,
            ])->save();

            $context = ['channel' => $channel->value, 'call_id' => $call?->id, 'attempt' => $attempts];
            $this->auditor->record('client.pin_wrong', $client, $context);

            if ($locked) {
                $this->auditor->record('client.pin_locked', $client, $context + ['lockout' => $lockouts, 'until' => $client->pin_locked_until?->toIso8601String()]);
            }

            return [IdSessionStatus::Failed, $locked ? 'locked' : 'wrong_pin'];
        }

        $client->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => null, 'pin_lockout_count' => 0])->save();

        return [IdSessionStatus::Passed, 'pin_ok'];
    }

    /**
     * The n-th consecutive lockout lasts base × 2^(n-1) minutes, at most a day.
     */
    public function lockoutMinutes(int $lockouts): int
    {
        $base = max(1, $this->settings->int(SettingKey::PinLockoutMinutes));

        return (int) min(self::MAX_LOCKOUT_MINUTES, $base * (2 ** max(0, $lockouts - 1)));
    }
}
