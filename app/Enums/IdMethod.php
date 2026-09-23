<?php

namespace App\Enums;

enum IdMethod: string
{
    case QuestionAnswer = 'qa';
    case Pin = 'pin';
    case MobileOtp = 'mobile_otp';
    case IvrCode = 'ivr_code';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::QuestionAnswer => __('Question & answer'),
            self::Pin => __('PIN'),
            self::MobileOtp => __('Dictated code'),
            self::IvrCode => __('Mobile app IVR code'),
            self::Manual => __('Manual identification'),
        };
    }
}
