<?php

namespace App\Clients;

use App\Auth\Permission;
use App\Identification\ClientAnswers;
use App\Models\Client;
use App\Support\HuDate;

/**
 * Every column the client export may carry. A closed list on purpose:
 * security answers are not in it and the PIN itself cannot be (only its
 * bcrypt hash exists). A field may ask for an extra permission on top of
 * the export permission; the exporter drops what the user is not allowed.
 */
enum ClientExportField: string
{
    case Name = 'name';
    case Email = 'email';
    case ExternalId = 'external_id';
    case Company = 'company';
    case JobTitle = 'job_title';
    case Department = 'department';
    case EmailDomain = 'email_domain';
    case PhoneNumbers = 'phone_numbers';
    case Locale = 'locale';

    case ImplicitPackage = 'implicit_package';
    case ExplicitPackage = 'explicit_package';
    case PackageOverride = 'package_override';
    case PackageOverrideUntil = 'package_override_until';
    case PackageOverrideReason = 'package_override_reason';
    case Tier = 'tier';
    case Sponsor = 'sponsor';
    case LinkedClientsCount = 'linked_clients_count';
    case MissingSponsor = 'missing_sponsor';
    case LinkWarningMutedReason = 'link_warning_muted_reason';
    case LinkWarningMutedUntil = 'link_warning_muted_until';

    case Status = 'status';
    case ClosedReason = 'closed_reason';
    case ClosedAt = 'closed_at';
    case Synced = 'synced';
    case MissingSince = 'missing_since';
    case LastLoginAt = 'last_login_at';
    case CreatedAt = 'created_at';

    case AnswersCount = 'answers_count';
    case PinSet = 'pin_set';
    case PinSetAt = 'pin_set_at';
    case PinLockedUntil = 'pin_locked_until';

    case Notes = 'notes';

    /**
     * Pre-ticked in the export dialog.
     *
     * @return list<self>
     */
    public static function defaults(): array
    {
        return [self::Name, self::Email, self::ExternalId, self::Company, self::JobTitle, self::Department, self::PhoneNumbers, self::ImplicitPackage, self::ExplicitPackage, self::Tier, self::Status];
    }

    public function label(): string
    {
        return match ($this) {
            self::Name => __('Name'),
            self::Email => __('E-mail'),
            self::ExternalId => __('External id'),
            self::Company => __('Company'),
            self::JobTitle => __('Job title'),
            self::Department => __('Department'),
            self::EmailDomain => __('E-mail domain'),
            self::PhoneNumbers => __('Phone numbers'),
            self::Locale => __('Language'),
            self::ImplicitPackage => __('Implicit package'),
            self::ExplicitPackage => __('Explicit package'),
            self::PackageOverride => __('Package override'),
            self::PackageOverrideUntil => __('Package override until'),
            self::PackageOverrideReason => __('Package override reason'),
            self::Tier => __('Portal access'),
            self::Sponsor => __('Linked to'),
            self::LinkedClientsCount => __('Linked clients'),
            self::MissingSponsor => __('Explicit premium without a link'),
            self::LinkWarningMutedReason => __('Link warning muted reason'),
            self::LinkWarningMutedUntil => __('Link warning muted until'),
            self::Status => __('Status'),
            self::ClosedReason => __('Closed reason'),
            self::ClosedAt => __('Closed at'),
            self::Synced => __('Linked to EMD'),
            self::MissingSince => __('Missing since'),
            self::LastLoginAt => __('Last login'),
            self::CreatedAt => __('Created'),
            self::AnswersCount => __('Answers'),
            self::PinSet => __('PIN set'),
            self::PinSetAt => __('PIN set at'),
            self::PinLockedUntil => __('PIN locked until'),
            self::Notes => __('Notes'),
        };
    }

    /**
     * Group key for the export dialog; label via groupLabel().
     */
    public function group(): string
    {
        return match ($this) {
            self::Name, self::Email, self::ExternalId, self::Company, self::JobTitle, self::Department, self::EmailDomain, self::PhoneNumbers, self::Locale => 'client',
            self::ImplicitPackage, self::ExplicitPackage, self::PackageOverride, self::PackageOverrideUntil, self::PackageOverrideReason, self::Tier,
            self::Sponsor, self::LinkedClientsCount, self::MissingSponsor, self::LinkWarningMutedReason, self::LinkWarningMutedUntil => 'packages',
            self::Status, self::ClosedReason, self::ClosedAt, self::Synced, self::MissingSince, self::LastLoginAt, self::CreatedAt => 'status',
            self::AnswersCount, self::PinSet, self::PinSetAt, self::PinLockedUntil, self::Notes => 'security',
        };
    }

    public static function groupLabel(string $group): string
    {
        return match ($group) {
            'client' => __('Client'),
            'packages' => __('Packages and links'),
            'status' => __('Status'),
            'security' => __('Security and notes'),
            'directory' => __('EMD columns'),
            default => $group,
        };
    }

    /**
     * Extra permission a field needs beyond clients.export, if any.
     */
    public function requires(): ?Permission
    {
        return null;
    }

    public function value(Client $client): string
    {
        $tiers = app(ClientTiers::class);
        $yesNo = fn (bool $v): string => $v ? __('Yes') : __('No');

        return (string) match ($this) {
            self::Name => $client->name,
            self::Email => $client->email,
            self::ExternalId => $client->externalRecord?->external_id,
            self::Company => $client->externalRecord?->company,
            self::JobTitle => $client->externalRecord?->jobTitle(),
            self::Department => $client->externalRecord?->departmentName(),
            self::EmailDomain => $client->externalRecord?->email_domain,
            self::PhoneNumbers => $client->phoneNumbers->pluck('number_e164')->implode(', '),
            self::Locale => $client->locale,
            self::ImplicitPackage => $client->implicit_package,
            self::ExplicitPackage => $client->explicit_package,
            self::PackageOverride => $client->hasActivePackageOverride() ? $client->package_override : null,
            self::PackageOverrideUntil => $client->hasActivePackageOverride() ? $client->package_override_until?->format(HuDate::DATETIME) : null,
            self::PackageOverrideReason => $client->hasActivePackageOverride() ? $client->package_override_reason : null,
            self::Tier => $tiers->tierFor($client)?->label() ?? __('None'),
            self::Sponsor => ($sponsor = $client->sponsor()) ? $sponsor->name.' ('.$sponsor->email.')' : null,
            self::LinkedClientsCount => $client->activeLinks()->count(),
            self::MissingSponsor => $yesNo(app(ClientLinks::class)->isMissingSponsor($client)),
            self::LinkWarningMutedReason => $client->hasMutedLinkWarning() ? $client->link_warning_muted_reason : null,
            self::LinkWarningMutedUntil => $client->hasMutedLinkWarning() ? $client->link_warning_muted_until?->format(HuDate::DATETIME) : null,
            self::Status => $client->isClosed() ? __('Closed') : __('Open'),
            self::ClosedReason => $client->isClosed() ? __((string) $client->closed_reason) : null,
            self::ClosedAt => $client->closed_at?->format(HuDate::DATETIME),
            self::Synced => $yesNo($client->isSynced()),
            self::MissingSince => $client->externalRecord?->missing_since?->format(HuDate::DATETIME),
            self::LastLoginAt => $client->last_login_at?->format(HuDate::DATETIME),
            self::CreatedAt => $client->created_at?->format(HuDate::DATETIME),
            self::AnswersCount => app(ClientAnswers::class)->usableCount($client),
            self::PinSet => $yesNo($client->hasPin()),
            self::PinSetAt => $client->pin_set_at?->format(HuDate::DATETIME),
            self::PinLockedUntil => $client->pin_locked_until?->isFuture() ? $client->pin_locked_until->format(HuDate::DATETIME) : null,
            self::Notes => $client->notes,
        };
    }
}
