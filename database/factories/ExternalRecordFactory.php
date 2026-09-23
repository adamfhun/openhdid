<?php

namespace Database\Factories;

use App\Enums\PrincipalType;
use App\Models\ExternalRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalRecord>
 */
class ExternalRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'external_id' => fake()->unique()->numberBetween(1000, 9_999_999),
            'kind' => PrincipalType::Client,
            'company' => fake()->company(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phones' => [],
            'attributes' => [],
        ];
    }

    public function user(): static
    {
        return $this->state(['kind' => PrincipalType::User]);
    }

    public function client(): static
    {
        return $this->state(['kind' => PrincipalType::Client]);
    }

    public function missing(): static
    {
        return $this->state(['missing_since' => now(), 'missed_runs' => 1]);
    }
}
