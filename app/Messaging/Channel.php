<?php

namespace App\Messaging;

enum Channel: string
{
    case Email = 'email';
    case Sms = 'sms';

    public function label(): string
    {
        return match ($this) {
            self::Email => __('E-mail'),
            self::Sms => __('SMS'),
        };
    }
}
