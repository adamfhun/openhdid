<?php

namespace App\Http\Requests\Api\V1\CallCenter;

use App\CallCenter\CallCenterService;
use App\Enums\CallStatus;
use App\Sync\ExternalRecordDto;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CallEventRequest extends FormRequest
{
    /** The longest call id the partner may send, here and in the path. */
    public const CALL_ID_MAX_LENGTH = 100;

    /**
     * The metadata is a flat object of at most CallCenterService::METADATA_MAX_KEYS
     * keys, each up to METADATA_KEY_MAX_LENGTH characters, with scalar values
     * of up to METADATA_VALUE_MAX_LENGTH characters. Every event merges into
     * the stored metadata, so the service bounds the merged object too.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'call_id' => ['required', 'string', 'max:'.self::CALL_ID_MAX_LENGTH],
            'caller_number' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::enum(CallStatus::class)],
            'queue' => ['nullable', 'string', 'max:100'],
            'arrived_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array', 'max:'.CallCenterService::METADATA_MAX_KEYS, $this->metadataKeys(...)],
            'metadata.*' => ['nullable', $this->metadataValue(...)],
            'client_id' => ['nullable', 'uuid'],
            'client_external_id' => ['nullable', 'integer', 'regex:'.ExternalRecordDto::EXTERNAL_ID_PATTERN],
        ];
    }

    /**
     * @param  Closure(string): mixed  $fail
     */
    private function metadataKeys(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (array_keys(is_array($value) ? $value : []) as $key) {
            if (mb_strlen((string) $key) > CallCenterService::METADATA_KEY_MAX_LENGTH) {
                $fail(__('A metadata key may be at most :max characters long.', ['max' => CallCenterService::METADATA_KEY_MAX_LENGTH]));

                return;
            }
        }
    }

    /**
     * @param  Closure(string): mixed  $fail
     */
    private function metadataValue(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_array($value) || is_object($value)) {
            $fail(__('A metadata value must be text, a number or true/false, not a list or an object.'));
        } elseif (is_string($value) && mb_strlen($value) > CallCenterService::METADATA_VALUE_MAX_LENGTH) {
            $fail(__('A metadata value may be at most :max characters long.', ['max' => CallCenterService::METADATA_VALUE_MAX_LENGTH]));
        }
    }
}
