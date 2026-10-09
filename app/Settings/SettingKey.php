<?php

namespace App\Settings;

/**
 * Every runtime-configurable business decision. Values are stored in the
 * `settings` table; the defaults below apply until an admin changes them.
 */
enum SettingKey: string
{
    // Branding (shared by the admin panel and the client site)
    case BrandingAppName = 'branding.app_name';
    case BrandingLogo = 'branding.logo';
    case BrandingFavicon = 'branding.favicon';
    case BrandingBackgroundImage = 'branding.background_image';
    case BrandingLoginBackgroundImage = 'branding.login_background_image';
    case BrandingAdminLoginBackgroundImage = 'branding.admin_login_background_image';
    case BrandingPrimaryColor = 'branding.primary_color';
    case BrandingAccentColor = 'branding.accent_color';
    case BrandingBackgroundColor = 'branding.background_color';
    case BrandingSurfaceColor = 'branding.surface_color';
    case BrandingTextColor = 'branding.text_color';
    case BrandingMutedTextColor = 'branding.muted_text_color';

    // Premium look of the client portal
    case PremiumAccentColor = 'premium.accent_color';
    case PremiumBadgeLabel = 'premium.badge_label';
    case PremiumBackgroundImage = 'premium.background_image';
    case PremiumLoginBackgroundImage = 'premium.login_background_image';

    // Client list: hide the directory-only accounts (no portal access, open, no history) by default
    case ClientsListRelevantOnly = 'clients.list_relevant_only';

    // Menu badge with the number of explicit-premium clients still waiting for a sponsor link
    case ClientsUnlinkedBadge = 'clients.unlinked_badge';
    case ClientsDirectoryFields = 'clients.directory_fields';

    // Packages that grant portal access, by service level
    case PackagesPremium = 'packages.premium';
    case PackagesStandard = 'packages.standard';

    // Support contact shown to clients, by service level
    case SupportStandardEmail = 'support.standard_email';
    case SupportStandardPhone = 'support.standard_phone';
    case SupportStandardHours = 'support.standard_hours';
    case SupportPremiumEmail = 'support.premium_email';
    case SupportPremiumPhone = 'support.premium_phone';
    case SupportPremiumHours = 'support.premium_hours';

    // Client portal content
    case PortalFooterText = 'portal.footer_text';
    case PortalLegalName = 'portal.legal_name';
    case PortalPrivacyUrl = 'portal.privacy_url';
    case PortalTermsUrl = 'portal.terms_url';
    case PortalImprintUrl = 'portal.imprint_url';
    case PortalNewsOnOverview = 'portal.news_on_overview';
    case PortalMaxPhoneNumbersPerClient = 'portal.max_phone_numbers_per_client';
    case PortalPhoneVerificationEnabled = 'portal.phone_verification_enabled';
    case PortalSharedNumberNotice = 'portal.shared_number_notice';

    // Client login methods
    case ClientLoginMagicLinkEnabled = 'client_login.magic_link.enabled';
    case ClientLoginMagicLinkTtlMinutes = 'client_login.magic_link.ttl_minutes';
    case ClientLoginMagicLinkPreviewInPanel = 'client_login.magic_link.preview_in_panel';
    case ClientLoginOtpSmsEnabled = 'client_login.otp_sms.enabled';
    case ClientLoginOtpSmsTtlMinutes = 'client_login.otp_sms.ttl_minutes';
    case ClientLoginOtpSmsLength = 'client_login.otp_sms.length';
    case ClientLoginOtpMaxAttempts = 'client_login.otp_sms.max_attempts';
    case ClientLoginOtpSmsMaxPerNumberPerMinute = 'client_login.otp_sms.max_per_number_per_minute';
    case ClientLoginOtpSmsMaxPerNumberPerHour = 'client_login.otp_sms.max_per_number_per_hour';
    case ClientLoginOtpSmsEmailFallbackEnabled = 'client_login.otp_sms.email_fallback_enabled';
    case ClientLoginRememberEnabled = 'client_login.remember.enabled';
    case ClientLoginRememberDays = 'client_login.remember.days';
    case ClientLoginSessionMaxHours = 'client_login.session_max_hours';
    case ClientLoginLockoutMaxAttempts = 'client_login.lockout.max_failed_attempts';
    case ClientLoginLockoutMinutes = 'client_login.lockout.minutes';

    // Staff login
    case UserLoginPasswordEnabled = 'user_login.password.enabled';
    case UserLoginRememberEnabled = 'user_login.remember.enabled';
    case UserLoginRememberDays = 'user_login.remember.days';
    case UserLoginSessionMaxHours = 'user_login.session_max_hours';
    case UserLoginLockoutMaxAttempts = 'user_login.lockout.max_failed_attempts';
    case UserLoginLockoutMinutes = 'user_login.lockout.minutes';

    // SSO providers (secrets and endpoints live in config/hdid.php)
    case UserSsoAdfsEnabled = 'sso.user.adfs.enabled';
    case UserSsoEntraEnabled = 'sso.user.entra.enabled';
    case ClientSsoAdfsEnabled = 'sso.client.adfs.enabled';
    case ClientSsoEntraEnabled = 'sso.client.entra.enabled';
    case SsoMobileTokenMaxAgeMinutes = 'sso.mobile_token_max_age_minutes';

    // Identification: question and answer sessions
    case QaMinAnsweredQuestionsRequired = 'identification.qa.min_answered_questions_required';
    case QaMaxQuestionsPerSession = 'identification.qa.max_questions_per_session';
    case QaMinAcceptedToPass = 'identification.qa.min_accepted_to_pass';
    case QaMaxRejectedToFail = 'identification.qa.max_rejected_to_fail';
    case QaSessionTtlMinutes = 'identification.qa.session_ttl_minutes';

    // Identification: PIN
    case PinMinLength = 'identification.pin.min_length';
    case PinMaxLength = 'identification.pin.max_length';
    case PinMaxFailedAttempts = 'identification.pin.max_failed_attempts';
    case PinLockoutMinutes = 'identification.pin.lockout_minutes';
    case PinAgentVerificationEnabled = 'identification.pin.agent_verification_enabled';
    case PinClientChangesEnabled = 'identification.pin.client_changes_enabled';
    case PinUniqueRequired = 'identification.pin.unique_required';

    // Identification: one-time code generated by the client (portal or mobile app), read to the IVR or the agent
    case MobileOtpLength = 'identification.mobile_otp.length';
    case MobileOtpTtlMinutes = 'identification.mobile_otp.ttl_minutes';
    case IvrCodeLength = 'identification.ivr_code.length';

    // Identification: how many code / PIN checks one agent may run per hour
    case AgentAttemptsPerHour = 'identification.agent_attempts_per_hour';

    // Identification: a client identified on a call proves the number the call came from
    case VerifyPhoneOnIdentifiedCall = 'identification.verify_phone_on_identified_call';

    // External sync
    case SyncMissedRunsBeforeClose = 'sync.missed_runs_before_close';
    case SyncUniqueDomains = 'sync.unique_domains';
    case SyncUserDomains = 'sync.user_email_domains';
    case SyncClientDomains = 'sync.client_email_domains';
    case SyncColumnMapping = 'sync.column_mapping';
    case SyncApiDataPath = 'sync.api.data_path';
    case SyncApiPageParam = 'sync.api.page_param';
    case SyncMinRowsRatioPercent = 'sync.min_rows_ratio_percent';
    case SyncExpectedIntervalHours = 'sync.expected_interval_hours';
    case SyncCsvEncoding = 'sync.csv_encoding';
    case SyncExportPayload = 'sync.export_payload';
    case SyncIdListPayload = 'sync.id_list_payload';
    case SyncIdListIdPath = 'sync.id_list_id_path';
    case SyncIdListNamePath = 'sync.id_list_name_path';
    case SyncIdListStatusPath = 'sync.id_list_status_path';

    // Call center
    case CallsRetentionHours = 'calls.retention_hours';
    case CallsDashboardPollSeconds = 'calls.dashboard_poll_seconds';
    case CallsQueueTiers = 'calls.queue_tiers';
    case CallsDefaultTier = 'calls.default_tier';

    // Data retention (years); the prune task runs daily
    case RetentionGeneralYears = 'retention.general_years';
    case RetentionShortLivedYears = 'retention.short_lived_years';
    case CallsStaleAfterHours = 'calls.stale_after_hours';

    public function type(): SettingType
    {
        return match ($this) {
            self::ClientLoginMagicLinkEnabled,
            self::ClientLoginMagicLinkPreviewInPanel,
            self::ClientLoginOtpSmsEnabled,
            self::UserLoginPasswordEnabled,
            self::PortalNewsOnOverview,
            self::PortalPhoneVerificationEnabled,
            self::PortalSharedNumberNotice,
            self::VerifyPhoneOnIdentifiedCall,
            self::UserSsoAdfsEnabled,
            self::UserSsoEntraEnabled,
            self::ClientSsoAdfsEnabled,
            self::ClientSsoEntraEnabled,
            self::UserLoginRememberEnabled,
            self::ClientLoginRememberEnabled,
            self::ClientLoginOtpSmsEmailFallbackEnabled,
            self::PinAgentVerificationEnabled,
            self::PinClientChangesEnabled,
            self::PinUniqueRequired,
            self::ClientsListRelevantOnly,
            self::SyncUniqueDomains,
            self::ClientsUnlinkedBadge => SettingType::Boolean,

            self::ClientLoginMagicLinkTtlMinutes,
            self::ClientLoginOtpSmsTtlMinutes,
            self::ClientLoginOtpSmsLength,
            self::ClientLoginOtpMaxAttempts,
            self::ClientLoginOtpSmsMaxPerNumberPerMinute,
            self::ClientLoginOtpSmsMaxPerNumberPerHour,
            self::ClientLoginRememberDays,
            self::ClientLoginSessionMaxHours,
            self::ClientLoginLockoutMaxAttempts,
            self::ClientLoginLockoutMinutes,
            self::UserLoginRememberDays,
            self::UserLoginSessionMaxHours,
            self::UserLoginLockoutMaxAttempts,
            self::UserLoginLockoutMinutes,
            self::SsoMobileTokenMaxAgeMinutes,
            self::QaMinAnsweredQuestionsRequired,
            self::QaMaxQuestionsPerSession,
            self::QaMinAcceptedToPass,
            self::QaMaxRejectedToFail,
            self::QaSessionTtlMinutes,
            self::PinMinLength,
            self::PinMaxLength,
            self::PinMaxFailedAttempts,
            self::PinLockoutMinutes,
            self::MobileOtpLength,
            self::IvrCodeLength,
            self::MobileOtpTtlMinutes,
            self::AgentAttemptsPerHour,
            self::SyncMissedRunsBeforeClose,
            self::SyncMinRowsRatioPercent,
            self::SyncExpectedIntervalHours,
            self::RetentionGeneralYears,
            self::RetentionShortLivedYears,
            self::CallsRetentionHours,
            self::CallsStaleAfterHours,
            self::PortalMaxPhoneNumbersPerClient,
            self::CallsDashboardPollSeconds => SettingType::Integer,

            self::BrandingPrimaryColor,
            self::BrandingAccentColor,
            self::BrandingBackgroundColor,
            self::BrandingSurfaceColor,
            self::BrandingTextColor,
            self::BrandingMutedTextColor,
            self::PremiumAccentColor => SettingType::Color,

            self::BrandingLogo,
            self::BrandingFavicon,
            self::BrandingBackgroundImage,
            self::BrandingLoginBackgroundImage,
            self::BrandingAdminLoginBackgroundImage,
            self::PremiumBackgroundImage,
            self::PremiumLoginBackgroundImage => SettingType::Image,

            self::PortalFooterText, self::SyncExportPayload, self::SyncIdListPayload => SettingType::LongText,

            self::SyncUserDomains,
            self::SyncClientDomains,
            self::PackagesPremium,
            self::PackagesStandard,
            self::CallsQueueTiers,
            self::ClientsDirectoryFields,
            self::SyncColumnMapping => SettingType::Json,

            default => SettingType::Text,
        };
    }

    public function default(): mixed
    {
        return match ($this) {
            self::BrandingAppName => 'Helpdesk ID',
            self::BrandingLogo, self::BrandingFavicon => null,
            self::BrandingBackgroundImage, self::BrandingLoginBackgroundImage, self::BrandingAdminLoginBackgroundImage => null,
            self::BrandingPrimaryColor => '#36495D',
            self::BrandingAccentColor => '#4F6478',
            self::BrandingBackgroundColor => '#F4F5F6',
            self::BrandingSurfaceColor => '#FFFFFF',
            self::BrandingTextColor => '#1E252D',
            self::BrandingMutedTextColor => '#5E6975',

            self::PremiumAccentColor => '#C9A227',
            self::PremiumBadgeLabel => null,
            self::PremiumBackgroundImage, self::PremiumLoginBackgroundImage => null,

            self::PackagesPremium, self::PackagesStandard => [],

            self::SupportStandardEmail, self::SupportStandardPhone, self::SupportStandardHours => null,
            self::SupportPremiumEmail, self::SupportPremiumPhone, self::SupportPremiumHours => null,

            self::PortalFooterText => null,
            self::PortalLegalName => null,
            self::PortalPrivacyUrl, self::PortalTermsUrl, self::PortalImprintUrl => null,
            self::PortalNewsOnOverview => true,
            self::PortalMaxPhoneNumbersPerClient => 5,
            self::PortalPhoneVerificationEnabled => false,
            self::PortalSharedNumberNotice => false,

            self::ClientLoginMagicLinkEnabled => true,
            self::ClientLoginMagicLinkPreviewInPanel => false,
            self::ClientLoginMagicLinkTtlMinutes => 720,
            self::ClientLoginOtpSmsEnabled => false,
            self::ClientLoginOtpSmsTtlMinutes => 5,
            self::ClientLoginOtpSmsLength => 6,
            self::ClientLoginOtpMaxAttempts => 5,
            self::ClientLoginOtpSmsMaxPerNumberPerMinute => 1,
            self::ClientLoginOtpSmsMaxPerNumberPerHour => 3,
            self::ClientLoginOtpSmsEmailFallbackEnabled => true,
            self::ClientLoginRememberEnabled, self::UserLoginRememberEnabled => false,
            self::ClientLoginRememberDays, self::UserLoginRememberDays => 30,
            self::ClientLoginSessionMaxHours => 24,
            self::UserLoginSessionMaxHours => 12,
            self::ClientLoginLockoutMaxAttempts, self::UserLoginLockoutMaxAttempts => 5,
            self::ClientLoginLockoutMinutes, self::UserLoginLockoutMinutes => 120,
            self::SsoMobileTokenMaxAgeMinutes => 15,

            self::UserLoginPasswordEnabled => true,

            self::UserSsoAdfsEnabled,
            self::UserSsoEntraEnabled,
            self::ClientSsoAdfsEnabled,
            self::ClientSsoEntraEnabled => false,

            self::QaMinAnsweredQuestionsRequired => 5,
            self::QaMaxQuestionsPerSession => 4,
            self::QaMinAcceptedToPass => 2,
            self::QaMaxRejectedToFail => 2,
            self::QaSessionTtlMinutes => 30,

            self::PinMinLength => 6,
            self::PinMaxLength => 10,
            self::PinMaxFailedAttempts => 5,
            self::PinLockoutMinutes => 30,
            self::PinAgentVerificationEnabled => false,
            self::PinClientChangesEnabled => false,
            self::PinUniqueRequired => true,

            self::ClientsListRelevantOnly => true,
            self::ClientsUnlinkedBadge => true,
            self::ClientsDirectoryFields => [],

            self::MobileOtpLength, self::IvrCodeLength => 8,
            self::MobileOtpTtlMinutes => 5,
            self::AgentAttemptsPerHour => 50,
            self::VerifyPhoneOnIdentifiedCall => false,

            self::SyncMissedRunsBeforeClose => 2,
            self::SyncMinRowsRatioPercent => 50,
            self::SyncExpectedIntervalHours => 24,
            self::SyncCsvEncoding => 'UTF-8',
            self::SyncExportPayload => null,
            self::SyncIdListPayload => null,
            self::SyncIdListIdPath => 'result.data.id',
            self::SyncIdListNamePath => 'result.data.name',
            self::SyncIdListStatusPath => null,
            self::SyncUserDomains => [],
            self::SyncClientDomains => [],
            self::SyncUniqueDomains => true,
            self::SyncApiDataPath => 'data',
            self::SyncApiPageParam => null,
            self::SyncColumnMapping => [
                'external_id' => 'external_user_id',
                'name' => 'name',
                'email' => 'email',
                'company' => 'company',
                'title' => 'title',
                'department' => 'department',
                'login_name' => 'login_name',
                'room' => 'room',
                'employment_status' => 'employment_status',
                'phones' => 'phone',
                'implicit_package' => 'implicit_package',
                'explicit_package' => 'explicit_package',
            ],

            self::CallsRetentionHours => 24,
            self::CallsDashboardPollSeconds => 5,
            self::CallsQueueTiers => [],
            self::CallsDefaultTier => 'premium',

            self::RetentionGeneralYears => 5,
            self::RetentionShortLivedYears => 2,
            self::CallsStaleAfterHours => 6,
        };
    }

    /**
     * The EMD request settings: they decide what is imported and, through
     * missed runs, what is closed, so only sync managers may change them.
     */
    public function requiresSyncManage(): bool
    {
        return in_array($this, [
            self::SyncExportPayload, self::SyncIdListPayload,
            self::SyncIdListIdPath, self::SyncIdListNamePath, self::SyncIdListStatusPath,
        ], true);
    }

    public function group(): string
    {
        return explode('.', $this->value, 2)[0];
    }

    public function label(): string
    {
        return __(ucfirst(str_replace(['_', '.'], ' ', explode('.', $this->value, 2)[1])));
    }

    public function groupLabel(): string
    {
        return __(ucfirst(str_replace('_', ' ', $this->group())));
    }
}
