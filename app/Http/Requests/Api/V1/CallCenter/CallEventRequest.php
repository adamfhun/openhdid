<?php

namespace App\Http\Requests\Api\V1\CallCenter;

use App\Enums\CallStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CallEventRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'call_id' => ['required', 'string', 'max:100'],
            'caller_number' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::enum(CallStatus::class)],
            'queue' => ['nullable', 'string', 'max:100'],
            'arrived_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
            'client_id' => ['nullable', 'uuid'],
            'client_external_id' => ['nullable', 'integer'],
        ];
    }
}
