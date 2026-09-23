<?php

namespace App\Enums;

enum OutboundMessageStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => __('Queued'),
            self::Sending => __('Sending'),
            self::Sent => __('Sent'),
            self::Failed => __('Failed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'warning',
            self::Sending => 'info',
            self::Sent => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }
}
