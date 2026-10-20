<?php

namespace App\Settings;

use App\Sync\RequestPayload;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * JSON validity of an EMD request body, with a message that names the fault:
 * a text placeholder written outside quotes, `{{ ids }}` outside quotes but
 * not inside brackets, the parser error otherwise. A rule object, because
 * Filament evaluates bare closures in a rules array as its own injected
 * closures instead of passing them on.
 */
class JsonPayloadRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '' || RequestPayload::isValidJson($value)) {
            return;
        }

        if (($names = RequestPayload::textPlaceholdersOutsideQuotes($value)) !== []) {
            $fail(__('The :list placeholder stands outside quotes. It is filled in as text, so write it inside a quoted value, for example "from": "{{ now }}". Only {{ ids }} may stand outside quotes, between brackets: "ids": [{{ ids }}].', [
                'list' => implode(', ', array_map(fn (string $name): string => '{{ '.$name.' }}', $names)),
            ]));

            return;
        }

        if (json_validate(RequestPayload::template($value, ids: 1))) {
            $fail(__('Outside quotes, {{ ids }} becomes a list of values (for example 3,12), so write it between brackets: "ids": [{{ ids }}]. Inside quotes it is filled in as text: "ids": "{{ ids }}" gives "3,12".'));

            return;
        }

        $fail(__('Invalid JSON. :fault', ['fault' => JsonFault::describe(RequestPayload::template($value))]));
    }
}
