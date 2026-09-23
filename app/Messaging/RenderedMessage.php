<?php

namespace App\Messaging;

final readonly class RenderedMessage
{
    public function __construct(
        public Channel $channel,
        public ?string $subject,
        public string $body,
    ) {}
}
