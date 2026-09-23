<?php

namespace App\Enums;

enum OneTimeCodePurpose: string
{
    case MagicLink = 'magic_link';
    case LoginOtp = 'login_otp';
    case MobileOtp = 'mobile_otp';
    case IvrCode = 'ivr_code';
    case PhoneVerify = 'phone_verify';
}
