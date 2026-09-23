<?php

namespace App\Enums;

enum CallStatus: string
{
    case Ringing = 'ringing';
    case Active = 'active';
    case Ended = 'ended';
    case Missed = 'missed';

    public function isOver(): bool
    {
        return $this === self::Ended || $this === self::Missed;
    }
}
