<?php

namespace App\Sms;

/**
 * Test double: remembers what would have been sent, gives every message a
 * gateway id, and answers delivery queries from the reports a test set up.
 */
class FakeSmsSender implements SmsSender
{
    /** @var list<array{to: string, message: string, id: string}> */
    public array $sent = [];

    /** @var array<string, SmsDeliveryReport|SmsException> gateway id => what a query returns (or throws) */
    public array $reports = [];

    /** @var list<string> gateway ids the delivery status was asked for, in order */
    public array $queried = [];

    public function send(string $to, string $message): ?string
    {
        $id = 'fake-'.(count($this->sent) + 1);
        $this->sent[] = ['to' => $to, 'message' => $message, 'id' => $id];

        return $id;
    }

    public function deliveryStatus(string $messageId): ?SmsDeliveryReport
    {
        $this->queried[] = $messageId;
        $report = $this->reports[$messageId] ?? null;

        if ($report instanceof SmsException) {
            throw $report;
        }

        return $report;
    }

    public function lastTo(string $to): ?string
    {
        foreach (array_reverse($this->sent) as $sms) {
            if ($sms['to'] === $to) {
                return $sms['message'];
            }
        }

        return null;
    }
}
