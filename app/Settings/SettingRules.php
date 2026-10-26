<?php

namespace App\Settings;

use App\Audit\AuditArchive;
use App\Auth\Mfa\StaffMfa;
use App\Auth\Oidc\OidcProvider;
use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Clients\PackageListImpact;
use App\Enums\MfaMethod;
use App\Enums\PrincipalType;
use App\Identification\AnswerMatcher;
use App\Identification\PinService;
use App\Identification\QaRetryLimit;
use App\Sync\RequestPayload;

/**
 * Validation rules per setting, applied when the panel saves. Business
 * floors live here so a typo (0 minutes, 3-digit code) cannot switch a
 * safeguard off; the enum keeps only types and defaults.
 */
class SettingRules
{
    /**
     * @return list<string>
     */
    public static function for(SettingKey $key): array
    {
        return match ($key) {
            SettingKey::ClientLoginMagicLinkTtlMinutes => ['integer', 'min:5', 'max:43200'],
            SettingKey::ClientLoginOtpSmsTtlMinutes => ['integer', 'min:1', 'max:60'],
            SettingKey::ClientLoginOtpSmsLength => ['integer', 'min:'.ClientPasswordlessLogin::OTP_MIN_LENGTH, 'max:'.ClientPasswordlessLogin::OTP_MAX_LENGTH],
            SettingKey::ClientLoginOtpMaxAttempts => ['integer', 'min:1', 'max:20'],
            SettingKey::ClientLoginOtpSmsMaxPerNumberPerMinute => ['integer', 'min:1', 'max:10'],
            SettingKey::ClientLoginOtpSmsMaxPerNumberPerHour => ['integer', 'min:1', 'max:60'],
            SettingKey::ClientLoginOtpSmsMaxPerNumberPerDay => ['integer', 'min:1', 'max:100'],
            SettingKey::ClientLoginOtpSmsMaxPerHour => ['integer', 'min:1', 'max:100000'],
            SettingKey::ClientLoginOtpSmsMaxPerDay => ['integer', 'min:1', 'max:1000000'],
            SettingKey::ClientLoginMagicLinkMaxPerHour => ['integer', 'min:0', 'max:1000000'],
            SettingKey::ClientLoginRememberDays, SettingKey::UserLoginRememberDays => ['integer', 'min:1', 'max:365'],
            SettingKey::ClientLoginSessionMaxHours, SettingKey::UserLoginSessionMaxHours => ['integer', 'min:1', 'max:720'],
            SettingKey::ClientLoginLockoutMaxAttempts, SettingKey::UserLoginLockoutMaxAttempts => ['integer', 'min:1', 'max:20'],
            SettingKey::ClientLoginLockoutMinutes, SettingKey::UserLoginLockoutMinutes => ['integer', 'min:1', 'max:43200'],
            SettingKey::SsoMobileTokenMaxAgeMinutes => ['integer', 'min:1', 'max:60'],
            SettingKey::QaMinAnsweredQuestionsRequired => ['integer', 'min:1', 'max:50'],
            SettingKey::QaMaxQuestionsPerSession => ['integer', 'min:1', 'max:20'],
            SettingKey::QaMinAcceptedToPass => ['integer', 'min:1', 'max:20'],
            SettingKey::QaMaxRejectedToFail => ['integer', 'min:1', 'max:20'],
            SettingKey::QaSessionTtlMinutes => ['integer', 'min:1', 'max:1440'],
            SettingKey::QaTypoTolerance => ['integer', 'min:0', 'max:'.AnswerMatcher::MAX_TOLERANCE],
            SettingKey::QaMaxUnsuccessfulSessions => ['integer', 'min:1', 'max:20'],
            SettingKey::QaUnsuccessfulWindowHours => ['integer', 'min:1', 'max:'.QaRetryLimit::MAX_WINDOW_HOURS],
            SettingKey::PhoneAllowedRegions => ['required', 'array', 'min:1', new RegionListRule],
            SettingKey::PinMinLength, SettingKey::PinMaxLength => ['integer', 'min:'.PinService::MIN_ALLOWED_LENGTH, 'max:'.PinService::MAX_ALLOWED_LENGTH],
            SettingKey::PinMaxFailedAttempts => ['integer', 'min:1', 'max:20'],
            SettingKey::PinLockoutMinutes => ['integer', 'min:1', 'max:1440'],
            SettingKey::MobileOtpLength, SettingKey::IvrCodeLength => ['integer', 'min:8', 'max:12'],
            SettingKey::MobileOtpTtlMinutes => ['integer', 'min:1', 'max:60'],
            SettingKey::SyncMissedRunsBeforeClose => ['integer', 'min:1', 'max:30'],
            SettingKey::SyncMinRowsRatioPercent => ['integer', 'min:0', 'max:100'],
            SettingKey::SyncExpectedIntervalHours => ['integer', 'min:1', 'max:720'],
            SettingKey::CallsRetentionHours => ['integer', 'min:1', 'max:8760'],
            SettingKey::CallsDashboardPollSeconds => ['integer', 'min:2', 'max:120'],
            SettingKey::RetentionGeneralYears => ['integer', 'min:1', 'max:30'],
            SettingKey::RetentionShortLivedYears => ['integer', 'min:1', 'max:30'],
            SettingKey::RetentionAuditHotDays => ['integer', 'min:'.AuditArchive::MIN_HOT_DAYS, 'max:3650'],
            SettingKey::SupportStandardEmail, SettingKey::SupportPremiumEmail => ['nullable', 'email'],
            SettingKey::PortalPrivacyUrl, SettingKey::PortalTermsUrl, SettingKey::PortalImprintUrl => ['nullable', 'url'],
            SettingKey::SyncCsvEncoding => ['nullable', 'string', 'max:40'],
            SettingKey::SyncExportPayload, SettingKey::SyncIdListPayload => ['nullable', 'string', new JsonPayloadRule],
            SettingKey::SyncIdListIdPath, SettingKey::SyncIdListNamePath => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_\-*]+(\.[A-Za-z0-9_\-*]+)*$/'],
            SettingKey::SyncIdListStatusPath => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)*$/'],
            default => $key->type() === SettingType::Integer ? ['integer', 'min:0'] : [],
        };
    }

    /**
     * Rules that span several keys. Returns field => message for violations.
     *
     * @param  array<string, mixed>  $values  keyed by SettingKey value
     * @return array<string, string>
     */
    public static function crossErrors(array $values): array
    {
        $int = fn (SettingKey $key): int => (int) ($values[$key->value] ?? $key->default());
        $errors = [];

        if ($int(SettingKey::PinMinLength) > $int(SettingKey::PinMaxLength)) {
            $errors[SettingKey::PinMaxLength->value] = __('The maximum PIN length must be at least the minimum PIN length.');
        }

        $errors = [...$errors, ...static::payloadErrors($values)];

        // Premium-only with no premium package would serve nobody at all.
        if (filter_var($values[SettingKey::PackagesPremiumOnly->value] ?? false, FILTER_VALIDATE_BOOL)
            && PackageListImpact::normalizeList((array) ($values[SettingKey::PackagesPremium->value] ?? [])) === []) {
            $errors[SettingKey::PackagesPremiumOnly->value] = __('Premium-only needs at least one package on the premium list; otherwise no client would be served.');
        }

        if ($int(SettingKey::QaMinAcceptedToPass) > $int(SettingKey::QaMaxQuestionsPerSession)) {
            $errors[SettingKey::QaMinAcceptedToPass->value] = __('Accepted answers needed to pass cannot exceed the questions per session.');
        }

        if ($int(SettingKey::QaMinAnsweredQuestionsRequired) < $int(SettingKey::QaMinAcceptedToPass)) {
            $errors[SettingKey::QaMinAnsweredQuestionsRequired->value] = __('A client must have answered at least as many questions as are needed to pass.');
        }

        if ($int(SettingKey::QaMaxRejectedToFail) > $int(SettingKey::QaMaxQuestionsPerSession)) {
            $errors[SettingKey::QaMaxRejectedToFail->value] = __('Rejections needed to fail cannot exceed the questions per session.');
        }

        return [...$errors, ...static::domainErrors($values), ...static::ssoErrors($values), ...static::mfaErrors($values)];
    }

    /**
     * Both EMD request bodies must be JSON objects with known placeholders;
     * an export using `{{ ids }}` needs a configured ID list query.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public static function payloadErrors(array $values): array
    {
        $errors = [];
        $export = $values[SettingKey::SyncExportPayload->value] ?? null;
        $idList = $values[SettingKey::SyncIdListPayload->value] ?? null;

        foreach ([
            [SettingKey::SyncExportPayload, $export, RequestPayload::EXPORT_PLACEHOLDERS, __('EMD export payload must be a JSON object.')],
            [SettingKey::SyncIdListPayload, $idList, RequestPayload::ID_LIST_PLACEHOLDERS, __('The ID list request payload must be a JSON object.')],
        ] as [$key, $payload, $allowed, $notObject]) {
            if (is_string($payload) && $payload !== '' && RequestPayload::isValidJson($payload)) {
                $error = RequestPayload::isJsonObject($payload) ? RequestPayload::unknownPlaceholderMessage($payload, $allowed) : $notObject;
                if ($error !== null) {
                    $errors[$key->value] = $error;
                }
            }
        }

        // An export with {{ ids }} saves in any order; the settings page lists what the
        // sync still needs, and the sync itself refuses to run until the ID list is usable.

        return $errors;
    }

    /** @param array<string, mixed> $values
     * @return array<string, string>
     */
    public static function domainErrors(array $values): array
    {
        if (! filter_var($values[SettingKey::SyncUniqueDomains->value] ?? true, FILTER_VALIDATE_BOOL)) {
            return [];
        }

        $overlap = array_intersect(
            self::normalizeDomains((array) ($values[SettingKey::SyncUserDomains->value] ?? [])),
            self::normalizeDomains((array) ($values[SettingKey::SyncClientDomains->value] ?? [])),
        );

        if ($overlap === []) {
            return [];
        }

        $message = __('Each domain may appear in only one list. Shared domains: :domains', ['domains' => implode(', ', $overlap)]);

        return [SettingKey::SyncUserDomains->value => $message, SettingKey::SyncClientDomains->value => $message];
    }

    /** @param array<array-key, mixed> $domains
     * @return list<string>
     */
    public static function normalizeDomains(array $domains): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($domain): string => self::normalizeDomain((string) $domain),
            $domains,
        ))));
    }

    /**
     * A domain as the classifier compares it: without whitespace, control or
     * invisible characters (a pasted no-break or zero-width space), without a
     * leading @, surrounding quotes or a trailing dot, in lower case.
     */
    public static function normalizeDomain(string $domain): string
    {
        $domain = preg_replace('/[\p{Z}\p{C}]+/u', '', $domain) ?? trim($domain);
        $domain = preg_replace('/^[@"\'„“”‘’]+|["\'„“”‘’.]+$/u', '', $domain) ?? $domain;

        return mb_strtolower($domain);
    }

    /**
     * Staff MFA needs at least one known method, and SMS only while a real
     * SMS gateway is connected (StaffMfa::smsAvailable): otherwise switching
     * it on could leave staff without a usable second factor.
     *
     * @param  array<string, mixed>  $values  keyed by SettingKey value
     * @return array<string, string>
     */
    public static function mfaErrors(array $values): array
    {
        $methods = (array) ($values[SettingKey::UserLoginMfaMethods->value] ?? SettingKey::UserLoginMfaMethods->default());
        $unknown = array_filter($methods, fn (mixed $method): bool => MfaMethod::tryFrom((string) $method) === null);
        $key = SettingKey::UserLoginMfaMethods->value;

        if ($unknown !== []) {
            return [$key => __('Unknown second factor: :list.', ['list' => implode(', ', array_map('strval', $unknown))])];
        }

        if (in_array(MfaMethod::Sms->value, $methods, true) && ! StaffMfa::smsAvailable()) {
            return [$key => __('SMS codes need a connected SMS gateway (SMS_DRIVER=ozeki); with the log driver they are only offered outside production.')];
        }

        if ($methods === [] && filter_var($values[SettingKey::UserLoginMfaEnabled->value] ?? false, FILTER_VALIDATE_BOOL)) {
            return [$key => __('Choose at least one second factor before switching two-factor sign-in on.')];
        }

        return [];
    }

    /**
     * An SSO provider can only be switched on when its .env entries are in
     * place, and admin password login cannot go away while no admin SSO
     * provider is on: otherwise nobody could sign in to the panel any more.
     *
     * @param  array<string, mixed>  $values  keyed by SettingKey value
     * @return array<string, string>
     */
    public static function ssoErrors(array $values): array
    {
        $bool = fn (SettingKey $key): bool => filter_var($values[$key->value] ?? $key->default(), FILTER_VALIDATE_BOOL);
        $errors = [];
        $adminSso = false;

        foreach (PrincipalType::cases() as $principal) {
            foreach (OidcProvider::cases() as $provider) {
                $key = $provider->enabledSetting($principal);

                if (! $bool($key)) {
                    continue;
                }

                if (! $provider->configFor($principal)->isConfigured()) {
                    $errors[$key->value] = __(':provider cannot be switched on: it is not configured on the server. Fill :keys in the .env file first.', [
                        'provider' => $provider->label(),
                        'keys' => implode(', ', $provider->envKeys($principal)),
                    ]);

                    continue;
                }

                $adminSso = $adminSso || $principal === PrincipalType::User;
            }
        }

        if (! $bool(SettingKey::UserLoginPasswordEnabled) && ! $adminSso) {
            $errors[SettingKey::UserLoginPasswordEnabled->value] = __('Admin password login cannot be switched off while no admin SSO provider is switched on and configured.');
        }

        return $errors;
    }
}
