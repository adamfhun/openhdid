<?php

namespace App\System;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * In production an account may not get an address under the reserved
 * .test domain of the demo data (DemoData): it could never sign in there,
 * and the database would then look like it holds the demo data, which
 * stops the container start. Outside production any address passes.
 */
final class NotDemoAddressRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && DemoData::refusesNewAddress($value)) {
            $fail(__('Addresses under the reserved .test domain belong to the demo data and are refused in production (APP_ENV=production). Use the real address.'));
        }
    }
}
