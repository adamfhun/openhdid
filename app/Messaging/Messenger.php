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
use App\Sms\SmsDestinations;
use App\Sms\SmsRefusal;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The one way to send a client an e-mail or an SMS: render the template,
 * store the message, hand it to the queue. A text message goes only to a
 * number SmsDestinations allows (a mobile number of an accepted country):
 * any other is stored as cancelled with the reason, its secret wiped, and
 * audited as message.refused, without reaching the gateway.
 *
 * Sign-in secrets (client login links and SMS codes, staff sign-in codes)
 * carry their expiry in meta.valid_until and travel on the priority queue
 * (HDID_PRIORITY_QUEUE), so other messages piling up cannot delay them past
 * their lifetime; the delivery job drops one that expired or was replaced.
 */
class Messenger
{
    /** How many queued messages one "send now" click sends inside the web request. */
    public const SEND_NOW_LIMIT = 25;

    /** A sign-in secret valid for at most this long goes on the priority queue. */
    public const PRIORITY_LIFETIME_MINUTES = 15;

    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly ClientTiers $tiers,
        private readonly Settings $settings,
        private readonly Auditor $auditor,
        private readonly Dispatcher $bus,
        private readonly SmsDestinations $smsDestinations,
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

        if (($lifetime = $this->signInSecretLifetime($key)) !== null) {
            $meta['valid_until'] = now()->addMinutes($lifetime)->toIso8601String();
        }

        $message = OutboundMessage::query()->create([
            'channel' => $rendered->channel,
            'template_key' => $key,
            'recipient' => $recipient,
            'subject' => self::fitSubject($rendered->subject),
            'body' => $rendered->body,
            'meta' => $meta ?: null,
            'client_id' => $client->id,
            'status' => OutboundMessageStatus::Queued,
        ]);

        $this->queueOrRefuse($message);

        return $message;
    }

    /**
     * A message without a template (test sends, staff sign-in codes). One
     * that carries a secret is wiped once it is out, like a code template.
     * A secret valid only until a given time (a staff sign-in code) is a
     * sign-in message: it goes on the priority queue and is not sent once
     * expired or replaced by a newer one to the same recipient.
     */
    public function sendRaw(Channel $channel, string $recipient, ?string $subject, string $body, ?Client $client = null, bool $carriesSecret = false, ?CarbonInterface $validUntil = null, ?string $forUserId = null): OutboundMessage
    {
        $meta = array_filter([
            'secret' => $carriesSecret || $validUntil !== null ? true : null,
            'valid_until' => $validUntil?->toIso8601String(),
            // Whose staff sign-in code it is: a newer code replaces only that user's older one.
            'for_user' => $forUserId,
        ], fn (mixed $value): bool => $value !== null);

        $message = OutboundMessage::query()->create([
            'channel' => $channel,
            'recipient' => $recipient,
            'subject' => self::fitSubject($subject),
            'body' => $body,
            'meta' => $meta ?: null,
            'client_id' => $client?->id,
            'status' => OutboundMessageStatus::Queued,
        ]);

        $this->queueOrRefuse($message);

        return $message;
    }

    /**
     * Why no text message may go to the number, or null when it may.
     */
    public function smsRefusal(string $number): ?SmsRefusal
    {
        return $this->smsDestinations->refusal($number);
    }

    /**
     * Hands the message to the queue, or, for a text message to a number no
     * SMS may go to, cancels it with the reason: nothing reaches the
     * gateway, the secret is wiped, and the audit log says why.
     */
    private function queueOrRefuse(OutboundMessage $message): void
    {
        if (! $this->refuseIfBlocked($message)) {
            $this->dispatch($message);
        }
    }

    /**
     * Cancels a text message to a number no SMS may go to (with the reason
     * on the row, the secret wiped and a message.refused audit entry) and
     * says whether it did. Asked when the message is queued and again just
     * before it is sent, so a row queued before a country was taken off the
     * list (or before an update) does not reach the gateway either.
     */
    public function refuseIfBlocked(OutboundMessage $message): bool
    {
        $refusal = $message->channel === Channel::Sms ? $this->smsDestinations->refusal($message->recipient) : null;

        if ($refusal === null) {
            return false;
        }

        $message->forceFill(['status' => OutboundMessageStatus::Cancelled, 'error' => self::refusalText($refusal)])->save();
        $message->redactSecret();
        $this->auditRefusal($message, $refusal);

        return true;
    }

    /**
     * The rendered subject as the column holds it (255 characters): a long
     * template subject with long placeholder values must not stop the send.
     */
    private static function fitSubject(?string $subject): ?string
    {
        return $subject === null ? null : mb_substr($subject, 0, 255);
    }

    private static function refusalText(SmsRefusal $refusal): string
    {
        return __('Not sent: no text message goes to this number (:reason).', ['reason' => $refusal->label()]);
    }

    private function auditRefusal(OutboundMessage $message, SmsRefusal $refusal): void
    {
        $this->auditor->record('message.refused', $message->client ?? $message, [
            'message_id' => $message->id,
            'channel' => $message->channel->value,
            'template_key' => $message->template_key?->value,
            'recipient' => $message->recipient,
            'reason' => 'sms_destination',
            'refusal' => $refusal->value,
        ]);
    }

    /**
     * Queue a failed (or network-reported undeliverable) message again, by
     * hand only. A message whose secret body was wiped after the final
     * failure cannot go out again (it would be empty); the caller issues a
     * new PIN, code or link instead.
     *
     * @throws MessageNotRetryableException
     */
    public function retry(OutboundMessage $message): void
    {
        if ($message->isRedacted()) {
            throw new MessageNotRetryableException($message);
        }

        // The number may have become one no text message goes to (a country switched off).
        if ($message->channel === Channel::Sms && ($refusal = $this->smsDestinations->refusal($message->recipient)) !== null) {
            $this->auditRefusal($message, $refusal);

            throw new MessageNotRetryableException($message, __('This message cannot be retried: no text message goes to this number (:reason).', ['reason' => $refusal->label()]));
        }

        $changed = OutboundMessage::query()
            ->whereKey($message->id)
            ->whereIn('status', [OutboundMessageStatus::Failed, OutboundMessageStatus::Undeliverable])
            ->whereNull('redacted_at')
            ->update([
                'status' => OutboundMessageStatus::Queued, 'attempts' => 0, 'error' => null, 'updated_at' => now(),
                'gateway_message_id' => null, 'delivery_status' => null, 'delivery_status_raw' => null, 'delivery_checks' => 0, 'next_delivery_check_at' => null, 'delivery_updated_at' => null,
            ]);

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
            // A text message to a number no SMS may go to stays failed; asked one by one
            // (retry) it says why, in bulk it is only left out, not audited again.
            if ($message->channel === Channel::Sms && $this->smsDestinations->refusal($message->recipient) !== null) {
                continue;
            }

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

    /**
     * A short-lived sign-in secret goes on the priority queue, every other
     * message on the connection's default queue, as before.
     */
    public function dispatch(OutboundMessage $message): void
    {
        SendOutboundMessage::dispatch($message->id)->onQueue(self::isPriorityMessage($message) ? self::priorityQueue() : null);
    }

    /**
     * Whether the message carries a short-lived sign-in secret (the staff
     * sign-in code, the client's SMS login code): only these go ahead of
     * the rest. The client login link lives for hours and can be asked for
     * without signing in, so a flood of links would otherwise stand in the
     * same lane in front of the codes; it waits on the default queue and is
     * still dropped once expired or replaced.
     */
    public static function isPriorityMessage(OutboundMessage $message): bool
    {
        if (! self::isSignInMessage($message)) {
            return false;
        }

        $created = $message->created_at ?? now();

        return Carbon::parse((string) $message->meta['valid_until'])->lessThanOrEqualTo($created->copy()->addMinutes(self::PRIORITY_LIFETIME_MINUTES));
    }

    /**
     * The queue of the short-lived sign-in secrets (HDID_PRIORITY_QUEUE);
     * "default" while it is not set, so a worker listening on the default
     * queue only still sends them.
     */
    public static function priorityQueue(): string
    {
        return (string) (config('hdid.messages.priority_queue') ?: 'default');
    }

    /**
     * Whether the message carries a sign-in secret valid only until a given
     * time (client login link or SMS code, staff sign-in code).
     */
    public static function isSignInMessage(OutboundMessage $message): bool
    {
        return isset($message->meta['valid_until']);
    }

    /**
     * Minutes a client sign-in secret of this template lives (the link or
     * code inside it is issued with the same setting); null for templates
     * without one.
     */
    private function signInSecretLifetime(MessageKey $key): ?int
    {
        return match ($key) {
            MessageKey::MagicLink => $this->settings->int(SettingKey::ClientLoginMagicLinkTtlMinutes),
            MessageKey::LoginOtpSms => $this->settings->int(SettingKey::ClientLoginOtpSmsTtlMinutes),
            default => null,
        };
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
