<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A host name as DNS carries it (RFC 1035, RFC 5890): at most 253 octets,
 * dot-separated labels of 1 to 63 octets made of letters, digits and inner
 * hyphens. An internationalised name is judged in its ASCII (punycode)
 * form, the one that goes on the wire. A rule object, so that a Filament
 * field, a plain validator and the settings rules use it alike; problem()
 * serves callers without a validator (the e-mail rule, imports).
 */
final class DomainNameRule implements ValidationRule
{
    public const MAX_LENGTH = 253;

    public const LABEL_MAX_LENGTH = 63;

    private const LABEL_PATTERN = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $problem = is_string($value) ? self::problem($value) : __('This is not a valid domain name.');

        if ($problem !== null) {
            $fail($problem);
        }
    }

    public static function isValid(string $domain): bool
    {
        return self::problem($domain) === null;
    }

    /**
     * Why the name is not a usable domain, as a translated sentence; null
     * when it is one.
     */
    public static function problem(string $domain): ?string
    {
        $ascii = self::toAscii($domain);

        if ($ascii === null) {
            return __('This is not a valid domain name.');
        }

        if (strlen($ascii) > self::MAX_LENGTH) {
            return __('The domain may be at most :max characters long.', ['max' => self::MAX_LENGTH]);
        }

        foreach (explode('.', $ascii) as $label) {
            if (strlen($label) > self::LABEL_MAX_LENGTH) {
                return __('A part of the domain between two dots may be at most :max characters long.', ['max' => self::LABEL_MAX_LENGTH]);
            }

            if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
                return __('This is not a valid domain name.');
            }
        }

        return null;
    }

    /**
     * The ASCII form DNS uses; null when an internationalised name cannot
     * be converted (or is empty).
     */
    private static function toAscii(string $domain): ?string
    {
        if ($domain === '') {
            return null;
        }

        if (preg_match('/^[\x20-\x7e]*$/', $domain) === 1) {
            return $domain;
        }

        if (! function_exists('idn_to_ascii')) {
            return null;
        }

        $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) && $ascii !== '' ? $ascii : null;
    }
}
