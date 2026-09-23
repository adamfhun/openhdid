<?php

namespace App\Identification;

/**
 * Numeric codes meant to be read aloud. The digits are grouped in pairs and
 * the second digit of every pair is never zero, so that "nulla" cannot be
 * confused with a two-digit number when the caller dictates the code.
 */
final class DictationCode
{
    public const GROUP_SIZE = 2;

    public static function generate(int $length): string
    {
        $code = '';

        for ($position = 1; $position <= $length; $position++) {
            $code .= $position % self::GROUP_SIZE === 0 ? (string) random_int(1, 9) : (string) random_int(0, 9);
        }

        return $code;
    }

    /**
     * "12345678" becomes "12 34 56 78".
     */
    public static function format(string $code): string
    {
        return implode(' ', str_split($code, self::GROUP_SIZE));
    }

    /**
     * Whatever the caller or the IVR sends, keep only the digits.
     */
    public static function normalize(string $input): string
    {
        return preg_replace('/\D+/', '', $input) ?? '';
    }
}
