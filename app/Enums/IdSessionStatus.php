<?php

namespace App\Enums;

enum IdSessionStatus: string
{
    case Open = 'open';
    case Passed = 'passed';
    case Failed = 'failed';
    case Undecided = 'undecided';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return $this !== self::Open;
    }

    public function isSuccessful(): bool
    {
        return $this === self::Passed;
    }
}
