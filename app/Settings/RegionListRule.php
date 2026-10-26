<?php

namespace App\Settings;

use App\Sms\SmsDestinations;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A list of country (ISO 3166 region) codes the numbering plan knows, such
 * as the accepted countries of phone numbers. A rule object, so that the
 * Filament form and the plain validator use it alike.
 */
class RegionListRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail(__('Choose the countries from the list.'));

            return;
        }

        $unknown = array_diff(SmsDestinations::normalizeRegions($value), SmsDestinations::knownRegions());

        if ($unknown !== []) {
            $fail(__('Unknown country code: :codes.', ['codes' => implode(', ', $unknown)]));
        }
    }
}
