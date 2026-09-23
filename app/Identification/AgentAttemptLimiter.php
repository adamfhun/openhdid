<?php

namespace App\Identification;

use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Caps the number of failed code and PIN checks one agent may run in an
 * hour (a setting, 50 by default). Successful checks are free: the limit
 * exists to stop an account from being used to guess codes, not to slow
 * down a busy agent.
 */
class AgentAttemptLimiter
{
    private const WINDOW_SECONDS = 3600;

    public function __construct(private readonly Settings $settings) {}

    public function limit(): int
    {
        return max(1, $this->settings->int(SettingKey::AgentAttemptsPerHour));
    }

    /**
     * @throws IdentificationException
     */
    public function assertAllowed(User $agent): void
    {
        if (RateLimiter::tooManyAttempts($this->key($agent), $this->limit())) {
            $minutes = (int) ceil(RateLimiter::availableIn($this->key($agent)) / 60);

            throw new IdentificationException(__('You have reached the hourly limit of :n failed verifications. Try again in :m minutes.', ['n' => $this->limit(), 'm' => max(1, $minutes)]));
        }
    }

    public function hit(User $agent): void
    {
        RateLimiter::hit($this->key($agent), self::WINDOW_SECONDS);
    }

    public function remaining(User $agent): int
    {
        return RateLimiter::remaining($this->key($agent), $this->limit());
    }

    private function key(User $agent): string
    {
        return 'agent-verifications:'.$agent->getKey();
    }
}
