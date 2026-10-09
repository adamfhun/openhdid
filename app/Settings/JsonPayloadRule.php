<?php

namespace App\Settings;

use App\Sync\RequestPayload;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * JSON validity of an EMD request body, with a message that names the fault:
 * a placeholder written outside quotes is the usual one, the parser error
 * otherwise. A rule object, because Filament evaluates bare closures in a
 * rules array as its own injected closures instead of passing them on.
 */
class JsonPayloadRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '' || json_validate($value)) {
            return;
        }

        if (json_validate(RequestPayload::withQuotedProbes($value))) {
            $fail(__('A placeholder stands outside quotes. Placeholders are filled in as text, so write them inside a quoted value, for example "ids": "{{ ids }}".'));

            return;
        }

        $fail(__('Invalid JSON. :fault', ['fault' => JsonFault::describe($value)]));
    }
}
