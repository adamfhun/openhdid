<?php

namespace App\Jobs;

use App\Enums\OutboundMessageStatus;
use App\Mail\MagicLinkMail;
use App\Mail\TemplatedMail;
use App\Messaging\Channel;
use App\Messaging\MessageKey;
use App\Models\OutboundMessage;
use App\Sms\SmsSender;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
 */
class SendOutboundMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const HEARTBEAT_KEY = 'hdid.queue.heartbeat';

    public int $tries = OutboundMessage::MAX_ATTEMPTS + 1;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $messageId) {}

    public function uniqueId(): string
    {
        return $this->messageId;
    }

    public function handle(Mailer $mailer, SmsSender $sms): void
    {
        Cache::put(self::HEARTBEAT_KEY, now()->toIso8601String(), now()->addHours(6));

        $message = $this->claim();

        if ($message === null) {
            return;
        }

        try {
            $this->deliver($message, $mailer, $sms);
        } catch (Throwable $e) {
            $exhausted = $message->attempts >= OutboundMessage::MAX_ATTEMPTS;

            $message->forceFill([
                'status' => $exhausted ? OutboundMessageStatus::Failed : OutboundMessageStatus::Queued,
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            if ($exhausted) {
                $message->redactSecret();
            }

            report($e);

            if (! $exhausted && $this->job !== null && ! $this->job->isReleased()) {
                $this->release(60 * $message->attempts);
            }

            return;
        }

        $message->forceFill(['status' => OutboundMessageStatus::Sent, 'sent_at' => now(), 'error' => null])->save();
        $message->redactSecret();
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

    private function deliver(OutboundMessage $message, Mailer $mailer, SmsSender $sms): void
    {
        if ($message->channel === Channel::Sms) {
            $sms->send($message->recipient, $message->body);

            return;
        }

        $mailable = $message->template_key === MessageKey::MagicLink && $message->client !== null
            ? new MagicLinkMail($message->client, (string) ($message->meta['url'] ?? ''), (int) ($message->meta['ttl_minutes'] ?? 0), $message->subject, $message->body)
            : new TemplatedMail((string) $message->subject, $message->body);

        $mailer->to($message->recipient)->send($mailable);
    }
}
