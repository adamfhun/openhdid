<?php

namespace App\Auth\Mfa;

use App\Audit\Auditor;
use App\Auth\Oidc\OidcProvider;
use App\Auth\Oidc\OidcTokens;
use App\Enums\MfaMethod;
use App\Messaging\Channel;
use App\Messaging\Messenger;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\SmsDestinations;
use App\Support\HuDate;
use App\Support\PhoneNormalizer;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Second factor of staff sign-in. Not a step of the login form: the
 * EnsureStaffMfa middleware asks for it on every panel request until this
 * session passed it, so password, SSO and remember-me logins all stop at
 * the same place. Every new login starts unverified (forget(), on the
 * Login event). A verified SSO token that proves the provider's own MFA
 * (amr) counts, while the setting allows it.
 *
 * Sent codes (SMS, e-mail) live in the shared cache, bcrypt-hashed and
 * bound to the session that asked for them; attempts are reserved before
 * the slow check, and every check of an account runs under its lock.
 * Wrong codes lock the second factor of the account (apart from the
 * password lockout, so guessing passwords never locks SSO).
 */
class StaffMfa
{
    public const CODE_LENGTH = 6;

    /** The longest code input taken at all (spaces between the digits allowed); longer is refused before the lock, the hash work and the count. */
    public const CODE_INPUT_MAX_LENGTH = 12;

    public const CODE_TTL_MINUTES = 10;

    /** Tries per sent code, below the account lockout. */
    public const CODE_MAX_ATTEMPTS = 5;

    /** Sent codes per account: one a minute, five in fifteen minutes. */
    public const SENDS_PER_QUARTER = 5;

    /** Authenticator codes accepted around now, in 30-second steps. */
    public const TOTP_WINDOW = 1;

    private const PASSED = 'hdid_mfa_passed';

    private const SETUP_TTL_MINUTES = 15;

    /** Purposes of a sent code: the sign-in challenge, a factor being set up, the e-mail proof before a first app factor. */
    public const PURPOSE_CHALLENGE = 'challenge';

    public const PURPOSE_SETUP = 'setup';

    public const PURPOSE_BOOTSTRAP = 'bootstrap';

    public function __construct(
        private readonly Settings $settings,
        private readonly Auditor $auditor,
        private readonly Messenger $messenger,
        private readonly PhoneNormalizer $phones,
        private readonly SmsDestinations $smsDestinations,
    ) {}

    public function isEnabled(): bool
    {
        return $this->settings->bool(SettingKey::UserLoginMfaEnabled);
    }

    /**
     * SMS codes need a real gateway; the log driver only outside production
     * (demo, tests), where the code is read from the log.
     */
    public static function smsAvailable(): bool
    {
        return config('hdid.sms.driver') !== 'log' || ! app()->isProduction();
    }

    /**
     * The methods the administrator allows that can work now.
     *
     * @return list<MfaMethod>
     */
    public function allowedMethods(): array
    {
        $configured = array_map('strval', $this->settings->array(SettingKey::UserLoginMfaMethods));

        return array_values(array_filter(MfaMethod::cases(), fn (MfaMethod $method): bool => in_array($method->value, $configured, true)
            && ($method !== MfaMethod::Sms || self::smsAvailable())));
    }

    /**
     * The user's factors that may be used now.
     *
     * @return list<MfaMethod>
     */
    public function enrolledMethods(User $user): array
    {
        return array_values(array_filter($this->allowedMethods(), fn (MfaMethod $method): bool => $this->hasFactor($user, $method)));
    }

    /**
     * The SMS factor follows the EMD record: a number no longer on it no
     * longer receives codes.
     */
    public function hasFactor(User $user, MfaMethod $method): bool
    {
        return match ($method) {
            MfaMethod::App => filled($user->mfa_app_secret),
            MfaMethod::Email => $user->mfa_email_enabled_at !== null,
            MfaMethod::Sms => $user->mfa_sms_number !== null && in_array($user->mfa_sms_number, $this->smsNumbers($user), true),
        };
    }

    public function hasAnyFactor(User $user): bool
    {
        return filled($user->mfa_app_secret) || $user->mfa_email_enabled_at !== null || $user->mfa_sms_number !== null;
    }

    /**
     * Mobile numbers of the user's EMD record in an accepted country
     * (Settings › Phone numbers), the only ones SMS codes go to: the
     * Messenger would refuse any other, so it is not offered either, and
     * a factor set up on a number whose country was taken off the list
     * stops counting, like a number gone from the record.
     *
     * @return list<string>
     */
    public function smsNumbers(User $user): array
    {
        return array_values(array_filter($this->recordMobileNumbers($user), fn (string $number): bool => $this->smsDestinations->allows($number)));
    }

    /**
     * Mobile numbers of the user's EMD record that no SMS code may go to,
     * because their country is not on the accepted list: the setup page
     * names why the SMS factor is not offered.
     *
     * @return list<string>
     */
    public function smsNumbersOutsideAcceptedCountries(User $user): array
    {
        return array_values(array_diff($this->recordMobileNumbers($user), $this->smsNumbers($user)));
    }

    /**
     * @return list<string>
     */
    private function recordMobileNumbers(User $user): array
    {
        $record = $user->externalRecord;

        if ($record === null || $record->isLocal()) {
            return [];
        }

        return array_values(array_filter($this->phones->normalizeMany($record->phones ?? []), fn (string $number): bool => $this->phones->isMobile($number)));
    }

    public function isSatisfied(User $user): bool
    {
        return ! $this->isEnabled() || $this->passedInSession($user);
    }

    public function passedInSession(User $user): bool
    {
        $passed = session()->get(self::PASSED);

        return is_array($passed)
            && ($passed['user'] ?? null) === $user->getKey()
            && (int) ($passed['epoch'] ?? -1) === (int) $user->session_epoch;
    }

    public function needsSetup(User $user): bool
    {
        return $this->isEnabled() && $this->enrolledMethods($user) === [];
    }

    /**
     * Factors may be added or removed in a verified session, or (a first
     * factor) while the user has none that works.
     */
    public function canManageFactors(User $user): bool
    {
        return $this->isEnabled() && ($this->passedInSession($user) || $this->enrolledMethods($user) === []);
    }

    /**
     * A new login starts unverified, whatever the path.
     */
    public function forget(): void
    {
        session()->forget(self::PASSED);
    }

    /**
     * The verified ID token of a company sign-on says the provider already
     * asked for a second factor (amr): this session needs no other.
     */
    public function satisfyByProvider(User $user, OidcTokens $tokens, OidcProvider $provider): bool
    {
        if (! $this->isEnabled() || ! $this->settings->bool(SettingKey::UserLoginMfaTrustProvider)) {
            return false;
        }

        $amr = $tokens->idClaims['amr'] ?? $tokens->idClaims['http://schemas.microsoft.com/claims/authnmethodsreferences'] ?? [];
        $amr = array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', is_array($amr) ? $amr : [$amr]);
        $matched = array_values(array_intersect($amr, (array) config('hdid.mfa.provider_amr', [])));

        if ($matched === []) {
            return false;
        }

        $this->markPassed($user, null, ['via' => 'provider', 'provider' => $provider->value, 'amr' => $matched]);

        return true;
    }

    /**
     * Sends a sign-in, setup or bootstrap code by SMS or e-mail and returns
     * where it went, partly masked.
     *
     * @throws MfaException
     */
    public function sendCode(User $user, MfaMethod $method, string $purpose, ?string $number = null): string
    {
        if (! $method->isSent() || ! $this->isEnabled()) {
            throw new MfaException(__('This second factor does not use sent codes.'));
        }

        $this->assertNotLocked($user, $method);

        // Setup and bootstrap codes only for a session that may add a factor.
        if ($purpose !== self::PURPOSE_CHALLENGE && ! $this->canManageFactors($user)) {
            throw new MfaException(__('Verify your sign-in with a second factor first.'));
        }

        $destination = $this->destination($user, $method, $purpose, $number);

        $minuteKey = 'hdid:mfa-send-minute:'.$user->getKey();
        $quarterKey = 'hdid:mfa-send:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($minuteKey, 1) || RateLimiter::tooManyAttempts($quarterKey, self::SENDS_PER_QUARTER)) {
            throw new MfaException(__('A code was sent a moment ago. Wait a minute before asking for a new one.'));
        }
        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($quarterKey, 900);

        $code = str_pad((string) random_int(0, 10 ** self::CODE_LENGTH - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $expires = now()->addMinutes(self::CODE_TTL_MINUTES);
        Cache::put($this->codeKey($user), [
            'session' => $this->sessionFingerprint(),
            'method' => $method->value,
            'purpose' => $purpose,
            'number' => $method === MfaMethod::Sms ? $destination : null,
            'hash' => Hash::make($code),
            'attempts' => 0,
            // Kept in the entry: a wrong try must not give the code a fresh lifetime.
            'expires' => $expires->getTimestamp(),
        ], $expires);

        // SMS in Hungarian only (house rule), e-mail in the language of the staff member's panel.
        $text = fn (string $locale): string => __('Your sign-in code: :code. Valid for :minutes minutes. Do not give it to anyone.', ['code' => $code, 'minutes' => self::CODE_TTL_MINUTES], $locale);
        // A sign-in message: on the priority queue, and never sent once expired or replaced.
        $method === MfaMethod::Sms
            ? $this->messenger->sendRaw(Channel::Sms, $destination, null, $text('hu'), carriesSecret: true, validUntil: $expires, forUserId: (string) $user->getKey())
            : $this->messenger->sendRaw(Channel::Email, $destination, __('Sign-in code'), $text(app()->getLocale()), carriesSecret: true, validUntil: $expires, forUserId: (string) $user->getKey());

        $masked = self::mask($destination);
        $this->auditor->record('mfa.code_sent', $user, ['method' => $method->value, 'purpose' => $purpose, 'to' => $masked], $user);

        return $masked;
    }

    /**
     * The sign-in challenge: a code of one of the user's factors. Success
     * marks this session verified.
     *
     * @throws MfaException
     */
    public function verify(User $user, MfaMethod $method, string $code): void
    {
        self::assertCodeInput($code);

        $this->underAccountLock($user, function () use ($user, $method, $code): void {
            $this->assertNotLocked($user, $method);

            if (! in_array($method, $this->enrolledMethods($user), true)) {
                throw new MfaException(__('This second factor is not set up for your account or is not allowed any more.'));
            }

            $valid = $method === MfaMethod::App
                ? $this->verifyTotp((string) $user->mfa_app_secret, $code)
                : $this->consumeSentCode($user, $method, self::PURPOSE_CHALLENGE, $code);

            $this->settle($user, $method, $valid);
            $this->markPassed($user, $method);
        });
    }

    /**
     * Starts setting up the authenticator app: a new secret, kept for this
     * session only until a code proves the app holds it.
     *
     * @return array{secret: string, qr: string}
     *
     * @throws MfaException
     */
    public function beginAppSetup(User $user): array
    {
        $this->assertCanManage($user, MfaMethod::App);

        $app = $this->app();
        $secret = $app->generateSecret();
        Cache::put($this->setupKey($user), Crypt::encryptString(json_encode(['secret' => $secret, 'session' => $this->sessionFingerprint()], JSON_THROW_ON_ERROR)), now()->addMinutes(self::SETUP_TTL_MINUTES));

        return ['secret' => $secret, 'qr' => $app->generateQrCodeDataUri($secret)];
    }

    /**
     * A first factor added by someone who only proved the password (or a
     * company sign-on without MFA) must not be theirs to choose: the app
     * needs the e-mail code too, so knowing the password is not enough to
     * register one's own authenticator.
     */
    public function needsBootstrap(User $user): bool
    {
        return ! $this->passedInSession($user);
    }

    /**
     * @throws MfaException
     */
    public function confirmAppSetup(User $user, string $code, ?string $emailCode = null): void
    {
        self::assertCodeInput($code, $emailCode);
        $this->assertCanManage($user, MfaMethod::App);

        $this->underAccountLock($user, function () use ($user, $code, $emailCode): void {
            $this->assertNotLocked($user, MfaMethod::App);
            $pending = $this->pendingAppSecret($user);

            if ($pending === null) {
                throw new MfaException(__('The setup has expired. Start it again.'));
            }

            if ($this->needsBootstrap($user)) {
                $this->settle($user, MfaMethod::Email, $this->consumeSentCode($user, MfaMethod::Email, self::PURPOSE_BOOTSTRAP, (string) $emailCode));
            }

            $this->settle($user, MfaMethod::App, $this->verifyTotp($pending, $code));

            $user->forceFill(['mfa_app_secret' => $pending])->save();
            Cache::forget($this->setupKey($user));
            $this->factorAdded($user, MfaMethod::App);
        });
    }

    /**
     * @throws MfaException
     */
    public function confirmSentSetup(User $user, MfaMethod $method, string $code): void
    {
        self::assertCodeInput($code);
        $this->assertCanManage($user, $method);

        $this->underAccountLock($user, function () use ($user, $method, $code): void {
            $this->assertNotLocked($user, $method);
            $number = $method === MfaMethod::Sms ? (Cache::get($this->codeKey($user))['number'] ?? null) : null;

            $this->settle($user, $method, $this->consumeSentCode($user, $method, self::PURPOSE_SETUP, $code));

            $user->forceFill($method === MfaMethod::Sms ? ['mfa_sms_number' => $number] : ['mfa_email_enabled_at' => now()])->save();
            $this->factorAdded($user, $method);
        });
    }

    /**
     * @throws MfaException
     */
    public function removeFactor(User $user, MfaMethod $method): void
    {
        if (! $this->passedInSession($user)) {
            throw new MfaException(__('Verify your sign-in with a second factor first.'));
        }

        $user->forceFill(match ($method) {
            MfaMethod::App => ['mfa_app_secret' => null],
            MfaMethod::Email => ['mfa_email_enabled_at' => null],
            MfaMethod::Sms => ['mfa_sms_number' => null],
        })->save();

        $this->auditor->record('mfa.factor_removed', $user, ['method' => $method->value], $user);
    }

    /**
     * An administrator (or the hdid:mfa-reset command) clears every factor
     * of a staff member who lost theirs: the account is signed out
     * everywhere and sets up a new factor at the next login.
     */
    public function reset(User $user, ?User $by = null): void
    {
        $had = array_values(array_filter(MfaMethod::cases(), fn (MfaMethod $method): bool => match ($method) {
            MfaMethod::App => filled($user->mfa_app_secret),
            MfaMethod::Email => $user->mfa_email_enabled_at !== null,
            MfaMethod::Sms => $user->mfa_sms_number !== null,
        }));

        $user->forceFill(['mfa_app_secret' => null, 'mfa_email_enabled_at' => null, 'mfa_sms_number' => null, 'failed_mfa_attempts' => 0, 'mfa_locked_until' => null])->save();
        Cache::forget($this->codeKey($user));
        Cache::forget($this->setupKey($user));
        $user->revokeAccess();

        $this->auditor->record('mfa.reset', $user, ['methods' => array_map(fn (MfaMethod $method): string => $method->value, $had)], $by);
    }

    public function isLocked(User $user): bool
    {
        return $user->mfa_locked_until !== null && $user->mfa_locked_until->isFuture();
    }

    public function unlock(User $user, ?User $by = null): void
    {
        $until = $user->mfa_locked_until;
        $user->forceFill(['failed_mfa_attempts' => 0, 'mfa_locked_until' => null])->saveQuietly();
        $this->auditor->record('account.mfa_unlocked', $user, ['locked_until' => $until?->toIso8601String()], $by);
    }

    /**
     * Where the sent code may go: the account e-mail, or a mobile number of
     * the EMD record (the chosen one for sign-in, any of them while setting up).
     *
     * @throws MfaException
     */
    private function destination(User $user, MfaMethod $method, string $purpose, ?string $number): string
    {
        if ($purpose === self::PURPOSE_BOOTSTRAP) {
            return $user->email;
        }

        if (! in_array($method, $this->allowedMethods(), true)) {
            throw new MfaException(__('This second factor is not allowed.'));
        }

        if ($purpose === self::PURPOSE_CHALLENGE && ! $this->hasFactor($user, $method)) {
            throw new MfaException(__('This second factor is not set up for your account or is not allowed any more.'));
        }

        if ($method === MfaMethod::Email) {
            return $user->email;
        }

        $number = $purpose === self::PURPOSE_CHALLENGE ? $user->mfa_sms_number : $number;

        if ($number === null || ! in_array($number, $this->smsNumbers($user), true)) {
            throw new MfaException(__('SMS codes go only to a mobile number of your EMD record; this number is not on it.'));
        }

        return $number;
    }

    /**
     * One try of a sent code: reserved before the slow hash check, so that
     * parallel guesses count one by one; a used or expired code is gone.
     */
    private function consumeSentCode(User $user, MfaMethod $method, string $purpose, string $code): bool
    {
        $entry = Cache::get($this->codeKey($user));
        $expires = is_array($entry) ? Carbon::createFromTimestamp((int) ($entry['expires'] ?? 0)) : null;

        if (! is_array($entry)
            || ($entry['method'] ?? null) !== $method->value
            || ($entry['purpose'] ?? null) !== $purpose
            || ! hash_equals((string) ($entry['session'] ?? ''), $this->sessionFingerprint())
            || (int) ($entry['attempts'] ?? self::CODE_MAX_ATTEMPTS) >= self::CODE_MAX_ATTEMPTS
            || $expires === null || $expires->isPast()) {
            Hash::check($code, '$2y$12$'.str_repeat('a', 53));

            return false;
        }

        // The try is reserved for the rest of the code's own lifetime, not a new one.
        $entry['attempts'] = (int) $entry['attempts'] + 1;
        Cache::put($this->codeKey($user), $entry, $expires);

        if (! Hash::check(self::digits($code), (string) $entry['hash'])) {
            return false;
        }

        Cache::forget($this->codeKey($user));

        return true;
    }

    /**
     * RFC 6238 code of the app; a code (and every earlier one) is accepted
     * only once per secret.
     */
    private function verifyTotp(string $secret, string $code): bool
    {
        $code = self::digits($code);

        return strlen($code) === self::CODE_LENGTH && $secret !== '' && $this->app()->verifyCode($code, $secret, shouldPreventCodeReuse: true);
    }

    /**
     * A wrong code is counted on the account (the configured number in a
     * row locks the second factor); a right one clears the count.
     *
     * @throws MfaException
     */
    private function settle(User $user, MfaMethod $method, bool $valid): void
    {
        if ($valid) {
            if ((int) $user->failed_mfa_attempts !== 0) {
                $user->forceFill(['failed_mfa_attempts' => 0])->saveQuietly();
            }

            return;
        }

        $attempts = (int) $user->failed_mfa_attempts + 1;
        $locks = $attempts >= $this->settings->int(SettingKey::UserLoginLockoutMaxAttempts);
        $this->auditor->record('mfa.failed', $user, ['method' => $method->value, 'failed_attempts' => $attempts], $user);

        if (! $locks) {
            $user->forceFill(['failed_mfa_attempts' => $attempts])->saveQuietly();

            throw new MfaException(__('The code is not valid.'));
        }

        $until = now()->addMinutes($this->settings->int(SettingKey::UserLoginLockoutMinutes));
        $user->forceFill(['failed_mfa_attempts' => 0, 'mfa_locked_until' => $until])->saveQuietly();
        Cache::forget($this->codeKey($user));
        $this->auditor->record('account.mfa_locked', $user, ['method' => $method->value, 'failed_attempts' => $attempts, 'locked_until' => $until->toIso8601String()], $user);

        throw new MfaException(self::lockedMessage($until));
    }

    /**
     * @throws MfaException
     */
    private function assertNotLocked(User $user, MfaMethod $method): void
    {
        if (! $this->isLocked($user)) {
            return;
        }

        $this->auditor->record('mfa.failed', $user, ['method' => $method->value, 'reason' => 'locked', 'locked_until' => $user->mfa_locked_until->toIso8601String()], $user);

        throw new MfaException(self::lockedMessage($user->mfa_locked_until));
    }

    /**
     * @throws MfaException
     */
    private function assertCanManage(User $user, MfaMethod $method): void
    {
        if (! $this->canManageFactors($user)) {
            throw new MfaException(__('Verify your sign-in with a second factor first.'));
        }

        if (! in_array($method, $this->allowedMethods(), true)) {
            throw new MfaException(__('This second factor is not allowed.'));
        }
    }

    private function factorAdded(User $user, MfaMethod $method): void
    {
        $this->auditor->record('mfa.factor_added', $user, ['method' => $method->value] + ($method === MfaMethod::Sms ? ['to' => self::mask((string) $user->mfa_sms_number)] : []), $user);

        // Proving a new factor on top of the first one is the second factor of this session.
        if (! $this->passedInSession($user)) {
            $this->markPassed($user, $method, ['via' => 'setup']);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function markPassed(User $user, ?MfaMethod $method, array $context = []): void
    {
        $session = session();
        $session->put(self::PASSED, ['user' => $user->getKey(), 'epoch' => (int) $user->session_epoch, 'at' => now()->getTimestamp()]);
        // A new identifier: a session id seen before the second factor is worth nothing after it.
        $session->migrate(true);

        $this->auditor->record('mfa.passed', $user, ['method' => $method?->value] + $context, $user);
    }

    private function pendingAppSecret(User $user): ?string
    {
        $stored = Cache::get($this->setupKey($user));

        if (! is_string($stored)) {
            return null;
        }

        $pending = json_decode(Crypt::decryptString($stored), true);

        return is_array($pending) && hash_equals((string) ($pending['session'] ?? ''), $this->sessionFingerprint()) ? (string) $pending['secret'] : null;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     *
     * @throws MfaException
     */
    /**
     * An over-long code is a typing slip or junk, never a try: refused here,
     * before the account lock, the hash work and the failure count, for
     * every caller (the pages check the same first, with a field error).
     *
     * @throws MfaException
     */
    private static function assertCodeInput(string $code, ?string $other = null): void
    {
        foreach ([$code, $other] as $value) {
            if ($value !== null && mb_strlen($value) > self::CODE_INPUT_MAX_LENGTH) {
                throw new MfaException(__('The code may be at most :max characters long.', ['max' => self::CODE_INPUT_MAX_LENGTH]));
            }
        }
    }

    private function underAccountLock(User $user, callable $work): mixed
    {
        try {
            return Cache::lock('hdid:mfa:'.$user->getKey(), 10)->block(5, function () use ($user, $work): mixed {
                $user->refresh();

                return $work();
            });
        } catch (LockTimeoutException) {
            throw new MfaException(__('Another check of this account is running. Try again in a moment.'));
        }
    }

    private function app(): AppAuthentication
    {
        return AppAuthentication::make()->codeWindow(self::TOTP_WINDOW);
    }

    private function codeKey(User $user): string
    {
        return 'hdid:mfa-code:'.$user->getKey();
    }

    private function setupKey(User $user): string
    {
        return 'hdid:mfa-setup:'.$user->getKey();
    }

    /**
     * Binds a sent code and a pending secret to the session that asked for
     * them; another browser signed in with the same password cannot use them.
     */
    private function sessionFingerprint(): string
    {
        return hash('sha256', session()->getId());
    }

    private static function digits(string $code): string
    {
        return (string) preg_replace('/\D+/', '', $code);
    }

    private static function lockedMessage(\DateTimeInterface $until): string
    {
        return __('Too many wrong codes: sign-in with a code is locked until :time.', ['time' => $until->format(HuDate::DATETIME)]);
    }

    /**
     * "+36 30 *** 4567" or "a***@example.org": enough to recognise, not to read out.
     */
    public static function mask(string $destination): string
    {
        if (str_contains($destination, '@')) {
            [$local, $domain] = explode('@', $destination, 2);

            return mb_substr($local, 0, 1).'***@'.$domain;
        }

        return mb_substr($destination, 0, 5).' *** '.mb_substr($destination, -4);
    }
}
