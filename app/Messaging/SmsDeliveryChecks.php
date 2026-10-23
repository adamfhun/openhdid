<?php

namespace App\Messaging;

use App\Audit\Auditor;
use App\Enums\OutboundMessageStatus;
use App\Enums\SmsDeliveryStatus;
use App\Models\OutboundMessage;
use App\Sms\SmsSender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the SMS gateway what became of a sent SMS. The gateway does not
 * call us: after the send the message is queried at the configured
 * offsets (SMS_DELIVERY_CHECKS, at most five, by default 30 s, 2, 5, 30
 * and 120 minutes after sending). A report that says delivered or
 * undeliverable settles the message and is audited; after the last query
 * without one the message keeps "sent" with "no report". Nothing is ever
 * resent on its own: an undeliverable SMS is acknowledged as such, and an
 * operator may queue it again by hand.
 */
class SmsDeliveryChecks
{
    public const DEFAULT_CHECKS = [30, 120, 300, 1800, 7200];

    public const MAX_CHECKS = 5;

    public const MAX_OFFSET_SECONDS = 86_400;

    public function __construct(
        private readonly SmsSender $sms,
        private readonly Auditor $auditor,
    ) {}

    /**
     * The offsets, in seconds after sending, at which the gateway is asked;
     * the configured list when it is valid, the defaults otherwise (see
     * configProblem()).
     *
     * @return list<int>
     */
    public static function offsets(): array
    {
        return self::parse((string) config('hdid.sms.delivery_checks', '')) ?? self::DEFAULT_CHECKS;
    }

    /**
     * Why the configured list is not used, for the status page; null when
     * it is fine or empty.
     */
    public static function configProblem(): ?string
    {
        $raw = trim((string) config('hdid.sms.delivery_checks', ''));

        if ($raw === '' || self::parse($raw) !== null) {
            return null;
        }

        return __('SMS_DELIVERY_CHECKS must be 1 to :max whole seconds between 1 and :limit, comma-separated; the default (:default) is used instead.', [
            'max' => self::MAX_CHECKS,
            'limit' => self::MAX_OFFSET_SECONDS,
            'default' => implode(',', self::DEFAULT_CHECKS),
        ]);
    }

    /**
     * @return list<int>|null null when the list is empty or invalid
     */
    private static function parse(string $raw): ?array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $offsets = [];
        foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
            if (preg_match('/^\d{1,6}$/', $part) !== 1 || (int) $part < 1 || (int) $part > self::MAX_OFFSET_SECONDS) {
                return null;
            }

            $offsets[] = (int) $part;
        }

        if ($offsets === [] || count($offsets) > self::MAX_CHECKS) {
            return null;
        }

        sort($offsets);

        return array_values(array_unique($offsets));
    }

    /**
     * Starts following a message the gateway just accepted under this id.
     */
    public function schedule(OutboundMessage $message, string $gatewayMessageId): void
    {
        $message->forceFill([
            'gateway_message_id' => mb_substr($gatewayMessageId, 0, 64),
            'delivery_status' => SmsDeliveryStatus::Pending,
            'delivery_status_raw' => null,
            'delivery_checks' => 0,
            'next_delivery_check_at' => ($message->sent_at ?? now())->addSeconds(self::offsets()[0]),
            'delivery_updated_at' => null,
        ])->save();
    }

    /**
     * Messages whose next query is due.
     *
     * @return Builder<OutboundMessage>
     */
    public static function due(): Builder
    {
        return OutboundMessage::query()
            ->where('channel', Channel::Sms)
            ->where('delivery_status', SmsDeliveryStatus::Pending)
            ->whereNotNull('gateway_message_id')
            ->whereNotNull('next_delivery_check_at')
            ->where('next_delivery_check_at', '<=', now());
    }

    /**
     * Queries every due message once; the scheduler runs this every minute.
     *
     * @return array{checked: int, delivered: int, undeliverable: int, no_report: int}
     */
    public function run(int $limit = 100): array
    {
        $result = ['checked' => 0, 'delivered' => 0, 'undeliverable' => 0, 'no_report' => 0];

        foreach (self::due()->orderBy('next_delivery_check_at')->limit($limit)->get() as $message) {
            $outcome = $this->check($message);
            $result['checked']++;

            if ($outcome !== null) {
                $result[$outcome]++;
            }
        }

        return $result;
    }

    /**
     * One query of the gateway for this message. Returns what settled
     * ("delivered", "undeliverable", "no_report") or null while the report
     * is still pending.
     */
    public function check(OutboundMessage $message): ?string
    {
        $checks = $message->delivery_checks + 1;
        $offsets = self::offsets();

        try {
            $report = $this->sms->deliveryStatus((string) $message->gateway_message_id);
            $raw = $report?->raw;
            $status = $report?->status ?? SmsDeliveryStatus::Unknown;
        } catch (Throwable $e) {
            Log::warning('SMS delivery status query failed', ['message_id' => $message->id, 'check' => $checks, 'error' => $e->getMessage()]);
            $raw = __('query failed');
            $status = SmsDeliveryStatus::Pending;
        }

        if ($status === SmsDeliveryStatus::Delivered || $status === SmsDeliveryStatus::Undeliverable) {
            $message->forceFill([
                'status' => $status === SmsDeliveryStatus::Delivered ? OutboundMessageStatus::Delivered : OutboundMessageStatus::Undeliverable,
                'delivery_status' => $status,
                'delivery_status_raw' => $raw,
                'delivery_checks' => $checks,
                'next_delivery_check_at' => null,
                'delivery_updated_at' => now(),
                'error' => $status === SmsDeliveryStatus::Undeliverable ? __('The network reported the SMS undeliverable (:status).', ['status' => (string) $raw]) : $message->error,
            ])->save();

            $this->auditor->record($status === SmsDeliveryStatus::Delivered ? 'message.delivered' : 'message.undeliverable', $message->client, [
                'message_id' => $message->id,
                'channel' => $message->channel->value,
                'template_key' => $message->template_key?->value,
                'recipient' => $message->recipient,
                'delivery_status' => $raw,
                'checks' => $checks,
            ]);

            return $status->value;
        }

        // The driver cannot report at all: nothing to wait for.
        if ($status === SmsDeliveryStatus::Unknown && $raw === null) {
            $message->forceFill(['delivery_status' => SmsDeliveryStatus::Unknown, 'delivery_checks' => $checks, 'next_delivery_check_at' => null, 'delivery_updated_at' => now()])->save();

            return 'no_report';
        }

        if ($checks >= count($offsets)) {
            $message->forceFill([
                'delivery_status' => SmsDeliveryStatus::Unknown,
                'delivery_status_raw' => $raw,
                'delivery_checks' => $checks,
                'next_delivery_check_at' => null,
                'delivery_updated_at' => now(),
            ])->save();

            return 'no_report';
        }

        $message->forceFill([
            'delivery_status_raw' => $raw,
            'delivery_checks' => $checks,
            'next_delivery_check_at' => ($message->sent_at ?? now())->addSeconds($offsets[$checks]),
            'delivery_updated_at' => now(),
        ])->save();

        return null;
    }
}
