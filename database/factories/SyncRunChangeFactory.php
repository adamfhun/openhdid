<?php

namespace Database\Factories;

use App\Enums\SyncChangeKind;
use App\Models\SyncRun;
use App\Models\SyncRunChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRunChange>
 */
class SyncRunChangeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sync_run_id' => SyncRun::factory(),
            'external_id' => fake()->unique()->numberBetween(1, 9_999_999),
            'kind' => SyncChangeKind::Updated,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'changes' => [
                ['event' => 'external_record.updated', 'subject_type' => null, 'subject_id' => null, 'context' => ['from' => ['title' => 'A'], 'to' => ['title' => 'B']]],
            ],
            'created_at' => now(),
        ];
    }

    public function created(): static
    {
        return $this->state(fn (): array => [
            'kind' => SyncChangeKind::Created,
            'changes' => [['event' => 'external_record.created', 'subject_type' => null, 'subject_id' => null, 'context' => ['attributes' => ['name' => 'New']]]],
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['kind' => SyncChangeKind::Closed]);
    }
}
