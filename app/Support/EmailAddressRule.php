<?php

namespace App\Support;

use Closure;
use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An e-mail address within the standard lengths, every character counted
 * as one, an accented one too (decision of 2026-10-26: the database column
 * and the form fields count characters as well, so one count means fewer
 * surprises): at most 254 characters in all, 64 before the @ (after RFC
 * 5321 4.5.3.1, which counts octets), a domain of at most 253 characters
 * with labels of 63, and one DNS can carry (DomainNameRule, which judges an
 * internationalised domain in its ASCII form). Laravel's own "email" rule only
 * warns about these lengths and lets the address through; after them this
 * rule applies that same RFC check, so every address accepted before is
 * still accepted when it fits.
 *
 * A rule object, so that a Filament field (->rule(new EmailAddressRule)),
 * a plain validator and the settings rules use it alike; isValid() and
 * problem() serve the import and the commands, which have no validator.
 */
final class EmailAddressRule implements ValidationRule
{
    public const MAX_LENGTH = 254;

    public const LOCAL_MAX_LENGTH = 64;

    public const DOMAIN_MAX_LENGTH = DomainNameRule::MAX_LENGTH;

    public const LABEL_MAX_LENGTH = DomainNameRule::LABEL_MAX_LENGTH;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $lengthProblem = is_string($value) ? self::lengthProblem($value) : null;

        if ($lengthProblem !== null) {
            $fail($lengthProblem);

            return;
        }

        if (! is_string($value) || ! self::matchesRfc($value)) {
            // The framework's own message, as the plain "email" rule gave it.
            $fail('validation.email')->translate();
        }
    }

    public static function isValid(string $address): bool
    {
        return self::problem($address) === null;
    }

    /**
     * Why the address is not usable, as a translated sentence; null when it
     * is one.
     */
    public static function problem(string $address): ?string
    {
        return self::lengthProblem($address)
            ?? (self::matchesRfc($address) ? null : __('This is not a valid e-mail address.'));
    }

    private static function lengthProblem(string $address): ?string
    {
        if (mb_strlen($address) > self::MAX_LENGTH) {
            return __('The e-mail address may be at most :max characters long.', ['max' => self::MAX_LENGTH]);
        }

        $at = mb_strrpos($address, '@');

        if ($at === false) {
            return null;
        }

        if ($at > self::LOCAL_MAX_LENGTH) {
            return __('The part of the e-mail address before the @ may be at most :max characters long.', ['max' => self::LOCAL_MAX_LENGTH]);
        }

        $domain = mb_substr($address, $at + 1);

        // An address literal ("[192.0.2.1]") is no host name; the RFC check judges it.
        if ($domain === '' || str_starts_with($domain, '[')) {
            return null;
        }

        if (mb_strlen($domain) > self::DOMAIN_MAX_LENGTH) {
            return __('The domain of the e-mail address is not valid: :problem', ['problem' => __('The domain may be at most :max characters long.', ['max' => self::DOMAIN_MAX_LENGTH])]);
        }

        foreach (explode('.', $domain) as $label) {
            if (mb_strlen($label) > self::LABEL_MAX_LENGTH) {
                return __('The domain of the e-mail address is not valid: :problem', ['problem' => __('A part of the domain between two dots may be at most :max characters long.', ['max' => self::LABEL_MAX_LENGTH])]);
            }
        }

        $domainProblem = DomainNameRule::problem($domain);

        return $domainProblem === null ? null : __('The domain of the e-mail address is not valid: :problem', ['problem' => $domainProblem]);
    }

    /**
     * The check of Laravel's "email" rule without options.
     */
    private static function matchesRfc(string $address): bool
    {
        if (preg_match('/[\r\n]/', $address) === 1) {
            return false;
        }

        return (new EmailValidator)->isValid($address, new RFCValidation);
    }
}
