<?php

namespace App\Messaging;

use App\Models\OutboundMessage;
use RuntimeException;

/**
 * Thrown when a message cannot be sent again: its confidential body was
 * wiped after the final failure, it is no longer in the failed state, or
 * (with its own reason) no text message may go to its number any more.
 */
class MessageNotRetryableException extends RuntimeException
{
    public function __construct(public readonly OutboundMessage $outboundMessage, ?string $reason = null)
    {
        parent::__construct($reason ?? ($outboundMessage->isRedacted()
            ? __('This message cannot be retried: its confidential content was wiped after the final failure. Issue a new PIN, code or login link instead.')
            : __('Only a failed message can be retried.')));
    }
}
