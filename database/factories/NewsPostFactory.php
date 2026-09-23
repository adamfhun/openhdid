<?php

namespace Database\Factories;

use App\Models\NewsPost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsPost>
 */
class NewsPostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => '## '.fake()->sentence(3)."\n\n".fake()->paragraph(),
            'published_at' => now()->subDay(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['published_at' => null]);
    }
}
