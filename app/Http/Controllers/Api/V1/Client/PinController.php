<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Http\Controllers\Controller;
use App\Identification\PinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PinController extends Controller
{
    public function __construct(private readonly PinService $pins) {}

    /**
     * Set or replace the identification PIN.
     *
     * @response array{message: string, min_length: int, max_length: int}
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($this->pins->clientChangesEnabled(), 403, __('PIN changes are managed by the helpdesk.'));

        $data = $request->validate(['pin' => ['required', 'string', 'confirmed', 'digits_between:'.$this->pins->minLength().','.$this->pins->maxLength()]]);

        $this->pins->setPin($request->user(), $data['pin']);

        return response()->json(['message' => __('PIN saved.'), 'min_length' => $this->pins->minLength(), 'max_length' => $this->pins->maxLength()]);
    }

    /**
     * Remove the PIN.
     *
     * @response array{message: string}
     */
    public function destroy(Request $request): JsonResponse
    {
        abort_unless($this->pins->clientChangesEnabled(), 403, __('PIN changes are managed by the helpdesk.'));

        $this->pins->clearPin($request->user());

        return response()->json(['message' => __('PIN removed.')]);
    }
}
