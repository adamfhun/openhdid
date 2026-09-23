<?php

namespace Database\Factories;

use App\Identification\AnswerNormalizer;
use App\Models\Client;
use App\Models\ClientAnswer;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientAnswer>
 */
class ClientAnswerFactory extends Factory
{
    public function definition(): array
    {
        $answer = fake()->word();

        return [
            'client_id' => Client::factory(),
            'question_id' => Question::factory(),
            'question_version_id' => fn (array $attributes) => Question::query()->find($attributes['question_id'])?->current_version_id,
            'answer' => $answer,
            'answer_hash' => (new AnswerNormalizer)->hash($answer),
        ];
    }
}
