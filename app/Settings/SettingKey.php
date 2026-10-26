<?php

namespace App\Settings;

use App\Audit\AuditArchive;
use App\Identification\AnswerMatcher;
use App\Identification\PinService;

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
    // Serve premium clients only: the standard list stops granting anything
    case PackagesPremiumOnly = 'packages.premium_only';

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
    // Login links anonymous requests may send per hour, all clients together (0 = no limit)
    case ClientLoginMagicLinkMaxPerHour = 'client_login.magic_link.max_per_hour';
    case ClientLoginOtpSmsEnabled = 'client_login.otp_sms.enabled';
    case ClientLoginOtpSmsTtlMinutes = 'client_login.otp_sms.ttl_minutes';
    case ClientLoginOtpSmsLength = 'client_login.otp_sms.length';
    case ClientLoginOtpMaxAttempts = 'client_login.otp_sms.max_attempts';
    case ClientLoginOtpSmsMaxPerNumberPerMinute = 'client_login.otp_sms.max_per_number_per_minute';
    case ClientLoginOtpSmsMaxPerNumberPerHour = 'client_login.otp_sms.max_per_number_per_hour';
    case ClientLoginOtpSmsMaxPerNumberPerDay = 'client_login.otp_sms.max_per_number_per_day';
    // SMS login codes anonymous requests may send, all clients together, per hour and per day
    case ClientLoginOtpSmsMaxPerHour = 'client_login.otp_sms.max_per_hour';
    case ClientLoginOtpSmsMaxPerDay = 'client_login.otp_sms.max_per_day';
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
    case UserLoginMfaEnabled = 'user_login.mfa.enabled';
    case UserLoginMfaMethods = 'user_login.mfa.methods';
    case UserLoginMfaTrustProvider = 'user_login.mfa.trust_provider_mfa';

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
    case QaBlindCheck = 'identification.qa.blind_check';
    case QaTypoTolerance = 'identification.qa.typo_tolerance';
    // Unsuccessful sessions of one client within a period (failed, undecided, or cancelled or expired
    // after a shown question that was not accepted), before a new one waits
    case QaMaxUnsuccessfulSessions = 'identification.qa.max_unsuccessful_sessions';
    case QaUnsuccessfulWindowHours = 'identification.qa.unsuccessful_window_hours';

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

    // Phone numbers: the countries the portal takes numbers of; text messages go
    // only to their mobile numbers (SmsDestinations)
    case PhoneAllowedRegions = 'phone.allowed_regions';

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
    // Days the audit log stays in the database; older days move to archive files
    case RetentionAuditHotDays = 'retention.audit_hot_days';
    case CallsStaleAfterHours = 'calls.stale_after_hours';

    public function type(): SettingType
    {
        return match ($this) {
            self::ClientLoginMagicLinkEnabled,
            self::ClientLoginMagicLinkPreviewInPanel,
            self::ClientLoginOtpSmsEnabled,
            self::UserLoginPasswordEnabled,
            self::UserLoginMfaEnabled,
            self::UserLoginMfaTrustProvider,
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
            self::QaBlindCheck,
            self::PinClientChangesEnabled,
            self::PinUniqueRequired,
            self::ClientsListRelevantOnly,
            self::SyncUniqueDomains,
            self::ClientsUnlinkedBadge,
            self::PackagesPremiumOnly => SettingType::Boolean,

            self::ClientLoginMagicLinkTtlMinutes,
            self::ClientLoginOtpSmsTtlMinutes,
            self::ClientLoginOtpSmsLength,
            self::ClientLoginOtpMaxAttempts,
            self::ClientLoginOtpSmsMaxPerNumberPerMinute,
            self::ClientLoginOtpSmsMaxPerNumberPerHour,
            self::ClientLoginOtpSmsMaxPerNumberPerDay,
            self::ClientLoginOtpSmsMaxPerHour,
            self::ClientLoginOtpSmsMaxPerDay,
            self::ClientLoginMagicLinkMaxPerHour,
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
            self::QaTypoTolerance,
            self::QaMaxUnsuccessfulSessions,
            self::QaUnsuccessfulWindowHours,
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
            self::RetentionAuditHotDays,
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
            self::UserLoginMfaMethods,
            self::PhoneAllowedRegions,
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
            self::PackagesPremiumOnly => false,

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
            self::ClientLoginOtpSmsMaxPerNumberPerDay => 5,
            self::ClientLoginOtpSmsMaxPerHour => 60,
            self::ClientLoginOtpSmsMaxPerDay => 300,
            self::ClientLoginMagicLinkMaxPerHour => 10000,
            self::ClientLoginOtpSmsEmailFallbackEnabled => true,
            self::ClientLoginRememberEnabled, self::UserLoginRememberEnabled => false,
            self::ClientLoginRememberDays, self::UserLoginRememberDays => 30,
            self::ClientLoginSessionMaxHours => 24,
            self::UserLoginSessionMaxHours => 12,
            self::ClientLoginLockoutMaxAttempts, self::UserLoginLockoutMaxAttempts => 5,
            self::ClientLoginLockoutMinutes, self::UserLoginLockoutMinutes => 120,
            self::UserLoginMfaEnabled => false,
            self::UserLoginMfaMethods => ['app', 'email'],
            self::UserLoginMfaTrustProvider => true,
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
            self::QaBlindCheck => false,
            self::QaTypoTolerance => 1,
            self::QaMaxUnsuccessfulSessions => 3,
            self::QaUnsuccessfulWindowHours => 24,

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
            self::PhoneAllowedRegions => ['HU'],

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
            self::RetentionAuditHotDays => 150,
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

    /**
     * The block of the settings page the key is shown in.
     */
    public function section(): SettingSection
    {
        return SettingSection::of($this);
    }

    /**
     * The switch the setting only matters under: the page shows it below
     * that switch, and only while the switch is on (a hidden setting keeps
     * its stored value).
     */
    public function dependsOn(): ?self
    {
        return match ($this) {
            self::ClientLoginMagicLinkTtlMinutes, self::ClientLoginMagicLinkPreviewInPanel, self::ClientLoginMagicLinkMaxPerHour => self::ClientLoginMagicLinkEnabled,
            self::ClientLoginOtpSmsLength, self::ClientLoginOtpSmsTtlMinutes, self::ClientLoginOtpMaxAttempts,
            self::ClientLoginOtpSmsMaxPerNumberPerMinute, self::ClientLoginOtpSmsMaxPerNumberPerHour, self::ClientLoginOtpSmsMaxPerNumberPerDay,
            self::ClientLoginOtpSmsMaxPerHour, self::ClientLoginOtpSmsMaxPerDay,
            self::ClientLoginOtpSmsEmailFallbackEnabled, self::ClientLoginLockoutMaxAttempts, self::ClientLoginLockoutMinutes => self::ClientLoginOtpSmsEnabled,
            self::ClientLoginRememberDays => self::ClientLoginRememberEnabled,
            self::UserLoginMfaMethods, self::UserLoginMfaTrustProvider => self::UserLoginMfaEnabled,
            self::UserLoginRememberDays => self::UserLoginRememberEnabled,
            self::QaTypoTolerance => self::QaBlindCheck,
            default => null,
        };
    }

    /**
     * The settings shown under this switch.
     *
     * @return list<self>
     */
    public function dependents(): array
    {
        return array_values(array_filter(self::cases(), fn (self $key): bool => $key->dependsOn() === $this));
    }

    /**
     * The unit shown inside a number field, instead of in its label.
     */
    public function unit(): ?string
    {
        return match ($this) {
            self::ClientLoginMagicLinkTtlMinutes, self::ClientLoginOtpSmsTtlMinutes, self::ClientLoginLockoutMinutes,
            self::UserLoginLockoutMinutes, self::SsoMobileTokenMaxAgeMinutes, self::QaSessionTtlMinutes,
            self::PinLockoutMinutes, self::MobileOtpTtlMinutes => __('setting-unit.minutes'),
            self::ClientLoginSessionMaxHours, self::UserLoginSessionMaxHours, self::SyncExpectedIntervalHours,
            self::CallsRetentionHours, self::CallsStaleAfterHours, self::QaUnsuccessfulWindowHours => __('setting-unit.hours'),
            self::ClientLoginRememberDays, self::UserLoginRememberDays, self::RetentionAuditHotDays => __('setting-unit.days'),
            self::RetentionGeneralYears, self::RetentionShortLivedYears => __('setting-unit.years'),
            self::CallsDashboardPollSeconds => __('setting-unit.seconds'),
            self::SyncMinRowsRatioPercent => '%',
            self::PinMinLength, self::PinMaxLength, self::MobileOtpLength, self::IvrCodeLength, self::ClientLoginOtpSmsLength => __('setting-unit.digits'),
            self::QaTypoTolerance => __('setting-unit.characters'),
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PackagesPremium => __('Premium packages'),
            self::PackagesStandard => __('Standard packages'),
            self::QaMinAnsweredQuestionsRequired => __('Answered questions required'),
            self::QaMaxQuestionsPerSession => __('Questions per session, at most'),
            self::QaMinAcceptedToPass => __('Accepted answers to pass'),
            self::QaMaxRejectedToFail => __('Rejected answers to fail'),
            self::QaSessionTtlMinutes => __('Session lifetime'),
            self::QaBlindCheck => __('Blind check'),
            self::QaTypoTolerance => __('Allowed typos'),
            self::QaMaxUnsuccessfulSessions => __('Unsuccessful sessions per client, at most'),
            self::QaUnsuccessfulWindowHours => __('Unsuccessful sessions counted over'),
            self::PhoneAllowedRegions => __('Accepted countries (phone numbers)'),
            self::PinMinLength => __('Shortest PIN'),
            self::PinMaxLength => __('Longest PIN'),
            self::PinMaxFailedAttempts => __('Lock after this many wrong PINs'),
            self::PinLockoutMinutes, self::ClientLoginLockoutMinutes, self::UserLoginLockoutMinutes => __('Lockout duration'),
            self::PinAgentVerificationEnabled => __('Agents may check the PIN'),
            self::PinClientChangesEnabled => __('Clients manage their own PIN'),
            self::PinUniqueRequired => __('Unique PINs'),
            self::MobileOtpLength => __('One-time identification code length'),
            self::MobileOtpTtlMinutes => __('One-time identification code lifetime'),
            self::IvrCodeLength => __('IVR code length'),
            self::AgentAttemptsPerHour => __('Failed checks per agent per hour'),
            self::PortalMaxPhoneNumbersPerClient => __('Own phone numbers per client, at most'),
            self::PortalPhoneVerificationEnabled => __('SMS confirmation on the portal'),
            self::VerifyPhoneOnIdentifiedCall => __('An identified call confirms the number'),
            self::PortalSharedNumberNotice => __('Tell the client about a shared number'),
            self::ClientLoginMagicLinkEnabled => __('Login link by e-mail'),
            self::ClientLoginMagicLinkTtlMinutes => __('Link lifetime'),
            self::ClientLoginMagicLinkPreviewInPanel => __('Show the link in the panel after sending'),
            self::ClientLoginOtpSmsEnabled => __('Login code by SMS'),
            self::ClientLoginOtpSmsLength => __('Code length'),
            self::ClientLoginOtpSmsTtlMinutes => __('Code lifetime'),
            self::ClientLoginOtpMaxAttempts => __('Attempts per code'),
            self::ClientLoginOtpSmsMaxPerNumberPerMinute => __('Codes to one number per minute, at most'),
            self::ClientLoginOtpSmsMaxPerNumberPerHour => __('Codes to one number per hour, at most'),
            self::ClientLoginOtpSmsMaxPerNumberPerDay => __('Codes to one number per day, at most'),
            self::ClientLoginOtpSmsMaxPerHour => __('Codes per hour in total, at most'),
            self::ClientLoginOtpSmsMaxPerDay => __('Codes per day in total, at most'),
            self::ClientLoginMagicLinkMaxPerHour => __('Login links per hour in total, at most'),
            self::ClientLoginOtpSmsEmailFallbackEnabled => __('E-mailed link as a fallback'),
            self::ClientLoginLockoutMaxAttempts => __('Lock after this many wrong codes'),
            self::UserLoginLockoutMaxAttempts => __('Lock after this many wrong passwords or codes'),
            self::ClientSsoEntraEnabled, self::UserSsoEntraEnabled => __('Company sign-in: Microsoft Entra ID'),
            self::ClientSsoAdfsEnabled, self::UserSsoAdfsEnabled => __('Company sign-in: ADFS'),
            self::SsoMobileTokenMaxAgeMinutes => __('Mobile app: maximum age of the Microsoft token'),
            self::ClientLoginSessionMaxHours, self::UserLoginSessionMaxHours => __('Maximum session length'),
            self::ClientLoginRememberEnabled, self::UserLoginRememberEnabled => __('Remember me'),
            self::ClientLoginRememberDays, self::UserLoginRememberDays => __('Remember me for'),
            self::PortalNewsOnOverview => __('News on the overview page'),
            self::PortalFooterText => __('Footer text of the portal'),
            self::PortalPrivacyUrl => __('Privacy notice address'),
            self::PortalTermsUrl => __('Terms of use address'),
            self::PortalImprintUrl => __('Imprint address'),
            self::UserLoginPasswordEnabled => __('Password sign-in'),
            self::UserLoginMfaEnabled => __('Two-factor sign-in (MFA)'),
            self::SyncUserDomains => __('Staff e-mail domains'),
            self::SyncClientDomains => __('Client e-mail domains'),
            self::SyncMinRowsRatioPercent => __('Minimum share of rows'),
            self::SyncExpectedIntervalHours => __('Expected interval'),
            self::SyncApiDataPath => __('Path of the records in the response'),
            self::SyncApiPageParam => __('Page number parameter'),
            self::BrandingBackgroundImage, self::PremiumBackgroundImage => __('Portal background'),
            self::BrandingLoginBackgroundImage, self::PremiumLoginBackgroundImage => __('Login page background'),
            self::BrandingAdminLoginBackgroundImage => __('Staff login page background'),
            self::PremiumAccentColor => __('Premium accent colour'),
            self::PremiumBadgeLabel => __('Premium badge label'),
            self::CallsDashboardPollSeconds => __('Dashboard refresh'),
            self::CallsRetentionHours => __('Finished calls stay on the dashboard'),
            self::CallsStaleAfterHours => __('Close unfinished calls after'),
            self::RetentionGeneralYears => __('General retention'),
            self::RetentionShortLivedYears => __('Short-lived data'),
            self::RetentionAuditHotDays => __('Audit log in the database'),
            default => $this->generatedLabel(),
        };
    }

    /**
     * The label made from the key itself, as the page showed it before the
     * settings were regrouped (2026-10-23); the search still finds it.
     */
    public function generatedLabel(): string
    {
        return __(ucfirst(str_replace(['_', '.'], ' ', explode('.', $this->value, 2)[1])));
    }

    /**
     * One short sentence under the field: what the setting does. The finer
     * points are in details(), behind the info icon.
     */
    public function help(): ?string
    {
        return match ($this) {
            self::PackagesPremium => __('Clients whose implicit or explicit package is listed here get the premium portal. Matching ignores case, accents and extra spaces.'),
            self::PackagesStandard => __('Clients whose package is listed here get the standard portal. Clients in neither list cannot sign in.'),
            self::PackagesPremiumOnly => __('Only premium clients are served; nothing is deleted, and switching it off brings everything back.'),
            self::CallsQueueTiers => __('One phone number is one call queue. Only needed once a second call center (the standard line) is connected; queues not listed here get the default level below.'),
            self::CallsDefaultTier => __('The level a call gets when its queue is not listed below and the caller is unknown. While only the premium call center is connected, leave it on premium.'),
            self::ClientsListRelevantOnly => __('The Clients list opens with the accounts that have portal access or any history.'),
            self::ClientsUnlinkedBadge => __('Shows a red counter next to the Clients menu item with the number of explicit-premium clients that have no sponsor link yet. The tab on the list keeps its counter either way.'),
            self::ClientsDirectoryFields => __('Further EMD columns shown on the client page and the list, and offered in the export.'),
            self::QaMinAnsweredQuestionsRequired => __('A client can be identified by questions once this many are answered.'),
            self::QaBlindCheck => __('The stored answer is hidden: the agent types what the caller says, and the system compares.'),
            self::QaTypoTolerance => __('How many typos still count as a match (0–:max).', ['max' => AnswerMatcher::MAX_TOLERANCE]),
            self::QaMaxUnsuccessfulSessions => __('Unsuccessful question-and-answer sessions one client may have within the period below (failed, undecided, or cancelled or expired with a shown question that was not accepted); then no new one starts until the oldest leaves the period.'),
            self::QaUnsuccessfulWindowHours => __('The period over which the unsuccessful question-and-answer sessions of a client are counted.'),
            self::PhoneAllowedRegions => __('Clients may add phone numbers of these countries on the portal, and text messages go only to their mobile numbers; the helpdesk and EMD may record any number.'),
            self::PinMinLength, self::PinMaxLength => __('When a PIN is set or replaced (:min–:max digits); existing PINs stay valid.', ['min' => PinService::MIN_ALLOWED_LENGTH, 'max' => PinService::MAX_ALLOWED_LENGTH]),
            self::PinMaxFailedAttempts => __('Wrong PINs in a row before the PIN is locked; a repeated lock doubles the time, up to 24 hours.'),
            self::PinAgentVerificationEnabled => __('Agents may also check the PIN the caller tells them; their role needs the PIN check permission as well.'),
            self::PinClientChangesEnabled => __('Clients may set, replace and remove their own PIN on the portal.'),
            self::PinUniqueRequired => __('A new PIN must differ from every other client\'s; existing PINs are not changed.'),
            self::MobileOtpLength => __('Digits of the one-time identification code; shown in pairs, the second digit of a pair is never zero.'),
            self::IvrCodeLength => __('Length of new IVR codes requested by the mobile app backend (8–12 digits), independent of the portal one-time identification code.'),
            self::AgentAttemptsPerHour => __('Failed code and PIN checks one agent may run in an hour; successful checks do not count.').' '.__('Answers of the blind question-and-answer check that do not match count as well.'),
            self::PortalMaxPhoneNumbersPerClient => __('Phone numbers a client may add on the portal.'),
            self::PortalPhoneVerificationEnabled => __('The client confirms a number added on the portal with an SMS code.'),
            self::VerifyPhoneOnIdentifiedCall => __('The number of a caller identified on a call becomes confirmed.'),
            self::PortalSharedNumberNotice => __('The portal tells the client when another client has the same number on file.'),
            self::ClientLoginMagicLinkEnabled => __('A one-time sign-in link by e-mail; the client page can send one too.'),
            self::ClientLoginMagicLinkTtlMinutes => __('In minutes; 720 = 12 hours.'),
            self::ClientLoginMagicLinkPreviewInPanel => __('For test environments only: keep it off in production.'),
            self::ClientLoginOtpSmsEnabled => __('A one-time code by SMS to the client\'s primary phone number.'),
            self::ClientLoginOtpSmsMaxPerNumberPerMinute => __('Codes sent to one number in a minute.'),
            self::ClientLoginOtpSmsMaxPerNumberPerHour => __('Codes sent to one number in an hour.'),
            self::ClientLoginOtpSmsMaxPerNumberPerDay => __('Codes sent to one number in 24 hours.'),
            self::ClientLoginOtpSmsMaxPerHour => __('SMS codes the login page may send in an hour, all clients together.'),
            self::ClientLoginOtpSmsMaxPerDay => __('SMS codes the login page may send in 24 hours, all clients together.'),
            self::ClientLoginMagicLinkMaxPerHour => __('Login links the login page may send in an hour, all clients together; 0 means no limit.'),
            self::ClientLoginOtpSmsEmailFallbackEnabled => __('When the code does not arrive, the login page offers an e-mailed link once; works only with the login link switched on.'),
            self::ClientLoginLockoutMaxAttempts => __('Wrong SMS codes in a row before the SMS login of the client is locked. Single sign-on and the e-mail link stay usable.'),
            // The second-factor codes count against the same numbers (StaffMfa), so this is not tied to the password switch.
            self::UserLoginLockoutMaxAttempts => __('Wrong passwords, or wrong second-factor codes, in a row before that step of the staff sign-in is locked; each is counted on its own. Single sign-on stays usable.'),
            self::ClientLoginLockoutMinutes, self::UserLoginLockoutMinutes => __('How long the lockout lasts, in minutes (120 = 2 hours). Staff with the user or client management permission can unlock it earlier on the account page.'),
            self::ClientSsoEntraEnabled => __('Clients sign in with their company Microsoft account; the tenants are listed in the installation (CLIENT_ENTRA_TENANTS).'),
            self::ClientSsoAdfsEnabled => __('Clients sign in through the company ADFS.'),
            self::UserSsoEntraEnabled => __('Staff sign in with their company Microsoft account.'),
            self::UserSsoAdfsEnabled => __('Staff sign in through the company ADFS.'),
            self::SsoMobileTokenMaxAgeMinutes => __('The Microsoft ID token of the mobile app is accepted once, and only within this many minutes of its issue.'),
            self::ClientLoginSessionMaxHours, self::UserLoginSessionMaxHours => __('A browser session ends this many hours after sign-in, however active it is; the idle limit of the session applies as well.'),
            self::ClientLoginRememberEnabled, self::UserLoginRememberEnabled => __('The browser stays signed in beyond the session limit.'),
            self::ClientLoginRememberDays, self::UserLoginRememberDays => __('How long a remembered sign-in lasts, in days.'),
            self::PortalFooterText => __('Shown at the bottom of the client site; line breaks are kept.'),
            self::UserLoginPasswordEnabled => __('Staff may sign in with e-mail and password; it cannot be switched off while no staff single sign-on works.'),
            self::UserLoginMfaEnabled => __('Every staff sign-in asks for a second factor, once per new session.'),
            self::UserLoginMfaTrustProvider => __('A company sign-in that already asked for a second factor needs no other.'),
            self::SyncUserDomains => __('Rows with these e-mail domains become staff users.'),
            self::SyncClientDomains => __('Rows with these e-mail domains become clients.'),
            self::SyncUniqueDomains => __('Enabled: a domain may appear in only one list. Disabled: shared domains are classified as staff. Domains are saved in lowercase; unlisted domains are skipped.'),
            self::SyncMissedRunsBeforeClose => __('Accounts close after their record is missing from this many syncs in a row.'),
            self::SyncMinRowsRatioPercent => __('A run is refused when it reads fewer rows than this share of the previous successful run (0 = off). Protects against a truncated export closing accounts.'),
            self::SyncExpectedIntervalHours => __('How often EMD sync is expected to run; the status page fails when no successful run happened in twice this time.'),
            self::SyncExportPayload => __('The JSON body of the export request; {{ now }} and {{ ids }} are filled in when it is sent. Only staff who manage EMD sync can edit it.'),
            self::SyncIdListPayload => __('JSON request body of the ID list query (EMD_SYNC_ID_LIST_URL), sent with the export\'s token; {{ now }} works here too. Only staff who manage EMD sync can edit it.'),
            self::SyncIdListIdPath => __('Where the IDs are in the response, as a dotted path. A list is crossed by name (result.data.id reads data.id of every element of result) or with *. Default: result.data.id.'),
            self::SyncIdListNamePath => __('Where the names are, read the same way; an entry without a name shows its ID. Default: result.data.name.'),
            self::SyncIdListStatusPath => __('Where the status code is in the response body, e.g. statusCode. Empty: the HTTP status code of the response. The list is accepted only with a 2xx status.'),
            self::SyncColumnMapping => __('Which source column fills which field; an empty one looks for the field\'s own name.'),
            self::SyncCsvEncoding => __('Character set of uploaded CSV files, e.g. UTF-8, Windows-1250 or ISO-8859-2.'),
            self::SyncApiDataPath => __('Where the records are in the JSON response, as a dotted path.'),
            self::SyncApiPageParam => __('The page number parameter of the request; empty for an endpoint without paging.'),
            self::CallsDashboardPollSeconds => __('How often the dashboard lists refresh.'),
            self::CallsRetentionHours => __('Finished calls leave the dashboard after this; the Calls list and the reports keep them.'),
            self::CallsStaleAfterHours => __('A call whose end the call center never reported (lost event, PBX restart) is closed as missed after this many hours, so it does not stay on the dashboard forever.'),
            self::RetentionGeneralYears => __('Identification sessions, calls, outbound messages, EMD sync runs and the audit archive files older than this are deleted for good.'),
            self::RetentionShortLivedYears => __('Expired one-time codes, uploaded EMD files and the EMD change records of sync runs older than this are deleted.'),
            self::RetentionAuditHotDays => __('Audit entries older than this many days (at least :min) are moved by the nightly task from the database into daily compressed archive files (Audit archive page) and deleted from the database; the archive is kept for the general retention period.', ['min' => AuditArchive::MIN_HOT_DAYS]),
            default => null,
        };
    }

    /**
     * The finer points, behind the info icon next to the label.
     */
    public function details(): ?string
    {
        return match ($this) {
            self::PackagesPremiumOnly => __('On: only clients whose implicit or explicit package (or a temporary override) is on the premium list are served. Standard clients cannot sign in to the portal, the phone menu does not recognise them, and no staff view shows them; a thin bar on top of the panel says so. Nothing is deleted or closed, and the sync keeps importing them: switching off brings everything back. Switching on asks for confirmation with the number of clients affected.'),
            self::ClientsListRelevantOnly => __('The Clients list opens with the relevant accounts only: portal access, closed, or any history (PIN, answers, calls, identifications, links, notes). EMD-only accounts stay reachable through the list filter and the search.'),
            self::ClientsDirectoryFields => __('Columns of EMD that are not mapped to a fixed field arrive as "other columns" of the EMD record. List here the ones the helpdesk should see on the client page and list and be able to export; the key is the column header as EMD sends it, the value is the label shown.'),
            self::QaBlindCheck => __('On: during question-and-answer identification the stored answer is hidden. The agent types what the caller says and the system compares it (case, accents, punctuation and spaces do not count, digits must be the same, a few typos are allowed as set below); the answer is shown only when it matches. A match accepts the question, a clearly different answer rejects it, a close one gets one retry and then counts as "cannot decide". The agent cannot accept or reject by hand, so the caller cannot be led to the answer. Off: the agent sees the stored answer and decides. A switch applies to sessions started afterwards.'),
            self::QaMaxUnsuccessfulSessions => __('Guards against a caller who knows some of the answers and calls again and again until the random questions suit them. Failed sessions (too many rejected answers) and undecided ones ("cannot decide" until passing was out of reach) count, and so do cancelled and expired sessions in which a question was shown and not accepted (still open, rejected or "cannot decide"), so hanging up on an unknown question is no free retry; whichever agent ran them makes no difference. On the identification page the first question shows as soon as the session starts, so a session cancelled or expired right after the start counts too. Passed sessions do not count, nor do sessions closed because the client\'s account was closed. The attempts on the identification page mark the cancelled and expired sessions that count. Once the limit is reached, the start button on the identification page is disabled and says until when, every refused start is written to the audit log (id_session.qa_refused), and staff with the client management permission can lift the lock on that page (client.qa_unlocked). The PIN, the one-time code and manual identification stay usable. The agents\' hourly limit of failed checks is a separate rule and does not change.'),
            self::PhoneAllowedRegions => __('Portal: a number of a country not listed here is refused with the reason. A landline or other number of a listed country that cannot receive text messages (premium rate, toll free, shared cost, VoIP) is taken, because it still recognises the caller, and it is marked there as not usable for text messages: it cannot be confirmed by SMS code. Text messages: every one (SMS login code, PIN, number confirmation, staff sign-in code, test message) is checked before it is queued and goes only to a mobile number of a listed country, because the destination sets the price of an SMS; where the numbering plan of a country does not tell every mobile number apart from a landline, the field names that country, because a text message may reach its landlines too. A refused message is listed on the Outbound messages page as cancelled with the reason, and the audit log records message.refused. The helpdesk (Phone numbers tab of the client page) and the EMD sync may record any number; the tab warns about a number of a country not listed here and marks a number that cannot be used for text messages.'),
            self::QaTypoTolerance => __('Blind check only: how many typos (a letter swapped, missing or extra, or two neighbours swapped) still match, 0–:max. Answers shorter than :short characters must match exactly, and digits always.', ['max' => AnswerMatcher::MAX_TOLERANCE, 'short' => AnswerMatcher::EXACT_BELOW]),
            self::PinMinLength, self::PinMaxLength => __('Allowed PIN length when setting or replacing a PIN (:min–:max digits). Existing PINs remain valid. The phone menu checks the PIN only for callers recognised by a registered number, so a PIN needs a phone number.', ['min' => PinService::MIN_ALLOWED_LENGTH, 'max' => PinService::MAX_ALLOWED_LENGTH]),
            self::PinClientChangesEnabled => __('Allows clients to set, replace and remove their own PIN. Disabled: only authorized staff can manage it. PIN uniqueness is controlled separately.'),
            self::PinUniqueRequired => __('Enabled: newly assigned PINs must be unique across clients, including closed accounts. Disabled: clients may share a PIN. Changing this setting does not alter existing PINs.'),
            self::PortalPhoneVerificationEnabled => __('The client confirms a number added on the portal with a code sent by SMS; needs a working SMS gateway. Off: the helpdesk confirms the number on the client page, or an identified call does when that switch is on. Unverified numbers rank below verified ones when a caller is matched.'),
            self::VerifyPhoneOnIdentifiedCall => __('A client identified on a call (by PIN, code, question and answer or manually) proves the number the call came from: their unverified copy of that number becomes verified. Off: numbers are verified by SMS code or by the helpdesk only.'),
            self::PortalSharedNumberNotice => __('On: the portal tells the client when one of their numbers is also on file for another client, so the phone menu cannot recognise them from it (the other client is never named). Off: the portal says nothing about it.'),
            self::ClientLoginMagicLinkPreviewInPanel => __('On: after "Send a login link" the panel notification also shows the link itself, so a tester can sign in without the mailbox. Anyone who can send links can then sign in as the client: keep it off in production; the system status page warns while it is on. Debug mode shows the link regardless.'),
            self::ClientLoginOtpSmsMaxPerNumberPerMinute => __('Login codes sent to one phone number per minute; the login page offers the next send after this interval. Keeps a flood of requests from running up the SMS bill. Other text messages are not limited.'),
            self::ClientLoginOtpSmsMaxPerNumberPerHour => __('Login codes sent to one phone number per hour; beyond it no code goes out until the hour has passed (the request is refused silently and audited). The login page offers one re-send, then the e-mailed link or support.'),
            self::ClientLoginOtpSmsMaxPerNumberPerDay => __('Login codes sent to one phone number in 24 hours (counted on the sent messages); beyond it no code goes out to that number until the oldest is a day old, the request is refused silently and audited (message.refused, sms_limit_per_day). Other text messages are not limited.'),
            self::ClientLoginOtpSmsMaxPerHour => __('Guards the SMS budget against a flood of anonymous login requests spread over many addresses, numbers and networks. Only codes that go out count, in a window of one hour from the first. Above the limit the login page answers as always, but no code goes out until the window is over: the audit log records login.request_capped with the channel (at most once a minute), the Outbound messages tile of the status page turns yellow and the portal\'s login page tells every visitor that SMS codes cannot be requested now. The networks exempt from the address limits (HDID_RATE_LIMIT_EXEMPT_NETWORKS) count here too. Staff second-factor codes and the other text messages are not counted: none of them can be asked for anonymously.'),
            self::ClientLoginOtpSmsMaxPerDay => __('The same for a window of 24 hours from the first code: a code refused by the hourly limit does not count here, and a full day is not reopened by a new hour. Set the two near the real need: every code costs money.'),
            self::ClientLoginMagicLinkMaxPerHour => __('Guards the mailbox against a flood of anonymous login requests spread over many addresses and networks; one address may ask five times in 15 minutes in any case. Only links that go out count, in a window of one hour from the first. Above the limit the login page answers as always, but no link goes out until the window is over: the audit log records login.request_capped with the channel (at most once a minute), the Outbound messages tile turns yellow and the portal\'s login page tells every visitor that links cannot be sent now. Links the helpdesk sends from the client page do not count and are never held back, and company sign-in is not affected. The default (10 000) leaves room for every client of the largest sizing profile signing in within one hour. What actually goes out is set by the mail service: Exchange Online, for example, lets one mailbox send 30 messages a minute and reach 10 000 recipients a day; links beyond that wait in the queue. The short-lived SMS login codes and staff second-factor codes go on the priority queue and are sent first; login links, which live for hours, wait on the default queue with the other mail.'),
            self::ClientLoginOtpSmsEmailFallbackEnabled => __('On: when the SMS code did not arrive after the second send, the login page offers to e-mail a login link once. Works only while "Login link by e-mail" is also on: with the e-mail link login off, this switch has no effect and the page says to contact support, as when the switch is off.'),
            self::ClientLoginRememberEnabled, self::UserLoginRememberEnabled => __('On: a sign-in through single sign-on (and, for staff, the "Remember me" box of the password form) keeps the browser signed in for the days set below, beyond the session limit. Off: no remember-me cookie. The e-mail link and the SMS code never remember.'),
            self::UserLoginMfaEnabled => __('On: every staff sign-in (password, ADFS, Entra, remember-me cookie) asks for a second factor before anything in the panel opens, once per new session. Staff without a factor set one up at their next step; switching on applies to sessions already open too.'),
            self::UserLoginMfaTrustProvider => __('On: a company sign-on whose verified token proves that ADFS or Entra already asked for a second factor (amr claim) needs no other; the audit log records it. Off: HDID always asks for its own second factor.'),
            self::SyncExportPayload => __('Complete JSON request body. Inside a text value, {{ now }} is replaced with the time of sending in UTC (e.g. 2026-09-28T12:03:15.000Z), and {{ ids }} with the selected IDs of the ID list below, comma-separated (e.g. "ids": "{{ ids }}" gives "3,12,40"). For a JSON list, write {{ ids }} without quotes between brackets: "ids": [{{ ids }}] gives [3,12,40] (all-digit IDs as numbers, others as text). Only staff who manage EMD sync can edit it.'),
            self::SyncColumnMapping => __('Use exact source headings. For phones, list all phone column headings separated by commas, e.g. Mobile phone, Office phone. Each cell may also contain several comma-separated numbers. All numbers are normalized and deduplicated. Empty mappings use the field name.'),
            default => null,
        };
    }

    /**
     * Extra words the settings search finds the setting by: the key itself
     * (as the audit log names it), the label and the tab it had before the
     * regrouping, and the usual abbreviations.
     *
     * @return list<string>
     */
    public function searchTerms(): array
    {
        $synonyms = match ($this) {
            self::ClientSsoEntraEnabled, self::UserSsoEntraEnabled, self::ClientSsoAdfsEnabled, self::UserSsoAdfsEnabled, self::SsoMobileTokenMaxAgeMinutes => ['SSO', 'OIDC'],
            self::ClientLoginMagicLinkEnabled, self::ClientLoginMagicLinkTtlMinutes, self::ClientLoginMagicLinkPreviewInPanel => ['magic link', 'e-mail link'],
            self::ClientLoginOtpSmsEnabled, self::ClientLoginOtpSmsLength, self::ClientLoginOtpSmsTtlMinutes, self::ClientLoginOtpMaxAttempts => ['OTP'],
            self::ClientLoginMagicLinkMaxPerHour => ['magic link', 'e-mail link', __('limit')],
            self::ClientLoginOtpSmsMaxPerHour, self::ClientLoginOtpSmsMaxPerDay, self::ClientLoginOtpSmsMaxPerNumberPerDay => ['OTP', 'SMS', __('limit')],
            self::MobileOtpLength, self::MobileOtpTtlMinutes => [__('dictated code')],
            self::QaMinAnsweredQuestionsRequired, self::QaMaxQuestionsPerSession, self::QaMinAcceptedToPass, self::QaMaxRejectedToFail, self::QaSessionTtlMinutes, self::QaBlindCheck, self::QaTypoTolerance => ['K-V', 'Q-A'],
            self::QaMaxUnsuccessfulSessions, self::QaUnsuccessfulWindowHours => ['K-V', 'Q-A', __('lock'), __('retry')],
            self::PhoneAllowedRegions => ['SMS', __('country'), __('premium rate'), __('landline')],
            self::UserLoginMfaEnabled, self::UserLoginMfaMethods, self::UserLoginMfaTrustProvider => ['MFA', '2FA'],
            default => [],
        };

        return array_values(array_unique([$this->value, $this->generatedLabel(), $this->groupLabel(), ...$synonyms]));
    }

    public function groupLabel(): string
    {
        return __(ucfirst(str_replace('_', ' ', $this->group())));
    }
}
