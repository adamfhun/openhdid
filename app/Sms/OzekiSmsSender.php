<?php

namespace App\Sms;

use App\Enums\SmsDeliveryStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Ozeki NG SMS Gateway HTTP API (`/api?action=sendmessage`). Credentials
 * and the message travel in a POST body, never in the URL, and a request
 * is only repeated when the connection itself failed: a timeout after the
 * gateway took the message would otherwise send the SMS twice.
 *
 * The accept report carries the gateway's message id; the delivery report
 * is asked for with it later (OZEKI_STATUS_ACTION, SmsDeliveryChecks), and
 * the gateway's status words are mapped by two configurable lists.
 */
class OzekiSmsSender implements SmsSender
{
    public const DEFAULT_STATUS_ACTION = 'querymessage';

    public const DEFAULT_DELIVERED_STATUSES = ['delivered', 'deliveredtohandset'];

    public const DEFAULT_UNDELIVERED_STATUSES = ['undelivered', 'notsent', 'deliveryfailed', 'failed', 'rejected', 'expired'];

    /**
     * @param  list<string>  $deliveredStatuses  gateway status words that mean "reached the handset"
     * @param  list<string>  $undeliveredStatuses  gateway status words that mean "will never arrive"
     */
    public function __construct(
        private readonly Http $http,
        private readonly string $url,
        private readonly string $username,
        private readonly string $password,
        private readonly string $statusAction = self::DEFAULT_STATUS_ACTION,
        private readonly array $deliveredStatuses = self::DEFAULT_DELIVERED_STATUSES,
        private readonly array $undeliveredStatuses = self::DEFAULT_UNDELIVERED_STATUSES,
    ) {}

    public function send(string $to, string $message): ?string
    {
        $response = $this->request([
            'action' => 'sendmessage',
            'recipient' => $to,
            'messagetype' => 'SMS:TEXT',
            'messagedata' => $message,
        ]);

        if ($response->failed() || ! str_contains($response->body(), '<acceptreport>')) {
            throw new SmsException('Ozeki rejected the message: '.mb_substr($response->body(), 0, 300));
        }

        return self::element($response->body(), ['messageid']);
    }

    /**
     * The status the gateway holds for a message it accepted. The element
     * carrying it is found by name (`status`, `messagestatus`,
     * `deliverystatus`, `state`), so a gateway version that spells the
     * answer differently still works once the lists are set; an answer with
     * no such element, or an error, is an exception.
     */
    public function deliveryStatus(string $messageId): ?SmsDeliveryReport
    {
        $response = $this->request([
            'action' => $this->statusAction,
            'messageid' => $messageId,
        ]);

        $body = $response->body();
        $raw = self::element($body, ['messagestatus', 'deliverystatus', 'status', 'state']);

        if ($response->failed() || $raw === null || str_contains($body, '<error>')) {
            throw new SmsException('Ozeki did not report the message status: '.mb_substr($body, 0, 300));
        }

        $word = mb_strtolower(trim($raw));

        $status = match (true) {
            in_array($word, $this->deliveredStatuses, true) => SmsDeliveryStatus::Delivered,
            in_array($word, $this->undeliveredStatuses, true) => SmsDeliveryStatus::Undeliverable,
            default => SmsDeliveryStatus::Pending,
        };

        return new SmsDeliveryReport($status, mb_substr(trim($raw), 0, 80));
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function request(array $fields): Response
    {
        return $this->http
            ->asForm()
            ->timeout(15)
            ->retry(2, 500, fn (Throwable $e): bool => $e instanceof ConnectionException && ! str_contains(mb_strtolower($e->getMessage()), 'timed out'), throw: false)
            ->post($this->url, ['username' => $this->username, 'password' => $this->password] + $fields);
    }

    /**
     * The text of the first of the named XML elements present in the body,
     * whichever comes first in the list; tag names are matched regardless
     * of case.
     *
     * @param  list<string>  $names
     */
    private static function element(string $body, array $names): ?string
    {
        foreach ($names as $name) {
            if (preg_match('/<'.preg_quote($name, '/').'(?:\s[^>]*)?>(.*?)<\/'.preg_quote($name, '/').'>/is', $body, $match) === 1) {
                $value = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_XML1));

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
