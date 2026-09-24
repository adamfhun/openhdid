<?php

namespace Database\Factories;

use App\Enums\OutboundMessageStatus;
use App\Messaging\Channel;
use App\Messaging\MessageKey;
use App\Models\OutboundMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutboundMessage>
 */
class OutboundMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'channel' => Channel::Email,
            'recipient' => fake()->safeEmail(),
            'subject' => fake()->sentence(3),
            'body' => '<p>'.fake()->sentence().'</p>',
            'status' => OutboundMessageStatus::Queued,
        ];
    }

    public function sms(): static
    {
        return $this->state(['channel' => Channel::Sms, 'recipient' => '+36301234567', 'subject' => null, 'body' => fake()->sentence()]);
    }

    public function failed(): static
    {
        return $this->state(['status' => OutboundMessageStatus::Failed, 'attempts' => OutboundMessage::MAX_ATTEMPTS, 'error' => 'boom']);
    }

    public function sent(): static
    {
        return $this->state(['status' => OutboundMessageStatus::Sent, 'attempts' => 1, 'sent_at' => now()]);
    }

    /**
     * A secret-carrying message whose body and meta were already wiped;
     * combine with failed() or sent() for the matching status.
     */
    public function redacted(): static
    {
        return $this->state(['template_key' => MessageKey::PinSms, 'body' => '', 'meta' => null, 'redacted_at' => now()]);
    }
}
