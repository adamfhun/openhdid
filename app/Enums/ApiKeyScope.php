<?php

namespace App\Enums;

/**
 * What a machine-to-machine API key may call.
 */
enum ApiKeyScope: string
{
    case CallCenter = 'callcenter';
    case MobileBackend = 'mobile_backend';

    public function label(): string
    {
        return match ($this) {
            self::CallCenter => __('Call center / IVR'),
            self::MobileBackend => __('Mobile app backend'),
        };
    }
}
