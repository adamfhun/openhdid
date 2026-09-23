<?php

namespace Database\Factories;

use App\Enums\CallStatus;
use App\Enums\ClientTier;
use App\Models\Call;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Call>
 */
class CallFactory extends Factory
{
    public function definition(): array
    {
        $number = '+3630'.fake()->numerify('#######');

        return [
            'external_call_id' => fake()->unique()->uuid(),
            'caller_number_raw' => $number,
            'caller_number_e164' => $number,
            'status' => CallStatus::Ringing,
            'tier' => ClientTier::Premium,
            'arrived_at' => now(),
        ];
    }
}
