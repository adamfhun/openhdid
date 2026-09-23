<?php

namespace App\Messaging;

use App\Clients\ClientTiers;
use App\Enums\OutboundMessageStatus;
use App\Jobs\SendOutboundMessage;
use App\Models\Client;
use App\Models\OutboundMessage;
use App\Settings\SettingKey;
use App\Settings\Settings;

/**
 * The one way to send a client an e-mail or an SMS: render the template,
 * store the message, hand it to the queue.
 */
class Messenger
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly ClientTiers $tiers,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array<string, scalar|null>  $data  key-specific placeholder values
     * @param  array<string, mixed>  $meta  kept on the message for the sender (e.g. the magic link URL)
     */
    public function sendTemplate(MessageKey $key, Client $client, array $data, string $recipient, ?string $locale = null, array $meta = []): OutboundMessage
    {
        // The client's own language wins; the UI language of whoever triggered
        // the send is only a fallback, and a key may support fewer languages (SMS: Hungarian only).
        $locale = $key->locale($locale ?? ($client->locale ?: app()->getLocale()));
        $rendered = $this->renderer->render($key, $locale, $data + $this->commonData($client));

        $message = OutboundMessage::query()->create([
            'channel' => $rendered->channel,
            'template_key' => $key,
            'recipient' => $recipient,
            'subject' => $rendered->subject,
            'body' => $rendered->body,
            'meta' => $meta ?: null,
            'client_id' => $client->id,
            'status' => OutboundMessageStatus::Queued,
        ]);

        $this->dispatch($message);

        return $message;
    }

    /**
     * A one-off message that is not tied to a template (test sends).
     */
    public function sendRaw(Channel $channel, string $recipient, ?string $subject, string $body, ?Client $client = null): OutboundMessage
    {
        $message = OutboundMessage::query()->create([
            'channel' => $channel,
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'client_id' => $client?->id,
            'status' => OutboundMessageStatus::Queued,
        ]);

        $this->dispatch($message);

        return $message;
    }

    public function retry(OutboundMessage $message): void
    {
        OutboundMessage::query()->whereKey($message->id)->update(['status' => OutboundMessageStatus::Queued, 'attempts' => 0, 'error' => null, 'updated_at' => now()]);
        $message->refresh();
        $this->dispatch($message);
    }

    public function dispatch(OutboundMessage $message): void
    {
        SendOutboundMessage::dispatch($message->id);
    }

    /**
     * Placeholders every message may use.
     *
     * @return array<string, string>
     */
    public function commonData(Client $client): array
    {
        $tier = $this->tiers->tierFor($client);
        $support = $tier === null ? ['email' => null, 'phone' => null, 'hours' => null] : $this->tiers->supportFor($tier);

        return [
            'name' => $client->name,
            'email' => $client->email,
            'app_name' => (string) ($this->settings->string(SettingKey::BrandingAppName) ?? config('app.name')),
            'support_email' => (string) $support['email'],
            'support_phone' => (string) $support['phone'],
            'support_hours' => (string) $support['hours'],
        ];
    }
}
