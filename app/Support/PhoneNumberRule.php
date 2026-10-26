<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number a person typed: at most 32 characters as typed (spaces,
 * dashes, brackets included), and one the normaliser turns into E.164,
 * which never has more than 15 digits. A rule object, so that a Filament
 * field, a plain validator and the settings rules use it alike; problem()
 * serves the commands.
 *
 * Numbers the call center sends (caller_number) are not human input: they
 * keep their own 50-character column limit and are stored even when they
 * cannot be normalised.
 */
final class PhoneNumberRule implements ValidationRule
{
    public const RAW_MAX_LENGTH = 32;

    public const E164_MAX_DIGITS = 15;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $problem = is_string($value) || is_int($value) ? self::problem((string) $value) : __('This is not a valid phone number.');

        if ($problem !== null) {
            $fail($problem);
        }
    }

    public static function isValid(string $number): bool
    {
        return self::problem($number) === null;
    }

    /**
     * Why the number is not usable, as a translated sentence; null when it
     * is one.
     */
    public static function problem(string $number): ?string
    {
        if (mb_strlen($number) > self::RAW_MAX_LENGTH) {
            return __('The phone number may be at most :max characters long.', ['max' => self::RAW_MAX_LENGTH]);
        }

        $e164 = app(PhoneNormalizer::class)->normalize($number);

        if ($e164 === null || strlen($e164) - 1 > self::E164_MAX_DIGITS) {
            return __('This is not a valid phone number.');
        }

        return null;
    }
}
