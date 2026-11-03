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
use App\Models\OneTimeCode;
use App\Models\OutboundMessage;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\SmsDestinations;
use App\Sync\AccountProvisioner;
use App\System\Installation;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Magic-link and SMS one-time-code login for clients. Both are switched on
 * or off in settings. Requests never reveal whether an account exists, and
 * are limited per e-mail address as well as per network address (an
 * address of HDID_RATE_LIMIT_EXEMPT_NETWORKS only per e-mail address); the
 * links and codes they send, all clients together, also by system-wide
 * limits that addresses and networks spread wide cannot dodge
 * (LoginMessageCaps: links per hour, SMS codes per hour and per day). A login
 * is a plain one-time sign-in: no remember-me cookie, the session ends
 * with the configured session lifetime.
 *
 * The SMS code is the one path that costs money per attempt, so a number
 * gets at most the configured login codes per minute, hour and day (counted
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
    /** The range the SMS login code length setting allows (SettingRules); the portal field and the check use the set length. */
    public const OTP_MIN_LENGTH = 4;

    public const OTP_MAX_LENGTH = 10;

    public const REQUESTS_PER_EMAIL = 5;

    public const REQUEST_WINDOW_SECONDS = 900;

    /** The login codes one number may get, per window: the setting and the window's length in seconds. */
    private const NUMBER_BUDGETS = [
        'minute' => [SettingKey::ClientLoginOtpSmsMaxPerNumberPerMinute, 60],
        'hour' => [SettingKey::ClientLoginOtpSmsMaxPerNumberPerHour, 3600],
        'day' => [SettingKey::ClientLoginOtpSmsMaxPerNumberPerDay, 86400],
    ];

    /** Cache key prefix of the links the panel showed, by one-time code id; the value is who saw it. */
    private const REVEALED_LINK_PREFIX = 'hdid:magic-link:revealed:';

    /** Lock around one client's SMS code checks. */
    private const OTP_LOCK_PREFIX = 'hdid:login-otp:';

    public function __construct(
        private readonly Settings $settings,
        private readonly OneTimeCodes $codes,
        private readonly AccountLogin $login,
        private readonly Messenger $messenger,
        private readonly Auditor $auditor,
        private readonly ClientTiers $tiers,
        private readonly LoginLockout $lockout,
        private readonly LoginMessageCaps $caps,
    ) {}

    /**
     * Whether the panel may show a sent login link to the staff member who sent
     * it: a test-environment switch (or debug mode), never meant for production.
     */
    public function revealsLinkInPanel(): bool
    {
        return (bool) config('app.debug') || $this->settings->bool(SettingKey::ClientLoginMagicLinkPreviewInPanel);
    }

    public function magicLinkEnabled(): bool
    {
        return $this->settings->bool(SettingKey::ClientLoginMagicLinkEnabled);
    }

    public function otpSmsEnabled(): bool
    {
        return $this->settings->bool(SettingKey::ClientLoginOtpSmsEnabled);
    }

    /**
     * Silently does nothing when the account is not eligible; the work a
     * real link costs (hashing the secret) is spent either way, so the
     * answer time does not tell an eligible address from any other.
     */
    public function requestMagicLink(string $email): void
    {
        if (! $this->magicLinkEnabled()) {
            throw new LoginRejectedException(LoginRejection::MethodDisabled);
        }

        if (! $this->sendMagicLinkIfEligible($email)) {
            $this->codes->matchHashCost();
        }
    }

    /**
     * Whether a secret was hashed (a link issued, or one that lost the race
     * with a simultaneous request of the same client).
     */
    private function sendMagicLinkIfEligible(string $email): bool
    {
        if ($this->tooManyRequestsFor($email, self::METHOD_MAGIC_LINK)) {
            return false;
        }

        $client = $this->eligibleClient($email, self::METHOD_MAGIC_LINK);

        if ($client === null || ! $this->caps->admit(LoginMessageCaps::CHANNEL_EMAIL, self::METHOD_MAGIC_LINK)) {
            return false;
        }

        try {
            $this->issueMagicLink($client);
        } catch (LockTimeoutException) {
            // Another request of the client is issuing a link this moment:
            // this one issues nothing (the hashing was spent all the same).
            $this->auditor->record('login.request_throttled', $client, ['method' => self::METHOD_MAGIC_LINK, 'address' => $client->email, 'reason' => 'concurrent_issue']);
        }

        return true;
    }

    /**
     * Create a fresh one-time link for a client and e-mail it. Returns the
     * URL so that an operator can hand it over directly (debug/support).
     * A link the panel shows to the staff member who sent it is marked in
     * the audit, and the sign-in it leads to names that staff member, so a
     * staff member signing in as the client never looks like the client.
     */
    public function issueMagicLink(Client $client, ?string $sentBy = null, bool $revealed = false): string
    {
        $ttl = $this->settings->int(SettingKey::ClientLoginMagicLinkTtlMinutes);
        ['token' => $token, 'record' => $record] = $this->codes->issueToken($client, OneTimeCodePurpose::MagicLink, $ttl, $client->email);

        if ($revealed) {
            Cache::put(Installation::key(self::REVEALED_LINK_PREFIX.$record->id), $sentBy ?? '?', now()->addMinutes($ttl));
        }

        $url = $this->landingUrl($client, $token);

        $this->messenger->sendTemplate(MessageKey::MagicLink, $client, [
            'link' => $url,
            'minutes' => (string) $ttl,
            'hours' => rtrim(rtrim(number_format($ttl / 60, 1, '.', ''), '0'), '.'),
            'days' => (string) max(1, (int) round($ttl / 1440)),
        ], $client->email, meta: ['url' => $url, 'ttl_minutes' => $ttl]);
        $this->auditor->record('login.magic_link_sent', $client, ['email' => $client->email, 'by' => $sentBy] + ($revealed ? ['revealed' => true] : []));

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

        $record = $this->codes->consumeTokenRecord(OneTimeCodePurpose::MagicLink, $token);
        $client = $record?->client;

        if ($record === null || $client === null) {
            $this->login->recordRejection(PrincipalType::Client, self::METHOD_MAGIC_LINK, LoginRejection::InvalidCredentials, ['detail' => 'unknown_used_or_expired_link']);

            throw new LoginRejectedException(LoginRejection::InvalidCredentials);
        }

        // A link mailed to an address the account no longer has (the e-mail was
        // changed while it was out) belongs to the previous holder: never a way in,
        // even in the moment between the change and the revocation of its links.
        if ($record->destination !== null && AccountProvisioner::emailKey($record->destination) !== AccountProvisioner::emailKey($client->email)) {
            $this->login->recordRejection(PrincipalType::Client, self::METHOD_MAGIC_LINK, LoginRejection::InvalidCredentials, ['detail' => 'destination_changed'], $client);

            throw new LoginRejectedException(LoginRejection::InvalidCredentials);
        }

        $client = $this->login->assertEligible($client, PrincipalType::Client, self::METHOD_MAGIC_LINK);
        $revealedTo = Cache::pull(Installation::key(self::REVEALED_LINK_PREFIX.$record->id));
        $this->login->loginToSession($client, self::METHOD_MAGIC_LINK, context: $revealedTo === null ? [] : ['link_revealed_to' => $revealedTo]);

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
     * premium look before they sign in, everyone else the standard one. The
     * address is APP_URL, never the Host of the request that asked for the
     * link: an anonymous request naming a foreign host must not send the
     * client a link that hands the token to that host.
     */
    private function landingUrl(Client $client, string $token): string
    {
        $entry = $this->tiers->tierFor($client) === ClientTier::Premium ? '/premium/login/link' : '/login/link';

        return rtrim((string) config('app.url'), '/').$entry.'#token='.$token;
    }

    /**
     * Silently does nothing when the account is not eligible or has no phone;
     * like the link, it costs the same time either way.
     */
    public function requestOtp(string $email): void
    {
        if (! $this->otpSmsEnabled()) {
            throw new LoginRejectedException(LoginRejection::MethodDisabled);
        }

        if (! $this->sendOtpIfEligible($email)) {
            $this->codes->matchHashCost();
        }
    }

    /**
     * Whether a secret was hashed (a code issued, or one that lost the race
     * with a simultaneous request of the same client).
     */
    private function sendOtpIfEligible(string $email): bool
    {
        if ($this->tooManyRequestsFor($email, self::METHOD_OTP_SMS)) {
            return false;
        }

        $client = $this->eligibleClient($email, self::METHOD_OTP_SMS);

        if ($client === null) {
            return false;
        }

        // The primary number, or the first other one an SMS may go to (a landline
        // cannot receive the code), among the numbers vouched for the sign-in:
        // from the directory, added by the helpdesk, or confirmed. No code is
        // issued while none may get it.
        $client->load('phoneNumbers');
        $destinations = app(SmsDestinations::class);
        $phone = $destinations->signInTargetFor($client);

        if ($phone === null) {
            $primary = $client->primaryPhoneNumber();
            $this->auditor->record('login.otp_refused', $client, [
                'reason' => match (true) {
                    $primary === null => 'no_phone_number',
                    $destinations->targetFor($client) !== null => 'no_sign_in_number',
                    default => 'no_sms_number',
                },
                'sms_refusal' => $primary === null ? null : $destinations->refusal($primary)?->value,
            ]);

            return false;
        }

        if ($client->isLoginLocked()) {
            $this->login->recordRejection(PrincipalType::Client, self::METHOD_OTP_SMS, LoginRejection::AccountLocked, ['email' => $client->email, 'detail' => 'code_request', 'locked_until' => $client->locked_until?->toIso8601String()], $client);

            return false;
        }

        if ($this->tooManyRequestsFor($phone, self::METHOD_OTP_SMS)) {
            return false;
        }

        $sent = false;
        $hashed = false;

        // Count and send under the client's lock and the number's lock, always
        // in this order, so that two overlapping requests cannot both pass
        // the budget, neither for one number nor for one client.
        try {
            Cache::lock(Installation::lockName('sms-budget:client:'.$client->id), 10)->block(5, fn () => Cache::lock(Installation::lockName('sms-budget:'.$phone), 10)->block(5, function () use ($client, $phone, &$sent, &$hashed): void {
                if (($exhausted = $this->smsBudgetExhausted($phone, $client)) !== null) {
                    $this->auditor->record('message.refused', $client, [
                        'channel' => 'sms',
                        'template_key' => MessageKey::LoginOtpSms->value,
                        'recipient' => $phone,
                        'reason' => 'sms_limit_per_'.$exhausted['window'],
                        'scope' => $exhausted['scope'],
                        'limit' => $this->settings->int(self::NUMBER_BUDGETS[$exhausted['window']][0]),
                    ]);

                    return;
                }

                if (! $this->caps->admit(LoginMessageCaps::CHANNEL_SMS, self::METHOD_OTP_SMS)) {
                    return;
                }

                try {
                    ['code' => $code] = $this->codes->issueNumeric(
                        $client,
                        OneTimeCodePurpose::LoginOtp,
                        $this->settings->int(SettingKey::ClientLoginOtpSmsLength),
                        $this->settings->int(SettingKey::ClientLoginOtpSmsTtlMinutes),
                        $this->settings->int(SettingKey::ClientLoginOtpMaxAttempts),
                        $phone,
                    );
                } catch (LockTimeoutException) {
                    // Another request of the client is issuing a code this
                    // moment: this one sends nothing (the hashing was spent).
                    $hashed = true;
                    $this->auditor->record('login.request_throttled', $client, ['method' => self::METHOD_OTP_SMS, 'address' => $phone, 'reason' => 'concurrent_issue']);

                    return;
                }

                $this->messenger->sendTemplate(MessageKey::LoginOtpSms, $client, [
                    'code' => $code,
                    'minutes' => (string) $this->settings->int(SettingKey::ClientLoginOtpSmsTtlMinutes),
                ], $phone);
                $this->auditor->record('login.otp_sent', $client, ['phone' => $phone]);
                $sent = true;
            }));
        } catch (LockTimeoutException) {
            // The budget is being counted by another request.
            $this->auditor->record('login.request_throttled', $client, ['method' => self::METHOD_OTP_SMS, 'address' => $phone, 'reason' => 'number_busy']);
        }

        return $sent || $hashed;
    }

    /**
     * The window ('minute', 'hour' or 'day') whose login-code budget is used
     * up, and by what ('number': codes to this number, whoever asked;
     * 'client': codes to this client, whichever number), or null while
     * another code may be sent. One budget for both, so changing the
     * primary number sends no more codes.
     *
     * @return array{window: string, scope: string}|null
     */
    private function smsBudgetExhausted(string $phone, Client $client): ?array
    {
        foreach (self::NUMBER_BUDGETS as $window => [$setting, $seconds]) {
            $limit = $this->settings->int($setting);
            $since = now()->subSeconds($seconds);
            // Two queries rather than an OR, so each uses its index.
            $sent = fn (string $column, string $value): int => OutboundMessage::query()
                ->where('template_key', MessageKey::LoginOtpSms)
                ->where($column, $value)
                ->whereInstant('created_at', '>=', $since)
                ->count();

            if ($sent('recipient', $phone) >= $limit) {
                return ['window' => $window, 'scope' => 'number'];
            }

            if ($sent('client_id', $client->id) >= $limit) {
                return ['window' => $window, 'scope' => 'client'];
            }
        }

        return null;
    }

    /**
     * How many digits the SMS login code has now: the portal field asks for
     * exactly these, and the check refuses any other length before the code
     * is looked at.
     */
    public function otpCodeLength(): int
    {
        return max(self::OTP_MIN_LENGTH, min(self::OTP_MAX_LENGTH, $this->settings->int(SettingKey::ClientLoginOtpSmsLength)));
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

        /** @var Client|null $client */
        $client = $this->login->findByEmail(PrincipalType::Client, $email);

        // Only a guess against a live code counts towards the lockout: a
        // stranger posting codes for an address that never asked for one
        // can neither lock the account nor learn that it exists. Every
        // refusal spends two hashes' work, as a wrong guess at a live code
        // does (its compare and the replaced-code compare), so the answer
        // time does not tell these cases apart either.
        if ($client === null || ! $this->codes->hasUsable($client, OneTimeCodePurpose::LoginOtp)) {
            $this->spendRefusalHashCost();
            $this->login->recordRejection(PrincipalType::Client, self::METHOD_OTP_SMS, LoginRejection::InvalidCredentials, ['email' => $email, 'detail' => $client === null ? 'unknown_email' : 'no_live_code'], $client);

            throw new LoginRejectedException(LoginRejection::InvalidCredentials);
        }

        // One client's guesses are judged one at a time: the lockout check,
        // the code compare and the failure count form one critical section,
        // so overlapping requests cannot all pass a lockout check made
        // before any of them was counted.
        try {
            $record = Cache::lock(Installation::lockName(self::OTP_LOCK_PREFIX.$client->id), 10)->block(5, function () use ($client, $code): ?OneTimeCode {
                $client->refresh();

                try {
                    $this->lockout->assertNotLocked($client, self::METHOD_OTP_SMS, answer: LoginRejection::InvalidCredentials);
                } catch (LoginRejectedException $e) {
                    $this->spendRefusalHashCost();

                    throw $e;
                }

                if (($record = $this->codes->verifyRecord($client, OneTimeCodePurpose::LoginOtp, $code)) !== null) {
                    return $record;
                }

                // The client's own previous code, replaced by a newer request
                // (anyone may ask for one with the address): the owner, not a
                // guesser, so it does not count towards the lockout.
                if ($this->codes->matchesReplaced($client, OneTimeCodePurpose::LoginOtp, $code)) {
                    $this->login->recordRejection(PrincipalType::Client, self::METHOD_OTP_SMS, LoginRejection::InvalidCredentials, ['email' => $client->email, 'detail' => 'replaced_code'], $client);

                    return null;
                }

                $this->lockout->recordFailure($client, self::METHOD_OTP_SMS);

                return null;
            });
        } catch (LockTimeoutException) {
            $this->spendRefusalHashCost();
            $record = null;
        }

        if ($record === null) {
            throw new LoginRejectedException(LoginRejection::InvalidCredentials);
        }

        $client = $this->login->assertEligible($client, PrincipalType::Client, self::METHOD_OTP_SMS);
        $this->lockout->recordSuccess($client);
        $this->login->loginToSession($client, self::METHOD_OTP_SMS, context: $this->numberContext($client, $record->destination));

        return $client;
    }

    /**
     * The work of two hash compares, for the refusals that compare nothing.
     */
    private function spendRefusalHashCost(): void
    {
        $this->codes->matchHashCost();
        $this->codes->matchHashCost();
    }

    /**
     * Where the number that got the code came from, for the sign-in's audit
     * entry: a code sent to a number vouches for the account only as far as
     * the number does.
     *
     * @return array{number_source: ?string, number_verified_via: ?string, number_vouched_by: ?string}
     */
    private function numberContext(Client $client, ?string $destination): array
    {
        $number = $destination === null ? null : $client->phoneNumbers()->where('number_e164', $destination)->first();

        return [
            'number_source' => $number?->source?->value,
            'number_verified_via' => $number?->verified_via?->value,
            'number_vouched_by' => $number?->verified_by_user_id,
        ];
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

    /**
     * Whether the system-wide limit of the channel ('email' or 'sms') turns
     * requests away now: the login page tells every visitor alike, so it
     * says nothing about any one account.
     */
    public function capped(string $channel): bool
    {
        return LoginMessageCaps::reached($channel) !== null;
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
