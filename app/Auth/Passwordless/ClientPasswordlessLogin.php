<?php

namespace App\Auth\Passwordless;

use App\Audit\Auditor;
use App\Auth\AccountLogin;
use App\Auth\LoginLockout;
use App\Auth\LoginRejectedException;
use App\Auth\LoginRejection;
use App\Clients\ClientTiers;
use App\Enums\ClientTier;
use App\Enums\OneTimeCodePurpose;
use App\Enums\PrincipalType;
use App\Messaging\MessageKey;
use App\Messaging\Messenger;
use App\Models\Client;
use App\Models\OutboundMessage;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Magic-link and SMS one-time-code login for clients. Both are switched on
 * or off in settings. Requests never reveal whether an account exists, and
 * are limited per e-mail address as well as per network address. A login
 * is a plain one-time sign-in: no remember-me cookie, the session ends
 * with the configured session lifetime.
 *
 * The SMS code is the one path that costs money per attempt, so a number
 * gets at most the configured login codes per minute and per hour (counted
 * on the stored outbound messages); a refused send is audited and the
 * answer does not change. Other text messages (PIN, number confirmation)
 * are not limited here. A locked account (LoginLockout) gets no code at
 * all, and the lock is never named on this path: wrong codes, a missing
 * code and a locked account all answer invalid_credentials.
 *
 * The e-mailed link opens a landing page under the client's own entry
 * (/login/link or /premium/login/link) that signs in only when the client
 * presses the button (a POST): mail scanners that open links in advance
 * cannot use the link up. The token travels in the URL fragment, so it
 * reaches neither the server log nor a Referer header.
 */
class ClientPasswordlessLogin
{
    public const METHOD_MAGIC_LINK = 'magic_link';

    public const METHOD_OTP_SMS = 'otp_sms';

    /** Requests per e-mail address within the window, on top of the per-IP throttle. */
    public const REQUESTS_PER_EMAIL = 5;

    public const REQUEST_WINDOW_SECONDS = 900;

    public function __construct(
        private readonly Settings $settings,
        private readonly OneTimeCodes $codes,
        private readonly AccountLogin $login,
        private readonly Messenger $messenger,
        private readonly Auditor $auditor,
        private readonly ClientTiers $tiers,
        private readonly LoginLockout $lockout,
    ) {}

    public function magicLinkEnabled(): bool
    {
        return $this->settings->bool(SettingKey::ClientLoginMagicLinkEnabled);
    }

    public function otpSmsEnabled(): bool
    {
        return $this->settings->bool(SettingKey::ClientLoginOtpSmsEnabled);
    }

    /**
     * Silently does nothing when the account is not eligible.
     */
    public function requestMagicLink(string $email): void
    {
        if (! $this->magicLinkEnabled()) {
            throw new LoginRejectedException(LoginRejection::MethodDisabled);
        }

        if ($this->tooManyRequestsFor($email, self::METHOD_MAGIC_LINK)) {
            return;
        }

        $client = $this->eligibleClient($email, self::METHOD_MAGIC_LINK);

        if ($client === null) {
            return;
        }

        $this->issueMagicLink($client);
    }

    /**
     * Create a fresh one-time link for a client and e-mail it. Returns the
     * URL so that an operator can hand it over directly (debug/support).
     */
    public function issueMagicLink(Client $client, ?string $sentBy = null): string
    {
        $ttl = $this->settings->int(SettingKey::ClientLoginMagicLinkTtlMinutes);
        ['token' => $token] = $this->codes->issueToken($client, OneTimeCodePurpose::MagicLink, $ttl, $client->email);

        $url = $this->landingUrl($client, $token);

        $this->messenger->sendTemplate(MessageKey::MagicLink, $client, [
            'link' => $url,
            'minutes' => (string) $ttl,
            'hours' => rtrim(rtrim(number_format($ttl / 60, 1, '.', ''), '0'), '.'),
            'days' => (string) max(1, (int) round($ttl / 1440)),
        ], $client->email, meta: ['url' => $url, 'ttl_minutes' => $ttl]);
        $this->auditor->record('login.magic_link_sent', $client, ['email' => $client->email, 'by' => $sentBy]);

        return $url;
    }

    /**
     * @throws LoginRejectedException
     */
    public function consumeMagicLink(string $token): Client
    {
        if (! $this->magicLinkEnabled()) {
            throw new LoginRejectedException(LoginRejection::MethodDisabled);
        }

        $client = $this->codes->consumeToken(OneTimeCodePurpose::MagicLink, $token);

        if ($client === null) {
            $this->login->recordRejection(PrincipalType::Client, self::METHOD_MAGIC_LINK, LoginRejection::InvalidCredentials, ['detail' => 'unknown_used_or_expired_link']);

            throw new LoginRejectedException(LoginRejection::InvalidCredentials);
        }

        $client = $this->login->assertEligible($client, PrincipalType::Client, self::METHOD_MAGIC_LINK);
        $this->login->loginToSession($client, self::METHOD_MAGIC_LINK);

        return $client;
    }

    /**
     * Where a link sent before the landing page existed (/auth/client/magic/…)
     * goes now: the landing page of the client's own entry, the link still
     * unused; null when the token is not (or no longer) usable.
     */
    public function landingUrlForToken(string $token): ?string
    {
        $client = $this->codes->peekToken(OneTimeCodePurpose::MagicLink, $token);

        return $client === null ? null : $this->landingUrl($client, $token);
    }

    /**
     * The landing page under the client's own entry: premium clients get the
     * premium look before they sign in, everyone else the standard one.
     */
    private function landingUrl(Client $client, string $token): string
    {
        $entry = $this->tiers->tierFor($client) === ClientTier::Premium ? '/premium/login/link' : '/login/link';

        return url($entry).'#token='.$token;
    }

    /**
     * Silently does nothing when the account is not eligible or has no phone.
     */
    public function requestOtp(string $email): void
    {
        if (! $this->otpSmsEnabled()) {
            throw new LoginRejectedException(LoginRejection::MethodDisabled);
        }

        if ($this->tooManyRequestsFor($email, self::METHOD_OTP_SMS)) {
            return;
        }

        $client = $this->eligibleClient($email, self::METHOD_OTP_SMS);
        $phone = $client?->load('phoneNumbers')->primaryPhoneNumber();

        if ($client === null || $phone === null) {
            return;
        }

        if ($client->isLoginLocked()) {
            $this->login->recordRejection(PrincipalType::Client, self::METHOD_OTP_SMS, LoginRejection::AccountLocked, ['email' => $client->email, 'detail' => 'code_request', 'locked_until' => $client->locked_until?->toIso8601String()], $client);

            return;
        }

        if ($this->tooManyRequestsFor($phone, self::METHOD_OTP_SMS)) {
            return;
        }

        // Count and send under one lock per number, so that two overlapping
        // requests cannot both pass the budget.
        Cache::lock('sms-budget:'.$phone, 10)->block(5, function () use ($client, $phone): void {
            if (($window = $this->smsBudgetExhausted($phone)) !== null) {
                $this->auditor->record('message.refused', $client, [
                    'channel' => 'sms',
                    'template_key' => MessageKey::LoginOtpSms->value,
                    'recipient' => $phone,
                    'reason' => 'sms_limit_per_'.$window,
                    'limit' => $this->settings->int($window === 'minute' ? SettingKey::ClientLoginOtpSmsMaxPerNumberPerMinute : SettingKey::ClientLoginOtpSmsMaxPerNumberPerHour),
                ]);

                return;
            }

            ['code' => $code] = $this->codes->issueNumeric(
                $client,
                OneTimeCodePurpose::LoginOtp,
                $this->settings->int(SettingKey::ClientLoginOtpSmsLength),
                $this->settings->int(SettingKey::ClientLoginOtpSmsTtlMinutes),
                $this->settings->int(SettingKey::ClientLoginOtpMaxAttempts),
                $phone,
            );

            $this->messenger->sendTemplate(MessageKey::LoginOtpSms, $client, [
                'code' => $code,
                'minutes' => (string) $this->settings->int(SettingKey::ClientLoginOtpSmsTtlMinutes),
            ], $phone);
            $this->auditor->record('login.otp_sent', $client, ['phone' => $phone]);
        });
    }

    /**
     * The window ('minute' or 'hour') whose login-code budget this number
     * has used up, or null while another code may be sent.
     */
    private function smsBudgetExhausted(string $phone): ?string
    {
        $sent = fn (int $seconds): int => OutboundMessage::query()
            ->where('template_key', MessageKey::LoginOtpSms)
            ->where('recipient', $phone)
            ->where('created_at', '>=', now()->subSeconds($seconds))
            ->count();

        if ($sent(60) >= $this->settings->int(SettingKey::ClientLoginOtpSmsMaxPerNumberPerMinute)) {
            return 'minute';
        }

        if ($sent(3600) >= $this->settings->int(SettingKey::ClientLoginOtpSmsMaxPerNumberPerHour)) {
            return 'hour';
        }

        return null;
    }

    /**
     * Seconds the portal waits before offering the code again (one per
     * minute by default).
     */
    public function otpRetryAfterSeconds(): int
    {
        return (int) ceil(60 / max(1, $this->settings->int(SettingKey::ClientLoginOtpSmsMaxPerNumberPerMinute)));
    }

    /**
     * Whether the portal may offer the e-mailed link after the SMS code did
     * not arrive: the switch is on and the link login itself is enabled.
     */
    public function otpEmailFallbackEnabled(): bool
    {
        return $this->magicLinkEnabled() && $this->settings->bool(SettingKey::ClientLoginOtpSmsEmailFallbackEnabled);
    }

    /**
     * @throws LoginRejectedException
     */
    public function verifyOtp(string $email, string $code): Client
    {
        if (! $this->otpSmsEnabled()) {
            throw new LoginRejectedException(LoginRejection::MethodDisabled);
        }

        $client = Client::query()->where('email', mb_strtolower(trim($email)))->first();

        // Only a guess against a live code counts towards the lockout: a
        // stranger posting codes for an address that never asked for one
        // can neither lock the account nor learn that it exists.
        if ($client === null || ! $this->codes->hasUsable($client, OneTimeCodePurpose::LoginOtp)) {
            $this->login->recordRejection(PrincipalType::Client, self::METHOD_OTP_SMS, LoginRejection::InvalidCredentials, ['email' => $email, 'detail' => $client === null ? 'unknown_email' : 'no_live_code'], $client);

            throw new LoginRejectedException(LoginRejection::InvalidCredentials);
        }

        $this->lockout->assertNotLocked($client, self::METHOD_OTP_SMS, answer: LoginRejection::InvalidCredentials);

        if (! $this->codes->verify($client, OneTimeCodePurpose::LoginOtp, $code)) {
            $this->lockout->recordFailure($client, self::METHOD_OTP_SMS);

            throw new LoginRejectedException(LoginRejection::InvalidCredentials);
        }

        $client = $this->login->assertEligible($client, PrincipalType::Client, self::METHOD_OTP_SMS);
        $this->lockout->recordSuccess($client);
        $this->login->loginToSession($client, self::METHOD_OTP_SMS);

        return $client;
    }

    /**
     * Per-address limit that a distributed requester cannot dodge. Hitting it
     * is invisible to the requester (the response never changes) but audited.
     */
    private function tooManyRequestsFor(string $address, string $method): bool
    {
        $key = 'passwordless:'.$method.':'.hash('sha256', mb_strtolower(trim($address)));

        if (RateLimiter::tooManyAttempts($key, self::REQUESTS_PER_EMAIL)) {
            $this->auditor->record('login.request_throttled', null, ['method' => $method, 'address' => $address]);

            return true;
        }

        RateLimiter::hit($key, self::REQUEST_WINDOW_SECONDS);

        return false;
    }

    private function eligibleClient(string $email, string $method): ?Client
    {
        try {
            /** @var Client $client */
            $client = $this->login->resolveByEmail(PrincipalType::Client, $email, $method);

            return $client;
        } catch (LoginRejectedException) {
            return null;
        }
    }
}
