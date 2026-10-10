<?php

namespace App\Enums;

enum OneTimeCodePurpose: string
{
    case MagicLink = 'magic_link';
    case LoginOtp = 'login_otp';
    case MobileOtp = 'mobile_otp';
    case IvrCode = 'ivr_code';
    case PhoneVerify = 'phone_verify';

    /**
     * The purposes whose codes are looked up together (the phone menu takes
     * a one-time code and an IVR code alike), so a code has to be unique
     * across all of them, not only within its own purpose.
     *
     * @return list<self>
     */
    public function sharedCodeSpace(): array
    {
        return match ($this) {
            self::MobileOtp, self::IvrCode => [self::MobileOtp, self::IvrCode],
            default => [$this],
        };
    }
}
