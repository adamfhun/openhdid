<?php

namespace App\Jobs;

use App\Audit\Auditor;
use App\Clients\ClientTiers;
use App\Enums\OutboundMessageStatus;
use App\Mail\MagicLinkMail;
use App\Mail\TemplatedMail;
use App\Messaging\Channel;
use App\Messaging\MessageKey;
use App\Messaging\Messenger;
use App\Messaging\SmsDeliveryChecks;
use App\Models\OutboundMessage;
use App\Sms\SmsSender;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Delivers one outbound message. Failures are recorded on the row; the job
 * retries itself up to OutboundMessage::MAX_ATTEMPTS and then marks the
 * message failed instead of throwing, so a broken transport never breaks
 * the request that queued the message.
 *
 * One message is processed by one job at a time: the job is unique per
 * message id while queued, and the row is locked while it is claimed, so
 * the requeue sweeper and a self-released retry cannot both send it.
 *
 * The outcome of every message is an audit entry on the client it was
 * sent to (message.sent, or message.failed once the retries are used up).
 * A message to a client the system no longer serves (premium-only mode was
 * switched on after it was queued) is not sent: it is cancelled, its secret
 * wiped, and the audit entry is message.cancelled. So is a sign-in message
 * (Messenger::isSignInMessage) whose link or code expired while it waited,
 * or that a newer one of the same holder (client, or staff user) to the same
 * recipient replaced; the row's error says which, and the audit entry
 * carries the reason. A text message to a number no SMS may go to (any
 * more) is cancelled before it is sent (Messenger::refuseIfBlocked).
 */
class SendOutboundMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const HEARTBEAT_KEY = 'hdid.queue.heartbeat';

    /** Audit reasons of a sign-in message that is not sent any more. */
    public const SECRET_EXPIRED = 'secret_expired';

    public const SECRET_SUPERSEDED = 'secret_superseded';

    public int $tries = OutboundMessage::MAX_ATTEMPTS + 1;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $messageId) {}

    public function uniqueId(): string
    {
        return $this->messageId;
    }

    public function handle(Mailer $mailer, SmsSender $sms, Auditor $auditor, ClientTiers $tiers, SmsDeliveryChecks $deliveryChecks): void
    {
        Cache::put(self::HEARTBEAT_KEY, now()->toIso8601String(), now()->addHours(6));

        $message = $this->claim();

        if ($message === null) {
            return;
        }

        if ($message->client_id !== null && ! $tiers->isServed($message->client)) {
            $message->forceFill([
                'status' => OutboundMessageStatus::Cancelled,
                'error' => __('Not sent: the client is not served (only premium clients are served).'),
            ])->save();
            $message->redactSecret();
            $auditor->record('message.cancelled', $message->client, self::auditContext($message) + ['premium_only' => true]);

            return;
        }

        if (($reason = self::staleSignInSecret($message)) !== null) {
            $message->forceFill([
                'status' => OutboundMessageStatus::Cancelled,
                'error' => $reason === self::SECRET_EXPIRED
                    ? __('Not sent: the sign-in code or link had expired before it could go out.')
                    : __('Not sent: a newer sign-in code or link replaced it before it could go out.'),
            ])->save();
            $message->redactSecret();
            $auditor->record('message.cancelled', $message->client, self::auditContext($message) + ['reason' => $reason]);

            return;
        }

        // Asked again right before sending: a row queued before a country was taken
        // off the accepted list (or before an update) must not reach the gateway.
        if (app(Messenger::class)->refuseIfBlocked($message)) {
            return;
        }

        try {
            $gatewayMessageId = $this->deliver($message, $mailer, $sms);
        } catch (Throwable $e) {
            $exhausted = $message->attempts >= OutboundMessage::MAX_ATTEMPTS;
            $error = self::errorText($e);

            $message->forceFill([
                'status' => $exhausted ? OutboundMessageStatus::Failed : OutboundMessageStatus::Queued,
                'error' => mb_substr($error, 0, 2000),
            ])->save();

            if ($exhausted) {
                $message->redactSecret();
                $auditor->record('message.failed', $message->client, self::auditContext($message) + ['error' => mb_substr($error, 0, 500)]);
            }

            report($e);

            if (! $exhausted && $this->job !== null && ! $this->job->isReleased()) {
                $this->release(60 * $message->attempts);
            }

            return;
        }

        $message->forceFill(['status' => OutboundMessageStatus::Sent, 'sent_at' => now(), 'error' => null])->save();
        $message->redactSecret();
        $auditor->record('message.sent', $message->client, self::auditContext($message));

        // An SMS the gateway gave an id to is followed up for its delivery report.
        if ($gatewayMessageId !== null) {
            $deliveryChecks->schedule($message, $gatewayMessageId);
        }
    }

    /** What the error column of a message says when SMTP offered no STARTTLS (MAIL_REQUIRE_TLS). */
    public const SMTP_NO_TLS_ERROR = 'The SMTP server offers no STARTTLS, so the e-mail was not sent: the password and the message do not go out unencrypted (MAIL_REQUIRE_TLS). Turn STARTTLS on at the server, or use MAIL_SCHEME=smtps with port 465; only for a local test server set MAIL_REQUIRE_TLS=false.';

    /**
     * The error as the row and the audit entry keep it: the transport's own
     * text, except where it would leave the operator guessing (the SMTP
     * server without STARTTLS refused by MAIL_REQUIRE_TLS).
     */
    public static function errorText(Throwable $e): string
    {
        if ($e instanceof TransportExceptionInterface && str_contains($e->getMessage(), 'TLS required')) {
            return __(self::SMTP_NO_TLS_ERROR);
        }

        return $e->getMessage();
    }

    /**
     * What the audit entry says about the message; never its body.
     *
     * @return array<string, mixed>
     */
    private static function auditContext(OutboundMessage $message): array
    {
        return [
            'message_id' => $message->id,
            'channel' => $message->channel->value,
            'template_key' => $message->template_key?->value,
            'recipient' => $message->recipient,
            'attempts' => $message->attempts,
        ];
    }

    /**
     * Why a sign-in message must not go out any more: its secret expired
     * while it waited, or a newer sign-in message of the same kind to the
     * same recipient replaced it (a new link or code makes the older one
     * unusable). Null for every other message, and for a sign-in message
     * still worth sending. A newer raw message counts while it carries its
     * expiry or once its secret was wiped: raw secrets are the staff
     * sign-in codes only.
     */
    private static function staleSignInSecret(OutboundMessage $message): ?string
    {
        if (! Messenger::isSignInMessage($message)) {
            return null;
        }

        if (Carbon::parse((string) $message->meta['valid_until'])->isPast()) {
            return self::SECRET_EXPIRED;
        }

        // Only a newer secret of the same holder replaces one: two clients may share a
        // number, and two staff an SMS number, and one's code must not void the other's.
        $forUser = $message->meta['for_user'] ?? null;

        $replaced = OutboundMessage::query()
            ->where('id', '>', $message->id)
            ->where('recipient', $message->recipient)
            ->when(
                $message->template_key !== null,
                fn (Builder $query) => $query->where('template_key', $message->template_key)
                    ->when($message->client_id !== null, fn (Builder $query) => $query->where('client_id', $message->client_id), fn (Builder $query) => $query->whereNull('client_id')),
                fn (Builder $query) => $query->whereNull('template_key')
                    ->where(fn (Builder $raw) => $raw->whereNotNull('meta->valid_until')->orWhereNotNull('redacted_at'))
                    ->when(is_string($forUser), fn (Builder $query) => $query->where('meta->for_user', $forUser)),
            )
            ->exists();

        return $replaced ? self::SECRET_SUPERSEDED : null;
    }

    /**
     * Flip the row from queued to sending under a row lock; null when some
     * other job already took it (or it was cancelled meanwhile).
     */
    private function claim(): ?OutboundMessage
    {
        return DB::transaction(function (): ?OutboundMessage {
            $message = OutboundMessage::query()->whereKey($this->messageId)->lockForUpdate()->first();

            if ($message === null || $message->status !== OutboundMessageStatus::Queued) {
                return null;
            }

            $message->forceFill(['status' => OutboundMessageStatus::Sending, 'last_attempt_at' => now(), 'attempts' => $message->attempts + 1])->save();
            $message->load('client');

            return $message;
        });
    }

    /**
     * Hands the message to its transport; the gateway's message id for an
     * SMS, null for e-mail or a driver without ids.
     */
    private function deliver(OutboundMessage $message, Mailer $mailer, SmsSender $sms): ?string
    {
        if ($message->channel === Channel::Sms) {
            return $sms->send($message->recipient, $message->body);
        }

        $mailable = $message->template_key === MessageKey::MagicLink && $message->client !== null
            ? new MagicLinkMail($message->client, (string) ($message->meta['url'] ?? ''), (int) ($message->meta['ttl_minutes'] ?? 0), $message->subject, $message->body)
            : new TemplatedMail((string) $message->subject, $message->body, carriesSecret: $message->carriesSecret());

        $mailer->to($message->recipient)->send($mailable);

        return null;
    }
}
