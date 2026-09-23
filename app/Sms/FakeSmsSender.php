<?php

namespace App\Sms;

/**
 * Test double: remembers what would have been sent.
 */
class FakeSmsSender implements SmsSender
{
    /** @var list<array{to: string, message: string}> */
    public array $sent = [];

    public function send(string $to, string $message): void
    {
        $this->sent[] = ['to' => $to, 'message' => $message];
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
