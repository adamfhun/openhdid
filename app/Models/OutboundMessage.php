<?php

namespace App\Models;

use App\Enums\OutboundMessageStatus;
use App\Messaging\Channel;
use App\Messaging\MessageKey;
use App\Models\Concerns\HasTimeOrderedUuidKey;
use Database\Factories\OutboundMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One e-mail or SMS on its way out: rendered up front, sent by the queue,
 * with the outcome kept for the operators.
 */
#[Fillable(['channel', 'template_key', 'recipient', 'subject', 'body', 'meta', 'client_id', 'status', 'error', 'attempts', 'sent_at', 'last_attempt_at', 'redacted_at'])]
class OutboundMessage extends Model
{
    /** @use HasFactory<OutboundMessageFactory> */
    use HasFactory;

    use HasTimeOrderedUuidKey;

    public const MAX_ATTEMPTS = 3;

    /** Queued rows older than this are re-dispatched; must exceed the job's longest self-release (60 s × attempts). */
    public const STALE_QUEUED_MINUTES = 5;

    /** Sending rows older than this are treated as a crashed worker. */
    public const STALE_SENDING_MINUTES = 30;

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'template_key' => MessageKey::class,
            'status' => OutboundMessageStatus::class,
            'meta' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'redacted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function carriesSecret(): bool
    {
        return $this->template_key?->carriesSecret() ?? false;
    }

    public function isRedacted(): bool
    {
        return $this->redacted_at !== null;
    }

    /**
     * Wipe the rendered secret once the message is out of our hands (sent,
     * or given up on). The subject and the audit trail stay.
     */
    public function redactSecret(): void
    {
        if (! $this->carriesSecret() || $this->isRedacted()) {
            return;
        }

        $this->forceFill(['body' => '', 'meta' => null, 'redacted_at' => now()])->save();
    }

    public function isPending(): bool
    {
        return in_array($this->status, [OutboundMessageStatus::Queued, OutboundMessageStatus::Sending], true);
    }

    /**
     * @param  Builder<OutboundMessage>  $query
     */
    public function scopeQueued(Builder $query): void
    {
        $query->where('status', OutboundMessageStatus::Queued);
    }

    /**
     * Queued messages nobody picked up, or sends that never finished.
     *
     * Residual risk, accepted: a worker that died after the transport took
     * the message but before the row was marked sent leaves a "sending" row
     * that is re-sent after STALE_SENDING_MINUTES. Neither SMTP nor the SMS
     * gateway confirms delivery idempotently, so a duplicate is the lesser
     * evil compared with a login link that never arrives.
     *
     * @param  Builder<OutboundMessage>  $query
     */
    public function scopeStale(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $queued) => $queued->where('status', OutboundMessageStatus::Queued)->where('updated_at', '<', now()->subMinutes(self::STALE_QUEUED_MINUTES)))
            ->orWhere(fn (Builder $sending) => $sending->where('status', OutboundMessageStatus::Sending)->where('updated_at', '<', now()->subMinutes(self::STALE_SENDING_MINUTES))));
    }
}
