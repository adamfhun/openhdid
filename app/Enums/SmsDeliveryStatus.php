<?php

namespace App\Enums;

/**
 * What the SMS gateway's delivery report says about a sent SMS
 * (SmsDeliveryChecks): still on its way, delivered to the handset, refused
 * by the network, or never answered within the configured checks.
 */
enum SmsDeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Undeliverable = 'undeliverable';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Report pending'),
            self::Delivered => __('Delivered'),
            self::Undeliverable => __('Undeliverable'),
            self::Unknown => __('No report'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Delivered => 'success',
            self::Undeliverable => 'danger',
            self::Unknown => 'gray',
        };
    }
}
