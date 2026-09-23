<?php

namespace Database\Factories;

use App\Enums\SyncRunStatus;
use App\Enums\SyncSource;
use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source' => SyncSource::Csv,
            'status' => SyncRunStatus::Completed,
            'started_at' => now(),
            'finished_at' => now(),
            'stats' => [],
        ];
    }
}
