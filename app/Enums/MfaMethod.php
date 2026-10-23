<?php

namespace App\Enums;

/**
 * Second factors of staff sign-in. The administrator chooses which ones may
 * be used (Settings › Staff sign-in), every staff member picks among those.
 */
enum MfaMethod: string
{
    /** Time-based one-time password (RFC 6238), e.g. Google Authenticator. */
    case App = 'app';

    /** A code by SMS to a mobile number of the staff member's EMD record. */
    case Sms = 'sms';

    /** A code by e-mail to the account's address. */
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::App => __('Authenticator app'),
            self::Sms => __('SMS code'),
            self::Email => __('E-mail code'),
        };
    }

    /**
     * Whether the code is sent by the system (SMS, e-mail) rather than shown
     * by the user's own device.
     */
    public function isSent(): bool
    {
        return $this !== self::App;
    }
}
