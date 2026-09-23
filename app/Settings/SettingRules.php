<?php

namespace App\Settings;

use App\Auth\Oidc\OidcProvider;
use App\Enums\PrincipalType;
use App\Identification\PinService;

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
            SettingKey::ClientLoginOtpSmsLength => ['integer', 'min:4', 'max:10'],
            SettingKey::ClientLoginOtpMaxAttempts => ['integer', 'min:1', 'max:20'],
            SettingKey::QaMinAnsweredQuestionsRequired => ['integer', 'min:1', 'max:50'],
            SettingKey::QaMaxQuestionsPerSession => ['integer', 'min:1', 'max:20'],
            SettingKey::QaMinAcceptedToPass => ['integer', 'min:1', 'max:20'],
            SettingKey::QaMaxRejectedToFail => ['integer', 'min:1', 'max:20'],
            SettingKey::QaSessionTtlMinutes => ['integer', 'min:1', 'max:1440'],
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
            SettingKey::SupportStandardEmail, SettingKey::SupportPremiumEmail => ['nullable', 'email'],
            SettingKey::PortalPrivacyUrl, SettingKey::PortalTermsUrl, SettingKey::PortalImprintUrl => ['nullable', 'url'],
            SettingKey::SyncCsvEncoding => ['nullable', 'string', 'max:40'],
            SettingKey::SyncExportPayload => ['nullable', 'string', 'json'],
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

        $payload = $values[SettingKey::SyncExportPayload->value] ?? null;
        if (is_string($payload) && $payload !== '' && json_validate($payload) && ! (json_decode($payload) instanceof \stdClass)) {
            $errors[SettingKey::SyncExportPayload->value] = __('EMD export payload must be a JSON object.');
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

        return [...$errors, ...static::domainErrors($values), ...static::ssoErrors($values)];
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
            fn ($domain): string => mb_strtolower(trim(ltrim(trim((string) $domain), '@'))),
            $domains,
        ))));
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
