<?php

namespace App\Auth;

enum LoginRejection: string
{
    case MethodDisabled = 'method_disabled';
    case NoAccount = 'no_account';
    case AccountClosed = 'account_closed';
    case NoExternalRecord = 'no_external_record';
    case ExternalRecordMissing = 'external_record_missing';
    case ExternalRecordMismatch = 'external_record_mismatch';
    case NoPermission = 'no_permission';
    case NotEntitled = 'not_entitled';
    case InvalidCredentials = 'invalid_credentials';
    case ProviderUnavailable = 'provider_unavailable';

    public function message(): string
    {
        return match ($this) {
            self::MethodDisabled => 'This login method is not available.',
            self::NoAccount => 'No account exists for this identity.',
            self::AccountClosed => 'This account has been closed.',
            self::NoExternalRecord, self::ExternalRecordMissing => 'This account is not present in Enterprise Master Data.',
            self::ExternalRecordMismatch => 'This account does not match its Enterprise Master Data record.',
            self::NoPermission => 'This account has no access.',
            self::NotEntitled => 'Your package does not include access to the client portal.',
            self::InvalidCredentials => 'The credentials are not valid.',
            self::ProviderUnavailable => 'The sign-in service is not available right now. Please try again later.',
        };
    }

    /**
     * An unreachable identity provider is an outage, not a refusal.
     */
    public function httpStatus(): int
    {
        return $this === self::ProviderUnavailable ? 503 : 403;
    }
}
