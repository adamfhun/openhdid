<?php

namespace Database\Factories;

use App\Enums\ApiKeyScope;
use App\Models\ApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    public function definition(): array
    {
        $plain = 'hdid_'.fake()->regexify('[A-Za-z0-9]{40}');

        return [
            'name' => fake()->words(2, true),
            'scope' => ApiKeyScope::CallCenter,
            'key_hash' => ApiKey::hashOf($plain),
            'key_prefix' => substr($plain, 0, 12),
        ];
    }

    public function scope(ApiKeyScope $scope): static
    {
        return $this->state(['scope' => $scope]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }
}
