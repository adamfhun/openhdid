<?php

namespace Database\Factories;

use App\Enums\IdChannel;
use App\Enums\IdMethod;
use App\Enums\IdSessionStatus;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdSession>
 */
class IdSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'agent_user_id' => User::factory(),
            'channel' => IdChannel::Manual,
            'method' => IdMethod::QuestionAnswer,
            'status' => IdSessionStatus::Open,
            'required_accepted' => 2,
            'max_questions' => 4,
            'max_rejected' => 2,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ];
    }
}
