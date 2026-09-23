<?php

namespace App\Enums;

enum PhoneNumberSource: string
{
    case Sync = 'sync';
    case Admin = 'admin';
    case ClientSelf = 'self';

    public function label(): string
    {
        return match ($this) {
            self::Sync => __('EMD'),
            self::Admin => __('admin'),
            self::ClientSelf => __('self'),
        };
    }
}
