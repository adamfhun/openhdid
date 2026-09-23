<?php

namespace App\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Ozeki NG SMS Gateway HTTP API (`/api?action=sendmessage`). Credentials
 * and the message travel in a POST body, never in the URL, and a request
 * is only repeated when the connection itself failed: a timeout after the
 * gateway took the message would otherwise send the SMS twice.
 */
class OzekiSmsSender implements SmsSender
{
    public function __construct(
        private readonly Http $http,
        private readonly string $url,
        private readonly string $username,
        private readonly string $password,
    ) {}

    public function send(string $to, string $message): void
    {
        $response = $this->http
            ->asForm()
            ->timeout(15)
            ->retry(2, 500, fn (Throwable $e): bool => $e instanceof ConnectionException && ! str_contains(mb_strtolower($e->getMessage()), 'timed out'), throw: false)
            ->post($this->url, [
                'action' => 'sendmessage',
                'username' => $this->username,
                'password' => $this->password,
                'recipient' => $to,
                'messagetype' => 'SMS:TEXT',
                'messagedata' => $message,
            ]);

        if ($response->failed() || ! str_contains($response->body(), '<acceptreport>')) {
            throw new SmsException('Ozeki rejected the message: '.mb_substr($response->body(), 0, 300));
        }
    }
}
