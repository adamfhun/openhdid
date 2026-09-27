<?php

namespace App\Enums;

/**
 * What confirmed that a phone number belongs to the client.
 */
enum PhoneVerificationSource: string
{
    case Directory = 'directory';
    case Admin = 'admin';
    case Sms = 'sms';
    case Staff = 'staff';
    case IdentifiedCall = 'identified_call';

    public function label(): string
    {
        return match ($this) {
            self::Directory => __('Enterprise Master Data'),
            self::Admin => __('added by the helpdesk'),
            self::Sms => __('SMS code'),
            self::Staff => __('confirmed by the helpdesk'),
            self::IdentifiedCall => __('identified call'),
        };
    }
}
