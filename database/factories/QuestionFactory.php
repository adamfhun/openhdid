<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\QuestionVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'is_active' => true,
            'position' => fake()->unique()->numberBetween(1, 100000),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Question $question): void {
            if ($question->current_version_id !== null) {
                return;
            }

            $version = QuestionVersion::query()->create([
                'question_id' => $question->id,
                'version' => 1,
                'text' => fake()->unique()->sentence().'?',
                'keeps_answers' => true,
            ]);
            $question->forceFill(['current_version_id' => $version->id])->save();
        });
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
