<?php

namespace App\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Development driver. Codes and PINs are masked before they reach the log
 * file: only the last two digits of any longer digit run survive.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        Log::info('SMS (log driver)', ['to' => $to, 'message' => static::mask($message)]);
    }

    public static function mask(string $message): string
    {
        return (string) preg_replace_callback(
            '/\d(?:[\d\s-]*\d)?/',
            function (array $m): string {
                $digits = preg_replace('/\D/', '', $m[0]) ?? '';
                if (strlen($digits) < 4) {
                    return $m[0];
                }

                return str_repeat('*', strlen($digits) - 2).substr($digits, -2);
            },
            $message,
        );
    }
}
