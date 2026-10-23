<?php

namespace App\Enums;

/**
 * Life of an outbound message. "Sent" means the transport took it; an SMS
 * moves on to "delivered" or "undeliverable" when the gateway's delivery
 * report says so (SmsDeliveryChecks), and stays "sent" when no report
 * arrives. "Failed" is the transport's refusal, retried from the panel.
 */
enum OutboundMessageStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Undeliverable = 'undeliverable';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => __('Queued'),
            self::Sending => __('Sending'),
            self::Sent => __('Sent'),
            self::Delivered => __('Delivered'),
            self::Undeliverable => __('Undeliverable'),
            self::Failed => __('Failed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /**
     * The transport took the message, whatever the handset did with it.
     */
    public function isSent(): bool
    {
        return in_array($this, [self::Sent, self::Delivered, self::Undeliverable], true);
    }

    /**
     * A message the operator may queue again by hand: refused by the
     * transport, or reported undeliverable by the network. Nothing is
     * resent on its own.
     */
    public function isRetryable(): bool
    {
        return in_array($this, [self::Failed, self::Undeliverable], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'warning',
            self::Sending => 'info',
            self::Sent => 'success',
            self::Delivered => 'success',
            self::Undeliverable => 'danger',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }
}
