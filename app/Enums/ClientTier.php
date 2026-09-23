<?php

namespace App\Enums;

/**
 * Service level of a client, derived from the package lists in settings.
 */
enum ClientTier: string
{
    case Premium = 'premium';
    case Standard = 'standard';

    public function label(): string
    {
        return match ($this) {
            self::Premium => __('Premium'),
            self::Standard => __('Standard'),
        };
    }
}
