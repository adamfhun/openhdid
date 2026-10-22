<?php

namespace App\Messaging;

use App\Audit\Auditor;
use App\Clients\ClientTiers;
use App\Enums\OutboundMessageStatus;
use App\Jobs\SendOutboundMessage;
use App\Models\Client;
use App\Models\OutboundMessage;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one way to send a client an e-mail or an SMS: render the template,
 * store the message, hand it to the queue.
 */
class Messenger
{
    /** How many queued messages one "send now" click sends inside the web request. */
    public const SEND_NOW_LIMIT = 25;

    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly ClientTiers $tiers,
        private readonly Settings $settings,
        private readonly Auditor $auditor,
        private readonly Dispatcher $bus,
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
    /**
     * A message without a template (test sends, staff sign-in codes). One
     * that carries a secret is wiped once it is out, like a code template.
     */
    public function sendRaw(Channel $channel, string $recipient, ?string $subject, string $body, ?Client $client = null, bool $carriesSecret = false): OutboundMessage
    {
        $message = OutboundMessage::query()->create([
            'channel' => $channel,
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'meta' => $carriesSecret ? ['secret' => true] : null,
            'client_id' => $client?->id,
            'status' => OutboundMessageStatus::Queued,
        ]);

        $this->dispatch($message);

        return $message;
    }

    /**
     * Queue a failed message again. A message whose secret body was wiped
     * after the final failure cannot go out again (it would be empty); the
     * caller issues a new PIN, code or link instead.
     *
     * @throws MessageNotRetryableException
     */
    public function retry(OutboundMessage $message): void
    {
        if ($message->isRedacted()) {
            throw new MessageNotRetryableException($message);
        }

        $changed = OutboundMessage::query()
            ->whereKey($message->id)
            ->where('status', OutboundMessageStatus::Failed)
            ->whereNull('redacted_at')
            ->update(['status' => OutboundMessageStatus::Queued, 'attempts' => 0, 'error' => null, 'updated_at' => now()]);

        if ($changed !== 1) {
            throw new MessageNotRetryableException($message->fresh() ?? $message);
        }

        $message->refresh();
        $this->dispatch($message);
    }

    /**
     * Withdraw a message that is still waiting in the queue and wipe its
     * secret. False when a worker already picked it up: that send finishes
     * on its own and the worker redacts the row.
     */
    public function cancel(OutboundMessage $message): bool
    {
        $changed = OutboundMessage::query()
            ->whereKey($message->id)
            ->where('status', OutboundMessageStatus::Queued)
            ->update(['status' => OutboundMessageStatus::Cancelled, 'updated_at' => now()]);

        if ($changed !== 1) {
            return false;
        }

        $message->refresh();
        $message->redactSecret();

        return true;
    }

    /**
     * Failed messages that can be queued again: their content is still there.
     *
     * @return Builder<OutboundMessage>
     */
    public function retryableFailed(): Builder
    {
        return OutboundMessage::query()->where('status', OutboundMessageStatus::Failed)->whereNull('redacted_at');
    }

    /**
     * Queue the given messages again; the ones that cannot be retried (not
     * failed any more, content wiped) are skipped. Returns how many went.
     *
     * @param  iterable<OutboundMessage>  $messages
     */
    public function retryMany(iterable $messages): int
    {
        $retried = 0;

        foreach ($messages as $message) {
            try {
                $this->retry($message);
                $retried++;
            } catch (MessageNotRetryableException) {
                // Skipped on purpose; the count tells the operator.
            }
        }

        return $retried;
    }

    public function retryAllFailed(): int
    {
        $retried = $this->retryMany($this->retryableFailed()->orderBy('id')->get());

        if ($retried > 0) {
            $this->auditor->record('message.retried_all', context: ['count' => $retried]);
        }

        return $retried;
    }

    /**
     * Cancel the given queued messages; returns how many were still queued.
     *
     * @param  iterable<OutboundMessage>  $messages
     */
    public function cancelMany(iterable $messages): int
    {
        $cancelled = 0;

        foreach ($messages as $message) {
            if ($this->cancel($message)) {
                $cancelled++;
            }
        }

        if ($cancelled > 0) {
            $this->auditor->record('message.cancelled_selected', context: ['count' => $cancelled]);
        }

        return $cancelled;
    }

    /**
     * Send the oldest queued messages right now, in this process, without a
     * worker: the way out when the worker is down and a login link must
     * still arrive. Capped so one click cannot outlast the PHP time limit;
     * the rest stays queued for the next click or the worker.
     *
     * @return array{sent: int, failed: int, retrying: int, remaining: int}
     */
    public function sendQueuedNow(int $limit = self::SEND_NOW_LIMIT): array
    {
        $ids = OutboundMessage::query()->queued()->orderBy('id')->limit(max(1, $limit))->pluck('id');

        foreach ($ids as $id) {
            $this->bus->dispatchSync(new SendOutboundMessage($id));
        }

        $outcome = OutboundMessage::query()->whereIn('id', $ids)->pluck('status', 'id')
            ->countBy(fn (OutboundMessageStatus $status): string => $status->value);

        $result = [
            'sent' => (int) $outcome->get(OutboundMessageStatus::Sent->value, 0),
            'failed' => (int) $outcome->get(OutboundMessageStatus::Failed->value, 0),
            'retrying' => (int) $outcome->get(OutboundMessageStatus::Queued->value, 0),
            'remaining' => OutboundMessage::query()->queued()->count(),
        ];

        if ($ids->isNotEmpty()) {
            $this->auditor->record('message.flushed', context: $result + ['processed' => $ids->count()]);
        }

        return $result;
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
