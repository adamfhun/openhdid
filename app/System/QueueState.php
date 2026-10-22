<?php

namespace App\System;

use Illuminate\Support\Carbon;

/**
 * One worker queue as the Queues page and the status tile see it: whether a
 * worker is expected and when one was last seen, what waits, what is in
 * progress and what failed. Counts are null where the queue driver cannot
 * tell them apart (only the database driver knows reserved jobs and ages).
 */
final readonly class QueueState
{
    public const HEARTBEAT_MAX_AGE_MINUTES = 3;

    public function __construct(
        public string $name,
        public bool $configured,
        public ?Carbon $heartbeat,
        public ?int $waiting,
        public ?int $reserved,
        public ?Carbon $oldestWaitingAt,
        public int $failed,
    ) {}

    public function isAlive(): bool
    {
        return $this->heartbeat !== null && $this->heartbeat->gte(now()->subMinutes(self::HEARTBEAT_MAX_AGE_MINUTES));
    }

    public function pending(): int
    {
        return ($this->waiting ?? 0) + ($this->reserved ?? 0);
    }
}
