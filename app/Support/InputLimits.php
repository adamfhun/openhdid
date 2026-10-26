<?php

namespace App\Support;

/**
 * Upper limits of free-form input, one place for the panel field
 * (maxLength), the server rule ("max:") and the column they end up in, so
 * the three cannot drift apart. Limits that belong to a domain stay with it
 * (PinService::MAX_ALLOWED_LENGTH, MobileOtpService::MIN_LENGTH,
 * StaffMfa::CODE_LENGTH, OidcClient::MAX_TOKEN_LENGTH,
 * ExternalRecordDto::EXTERNAL_ID_PATTERN, EmailAddressRule, PhoneNumberRule,
 * DomainNameRule).
 */
final class InputLimits
{
    /** A search box: no name, address or number is longer. */
    public const SEARCH = 255;

    /** A URL (the de facto limit of browsers and servers). */
    public const URL = 2048;

    /** A name, title or other short text in a varchar(255) column. */
    public const NAME = 255;

    /** A long text in a TEXT column: 65,535 bytes hold 16,383 four-byte characters. */
    public const TEXT_CHARS = 16000;

    /** A JSON document typed by hand. */
    public const JSON_CHARS = 65535;

    public const FOOTER = 2000;

    public const SMS_BODY = 1000;

    public const BADGE = 50;

    public const APP_NAME = 100;

    /**
     * A search term as it is used and remembered: trimmed and cut to
     * SEARCH characters, so an oversized term never reaches a query, the
     * session or the audit log in full.
     */
    public static function search(?string $term): string
    {
        return mb_substr(trim((string) $term), 0, self::SEARCH);
    }
}
