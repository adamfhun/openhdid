<?php

namespace Database\Factories;

use App\Enums\PhoneNumberSource;
use App\Models\Client;
use App\Models\ClientPhoneNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientPhoneNumber>
 */
class ClientPhoneNumberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'number_e164' => '+3630'.fake()->unique()->numerify('#######'),
            'source' => PhoneNumberSource::Admin,
            'is_primary' => false,
        ];
    }
}
