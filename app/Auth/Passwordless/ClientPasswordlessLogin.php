<?php

namespace App\Auth\Passwordless;

use App\Audit\Auditor;
use App\Auth\AccountLogin;
use App\Auth\LoginRejectedException;
use App\Auth\LoginRejection;
use App\Enums\OneTimeCodePurpose;
use App\Enums\PrincipalType;
use App\Messaging\MessageKey;
use App\Messaging\Messenger;
use App\Models\Client;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

/**
 * Magic-link and SMS one-time-code login for clients. Both are switched on
 * or off in settings. Requests never reveal whether an account exists, and
 * are limited per e-mail address as well as per network address. A login
 * is a plain one-time sign-in: no remember-me cookie, the session ends
 * with the configured session lifetime.
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

        $url = URL::temporarySignedRoute('client.magic-link', now()->addMinutes($ttl), ['token' => $token]);

        $this->messenger->sendTemplate(MessageKey::MagicLink, $client, [
            'link' => $url,
            'minutes' => (string) $ttl,
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
            throw new LoginRejectedException(LoginRejection::InvalidCredentials);
        }

        $client = $this->login->assertEligible($client, PrincipalType::Client, self::METHOD_MAGIC_LINK);
        $this->login->loginToSession($client, self::METHOD_MAGIC_LINK);

        return $client;
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

        if ($this->tooManyRequestsFor($phone, self::METHOD_OTP_SMS)) {
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

        if ($client === null || ! $this->codes->verify($client, OneTimeCodePurpose::LoginOtp, $code)) {
            $this->auditor->record('login.rejected', $client, ['method' => self::METHOD_OTP_SMS, 'reason' => LoginRejection::InvalidCredentials->value, 'email' => $email]);

            throw new LoginRejectedException(LoginRejection::InvalidCredentials);
        }

        $client = $this->login->assertEligible($client, PrincipalType::Client, self::METHOD_OTP_SMS);
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
