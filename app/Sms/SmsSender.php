<?php

namespace App\Sms;

interface SmsSender
{
    /**
     * @param  string  $to  E.164 number
     */
    public function send(string $to, string $message): void;
}
