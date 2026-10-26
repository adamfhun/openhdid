<?php

namespace App\Auth\Oidc;

/**
 * What the EMD sync makes of an e-mail domain a client Entra tenant may
 * present (CLIENT_ENTRA_TENANTS "id:domain|domain"): the sync files an
 * address by the staff and client domain lists, staff first, and skips the
 * rows of any other domain (RecordClassifier).
 */
enum EntraDomainState: string
{
    /** On the client domain list, and client records of it came in. */
    case ClientDomain = 'client_domain';

    /** On the client domain list, but no client record of it came in yet. */
    case ClientDomainWithoutRecords = 'client_domain_without_records';

    /** On the staff domain list: the sync files these addresses as staff. */
    case StaffDomain = 'staff_domain';

    /** On neither list: the sync skips these rows. */
    case Unlisted = 'unlisted';

    /**
     * The badge colour from the panel palette. A domain on no list is red
     * while clients need an EMD record to sign in, yellow otherwise.
     */
    public function color(bool $emdRequired): string
    {
        return match ($this) {
            self::ClientDomain => 'success',
            self::ClientDomainWithoutRecords => 'warning',
            self::StaffDomain => 'danger',
            self::Unlisted => $emdRequired ? 'danger' : 'warning',
        };
    }

    /**
     * The line next to the badge: what the sync does with the domain, and
     * what that means for a client signing in with such an address.
     */
    public function describe(int $records, bool $emdRequired): string
    {
        return match ($this) {
            self::ClientDomain => __('client domain in the EMD sync, :count record(s)', ['count' => $records]),
            self::ClientDomainWithoutRecords => __('client domain in the EMD sync, but no record of it has come in yet'),
            self::StaffDomain => $emdRequired
                ? __('staff domain in the EMD sync: these addresses are filed as staff, so no client signs in with them')
                : __('staff domain in the EMD sync: these addresses are filed as staff; only a client account created in the panel signs in with them (CLIENT_LOGIN_REQUIRE_EMD=false)'),
            self::Unlisted => $emdRequired
                ? __('on no EMD domain list: the sync skips these rows, and without an EMD record no client signs in (CLIENT_LOGIN_REQUIRE_EMD)')
                : __('on no EMD domain list: the sync skips these rows; only a client account created in the panel signs in (CLIENT_LOGIN_REQUIRE_EMD=false)'),
        };
    }

    /** A few words for the status tile. */
    public function label(): string
    {
        return match ($this) {
            self::ClientDomain => __('client domain'),
            self::ClientDomainWithoutRecords => __('client domain without records'),
            self::StaffDomain => __('staff domain'),
            self::Unlisted => __('on no EMD domain list'),
        };
    }

    /**
     * Whether the sync brings no client of the domain at all: the status
     * tile names these, since a tenant listed for them is usually a slip.
     */
    public function needsAttention(): bool
    {
        return $this === self::StaffDomain || $this === self::Unlisted;
    }
}
