<?php

namespace App\Auth\Passwordless;

use App\Audit\Auditor;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The system-wide limits of the anonymously requested login messages, all
 * clients together (owner's decision, 2026-10-26): login links by e-mail
 * per hour (generous), SMS login codes per hour and per day (strict). A
 * message that is about to go out first takes its place on every window of
 * its channel (an atomic hit); a window that is full gives back the places
 * taken, so the counters hold only the messages that went out. Above a
 * limit nothing is sent and the answer of the login page does not change;
 * the refusal is audited at most once a minute per channel, and until the
 * full window ends the status page and the portal's login page say so.
 * The networks exempt from the address limits count here like any other.
 */
class LoginMessageCaps
{
    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    private const WINDOW_SECONDS = ['hour' => 3600, 'day' => 86400];

    public function __construct(
        private readonly Settings $settings,
        private readonly Auditor $auditor,
    ) {}

    /**
     * The limit of each window of a channel, in the order they are checked.
     *
     * @return array<string, SettingKey> window => setting
     */
    public static function windows(string $channel): array
    {
        return $channel === self::CHANNEL_EMAIL
            ? ['hour' => SettingKey::ClientLoginMagicLinkMaxPerHour]
            : ['hour' => SettingKey::ClientLoginOtpSmsMaxPerHour, 'day' => SettingKey::ClientLoginOtpSmsMaxPerDay];
    }

    /**
     * Takes a place for one message of the channel on every window, or none
     * and false when a window is full (0 means no limit).
     */
    public function admit(string $channel, string $method): bool
    {
        $taken = [];

        foreach (self::windows($channel) as $window => $setting) {
            $limit = $this->settings->int($setting);
            $counter = self::counterKey($channel, $window);
            $seconds = self::WINDOW_SECONDS[$window];

            if ($limit <= 0) {
                continue;
            }

            if (RateLimiter::hit($counter, $seconds) <= $limit) {
                $taken[$counter] = $seconds;

                continue;
            }

            RateLimiter::decrement($counter, $seconds);

            foreach ($taken as $key => $decay) {
                RateLimiter::decrement($key, $decay);
            }

            $this->refused($channel, $window, $limit, $method, $counter);

            return false;
        }

        return true;
    }

    /**
     * The window of the channel that turned a request away and is not over
     * yet: when it last did, its limit and how many it refused; null while
     * none did.
     *
     * @return array{window: string, limit: int, at: Carbon, refused: int}|null
     */
    public static function reached(string $channel): ?array
    {
        foreach (array_keys(self::windows($channel)) as $window) {
            $state = Cache::get(self::reachedKey($channel, $window));

            if (is_array($state) && isset($state['at'], $state['limit'])) {
                return [
                    'window' => $window,
                    'limit' => (int) $state['limit'],
                    'at' => Carbon::parse((string) $state['at']),
                    'refused' => RateLimiter::attempts(self::reachedKey($channel, $window).':refused'),
                ];
            }
        }

        return null;
    }

    private function refused(string $channel, string $window, int $limit, string $method, string $counter): void
    {
        // The notice lasts as long as the full window: once it ends, messages go out again.
        $remaining = max(1, RateLimiter::availableIn($counter));
        Cache::put(self::reachedKey($channel, $window), ['at' => now()->toIso8601String(), 'limit' => $limit], $remaining);
        RateLimiter::hit(self::reachedKey($channel, $window).':refused', $remaining);

        if (Cache::add('hdid:login-cap:'.$channel.':audited', true, 60)) {
            $this->auditor->record('login.request_capped', null, ['channel' => $channel, 'method' => $method, 'window' => $window, 'limit' => $limit]);
        }
    }

    private static function counterKey(string $channel, string $window): string
    {
        return 'passwordless:cap:'.$channel.':'.$window;
    }

    private static function reachedKey(string $channel, string $window): string
    {
        return 'hdid:login-cap:'.$channel.':'.$window;
    }
}
