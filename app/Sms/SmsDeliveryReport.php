<?php

namespace App\Sms;

use App\Enums\SmsDeliveryStatus;

/**
 * One answer of the gateway about a sent SMS: the status as the gateway
 * spelled it, and what it means to us.
 */
final readonly class SmsDeliveryReport
{
    public function __construct(
        public SmsDeliveryStatus $status,
        public string $raw,
    ) {}
}
