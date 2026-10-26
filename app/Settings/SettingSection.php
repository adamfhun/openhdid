<?php

namespace App\Settings;

/**
 * The blocks of the settings page: each names where its settings act
 * ("Hol hat") and lists its keys in display order. A key whose
 * SettingKey::dependsOn() names a switch is shown under that switch, in the
 * same block, only while the switch is on. Every SettingKey belongs to
 * exactly one block (SettingsLayoutTest), so the page never drops a setting.
 */
enum SettingSection: string
{
    case Packages = 'packages';
    case SupportContacts = 'support_contacts';
    case CallQueues = 'call_queues';
    case ClientList = 'client_list';

    case QuestionAnswer = 'question_answer';
    case Pin = 'pin';
    case OneTimeCodes = 'one_time_codes';
    case PhoneNumbers = 'phone_numbers';

    case PortalSignIn = 'portal_sign_in';
    case PortalSession = 'portal_session';
    case PortalContent = 'portal_content';

    case StaffSignIn = 'staff_sign_in';
    case StaffMfa = 'staff_mfa';
    case StaffSession = 'staff_session';

    case DirectoryClassification = 'directory_classification';
    case DirectorySafeguards = 'directory_safeguards';
    case DirectoryFileApi = 'directory_file_api';
    case DirectoryColumns = 'directory_columns';
    case DirectoryJsonApi = 'directory_json_api';

    case AppIdentity = 'app_identity';
    case Colors = 'colors';
    case Images = 'images';
    case PremiumLook = 'premium_look';

    case Dashboard = 'dashboard';
    case Retention = 'retention';

    public function tab(): SettingTab
    {
        return match ($this) {
            self::Packages, self::SupportContacts, self::CallQueues, self::ClientList => SettingTab::Clients,
            self::QuestionAnswer, self::Pin, self::OneTimeCodes, self::PhoneNumbers => SettingTab::Identification,
            self::PortalSignIn, self::PortalSession, self::PortalContent => SettingTab::Portal,
            self::StaffSignIn, self::StaffMfa, self::StaffSession => SettingTab::Staff,
            self::DirectoryClassification, self::DirectorySafeguards, self::DirectoryFileApi, self::DirectoryColumns, self::DirectoryJsonApi => SettingTab::Directory,
            self::AppIdentity, self::Colors, self::Images, self::PremiumLook => SettingTab::Appearance,
            self::Dashboard, self::Retention => SettingTab::Operations,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Packages => __('Packages and levels'),
            self::SupportContacts => __('Support contacts'),
            self::CallQueues => __('Levels of the call queues'),
            self::ClientList => __('Client list and client page'),
            self::QuestionAnswer => __('Question & answer'),
            self::Pin => __('PIN'),
            self::OneTimeCodes => __('One-time codes'),
            self::PhoneNumbers => __('Phone numbers'),
            self::PortalSignIn, self::StaffSignIn => __('Sign-in methods'),
            self::PortalSession, self::StaffSession => __('Session'),
            self::PortalContent => __('Content'),
            self::StaffMfa => __('Two-factor sign-in'),
            self::DirectoryClassification => __('Classification'),
            self::DirectorySafeguards => __('Closing and safeguards'),
            self::DirectoryFileApi => __('File API (export and ID list)'),
            self::DirectoryColumns => __('Columns and uploaded files'),
            self::DirectoryJsonApi => __('JSON API'),
            self::AppIdentity => __('Application'),
            self::Colors => __('Colours'),
            self::Images => __('Background images'),
            self::PremiumLook => __('Premium look'),
            self::Dashboard => __('Dashboard and calls'),
            self::Retention => __('Retention'),
        };
    }

    /**
     * Where the block's settings show their effect, under its title.
     */
    public function where(): string
    {
        return __('Where it acts: :where', ['where' => match ($this) {
            self::Packages => __('who may sign in to the portal and at which level; premium-only mode is shown by a bar on top of the panel'),
            self::SupportContacts => __('the portal and the support line block of the client page; in premium-only mode a turned-away standard client sees the standard contact'),
            self::CallQueues => __('the level of each call: the dashboard, the missed calls and the Calls list follow it'),
            self::ClientList => __('the Clients list, the counter next to the menu item, the client page and the export'),
            self::QuestionAnswer => __('the agent\'s identification page (including how often one client may be tried) and the security questions on the portal'),
            self::Pin => __('the phone menu (IVR), the agent\'s PIN check and the PIN handling on the portal'),
            self::OneTimeCodes => __('the one-time identification code of the portal and the mobile app (Verify code page), the IVR code, and the agents\' attempt limit'),
            self::PhoneNumbers => __('recognising the caller by number, the Phone numbers tab of the client page, the numbers clients may add on the portal, and the numbers every text message may go to'),
            self::PortalSignIn => __('the portal\'s login page, the mobile app, and the "Send login link" button of the client page'),
            self::PortalSession => __('the client\'s browser session on the portal'),
            self::PortalContent => __('the overview page and the footer of the portal'),
            self::StaffSignIn => __('the login page of this panel'),
            self::StaffMfa => __('every staff sign-in; the status page shows who has no factor yet'),
            self::StaffSession => __('the staff member\'s browser session in this panel'),
            self::DirectoryClassification => __('which EMD records become staff and which become clients'),
            self::DirectorySafeguards => __('when accounts close and when a sync is refused; the status page warns on a late sync'),
            self::DirectoryFileApi => __('the export and the ID list requests sent to EMD'),
            self::DirectoryColumns => __('every import: which source column fills which field, and the uploaded CSV files'),
            self::DirectoryJsonApi => __('the legacy JSON API import'),
            self::AppIdentity => __('this panel, the portal, the e-mails and the browser tab'),
            self::Colors => __('this panel, the portal, the e-mails and the HTML reports'),
            self::Images => __('the portal and the login pages'),
            self::PremiumLook => __('the portal of premium clients'),
            self::Dashboard => __('the dashboard of this panel'),
            self::Retention => __('the nightly clean-up and the audit archive'),
        }]);
    }

    /**
     * Grid columns of the block: 1 lays the settings out in rows with the
     * label beside the field; more columns suit colours and images.
     */
    public function columns(): int
    {
        return match ($this) {
            self::Colors, self::Images, self::PremiumLook, self::AppIdentity => 3,
            default => 1,
        };
    }

    /**
     * The settings of the block, in display order; a dependent setting
     * follows its switch.
     *
     * @return list<SettingKey>
     */
    public function keys(): array
    {
        return match ($this) {
            self::Packages => [SettingKey::PackagesPremium, SettingKey::PackagesStandard, SettingKey::PackagesPremiumOnly],
            self::SupportContacts => [
                SettingKey::SupportPremiumPhone, SettingKey::SupportPremiumEmail, SettingKey::SupportPremiumHours,
                SettingKey::SupportStandardPhone, SettingKey::SupportStandardEmail, SettingKey::SupportStandardHours,
            ],
            self::CallQueues => [SettingKey::CallsQueueTiers, SettingKey::CallsDefaultTier],
            self::ClientList => [SettingKey::ClientsListRelevantOnly, SettingKey::ClientsUnlinkedBadge, SettingKey::ClientsDirectoryFields],

            self::QuestionAnswer => [
                SettingKey::QaMinAnsweredQuestionsRequired, SettingKey::QaMaxQuestionsPerSession, SettingKey::QaMinAcceptedToPass,
                SettingKey::QaMaxRejectedToFail, SettingKey::QaSessionTtlMinutes, SettingKey::QaMaxUnsuccessfulSessions, SettingKey::QaUnsuccessfulWindowHours,
                SettingKey::QaBlindCheck, SettingKey::QaTypoTolerance,
            ],
            self::Pin => [
                SettingKey::PinMinLength, SettingKey::PinMaxLength, SettingKey::PinMaxFailedAttempts, SettingKey::PinLockoutMinutes,
                SettingKey::PinAgentVerificationEnabled, SettingKey::PinClientChangesEnabled, SettingKey::PinUniqueRequired,
            ],
            self::OneTimeCodes => [SettingKey::MobileOtpLength, SettingKey::MobileOtpTtlMinutes, SettingKey::IvrCodeLength, SettingKey::AgentAttemptsPerHour],
            self::PhoneNumbers => [
                SettingKey::PhoneAllowedRegions, SettingKey::PortalMaxPhoneNumbersPerClient, SettingKey::PortalPhoneVerificationEnabled,
                SettingKey::VerifyPhoneOnIdentifiedCall, SettingKey::PortalSharedNumberNotice,
            ],

            self::PortalSignIn => [
                SettingKey::ClientLoginMagicLinkEnabled, SettingKey::ClientLoginMagicLinkTtlMinutes, SettingKey::ClientLoginMagicLinkPreviewInPanel,
                SettingKey::ClientLoginMagicLinkMaxPerHour,
                SettingKey::ClientLoginOtpSmsEnabled, SettingKey::ClientLoginOtpSmsLength, SettingKey::ClientLoginOtpSmsTtlMinutes,
                SettingKey::ClientLoginOtpMaxAttempts, SettingKey::ClientLoginOtpSmsMaxPerNumberPerMinute, SettingKey::ClientLoginOtpSmsMaxPerNumberPerHour,
                SettingKey::ClientLoginOtpSmsMaxPerNumberPerDay, SettingKey::ClientLoginOtpSmsMaxPerHour, SettingKey::ClientLoginOtpSmsMaxPerDay,
                SettingKey::ClientLoginOtpSmsEmailFallbackEnabled, SettingKey::ClientLoginLockoutMaxAttempts, SettingKey::ClientLoginLockoutMinutes,
                SettingKey::ClientSsoEntraEnabled, SettingKey::ClientSsoAdfsEnabled, SettingKey::SsoMobileTokenMaxAgeMinutes,
            ],
            self::PortalSession => [SettingKey::ClientLoginSessionMaxHours, SettingKey::ClientLoginRememberEnabled, SettingKey::ClientLoginRememberDays],
            self::PortalContent => [
                SettingKey::PortalNewsOnOverview, SettingKey::PortalFooterText, SettingKey::PortalLegalName,
                SettingKey::PortalPrivacyUrl, SettingKey::PortalTermsUrl, SettingKey::PortalImprintUrl,
            ],

            self::StaffSignIn => [
                SettingKey::UserLoginPasswordEnabled, SettingKey::UserLoginLockoutMaxAttempts, SettingKey::UserLoginLockoutMinutes,
                SettingKey::UserSsoEntraEnabled, SettingKey::UserSsoAdfsEnabled,
            ],
            self::StaffMfa => [SettingKey::UserLoginMfaEnabled, SettingKey::UserLoginMfaMethods, SettingKey::UserLoginMfaTrustProvider],
            self::StaffSession => [SettingKey::UserLoginSessionMaxHours, SettingKey::UserLoginRememberEnabled, SettingKey::UserLoginRememberDays],

            self::DirectoryClassification => [SettingKey::SyncUserDomains, SettingKey::SyncClientDomains, SettingKey::SyncUniqueDomains],
            self::DirectorySafeguards => [SettingKey::SyncMissedRunsBeforeClose, SettingKey::SyncMinRowsRatioPercent, SettingKey::SyncExpectedIntervalHours],
            self::DirectoryFileApi => [
                SettingKey::SyncExportPayload, SettingKey::SyncIdListPayload,
                SettingKey::SyncIdListIdPath, SettingKey::SyncIdListNamePath, SettingKey::SyncIdListStatusPath,
            ],
            self::DirectoryColumns => [SettingKey::SyncColumnMapping, SettingKey::SyncCsvEncoding],
            self::DirectoryJsonApi => [SettingKey::SyncApiDataPath, SettingKey::SyncApiPageParam],

            self::AppIdentity => [SettingKey::BrandingAppName, SettingKey::BrandingLogo, SettingKey::BrandingFavicon],
            self::Colors => [
                SettingKey::BrandingPrimaryColor, SettingKey::BrandingAccentColor, SettingKey::BrandingBackgroundColor,
                SettingKey::BrandingSurfaceColor, SettingKey::BrandingTextColor, SettingKey::BrandingMutedTextColor,
            ],
            self::Images => [SettingKey::BrandingBackgroundImage, SettingKey::BrandingLoginBackgroundImage, SettingKey::BrandingAdminLoginBackgroundImage],
            self::PremiumLook => [SettingKey::PremiumAccentColor, SettingKey::PremiumBadgeLabel, SettingKey::PremiumBackgroundImage, SettingKey::PremiumLoginBackgroundImage],

            self::Dashboard => [SettingKey::CallsDashboardPollSeconds, SettingKey::CallsRetentionHours, SettingKey::CallsStaleAfterHours],
            self::Retention => [SettingKey::RetentionGeneralYears, SettingKey::RetentionShortLivedYears, SettingKey::RetentionAuditHotDays],
        };
    }

    /**
     * The EMD source this block configures, when it serves one source only
     * (EMD_SYNC_DRIVER decides which one runs).
     */
    public function syncDriver(): ?string
    {
        return match ($this) {
            self::DirectoryFileApi => 'spreadsheet',
            self::DirectoryJsonApi => 'json',
            default => null,
        };
    }

    public static function of(SettingKey $key): self
    {
        static $map = null;

        if ($map === null) {
            $map = [];
            foreach (self::cases() as $section) {
                foreach ($section->keys() as $sectionKey) {
                    $map[$sectionKey->value] = $section;
                }
            }
        }

        return $map[$key->value];
    }
}
