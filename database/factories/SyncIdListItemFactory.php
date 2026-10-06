<?php

namespace Database\Factories;

use App\Models\SyncIdListItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncIdListItem>
 */
class SyncIdListItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'external_id' => (string) fake()->unique()->numberBetween(1, 99_999),
            'name' => fake()->company(),
            'selected' => false,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function selected(): static
    {
        return $this->state(['selected' => true]);
    }

    public function removed(): static
    {
        return $this->state(['removed_at' => now()]);
    }
}
