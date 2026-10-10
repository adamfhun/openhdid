<?php

namespace App\Enums;

/**
 * What a machine-to-machine API key may call.
 */
enum ApiKeyScope: string
{
    case CallCenter = 'callcenter';
    case MobileBackend = 'mobile_backend';

    /**
     * Keys of this scope sign every request: the mobile backend key can issue
     * an identification code for any client, so a leaked key alone must not
     * be enough (owner decision, 2026-10-10). Call-center keys sign once a
     * secret is issued for them, because legacy IVR platforms may not sign.
     */
    public function signatureRequired(): bool
    {
        return $this === self::MobileBackend;
    }

    public function label(): string
    {
        return match ($this) {
            self::CallCenter => __('Call center / IVR'),
            self::MobileBackend => __('Mobile app backend'),
        };
    }
}
