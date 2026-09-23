<?php

namespace App\Reporting;

/**
 * Calls taken by agents in a period, broken down by call queue and day.
 * Plain arrays only, so the summary can sit in the cache.
 *
 * @phpstan-type AgentRow array{id: string, name: string, total: int, by_queue: array<string, int>, by_day: array<string, array<string, int>>}
 */
final class AgentCallSummary
{
    /**
     * @param  list<string>  $days  Y-m-d, oldest first
     * @param  list<string>  $queues  busiest first; '' stands for calls without a queue
     * @param  list<AgentRow>  $agents  busiest first
     * @param  array<string, int>  $dayTotals
     * @param  array<string, int>  $queueTotals
     */
    public function __construct(
        public readonly array $days,
        public readonly array $queues,
        public readonly array $agents,
        public readonly array $dayTotals,
        public readonly array $queueTotals,
        public readonly int $total,
    ) {}

    /**
     * Plain array form for the cache: the cache stores only allow-listed
     * classes through unserialize, so the object itself must not be stored.
     *
     * @return array{days: list<string>, queues: list<string>, agents: list<AgentRow>, day_totals: array<string, int>, queue_totals: array<string, int>, total: int}
     */
    public function toArray(): array
    {
        return [
            'days' => $this->days,
            'queues' => $this->queues,
            'agents' => $this->agents,
            'day_totals' => $this->dayTotals,
            'queue_totals' => $this->queueTotals,
            'total' => $this->total,
        ];
    }

    /**
     * @param  array{days: list<string>, queues: list<string>, agents: list<AgentRow>, day_totals: array<string, int>, queue_totals: array<string, int>, total: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['days'], $data['queues'], $data['agents'], $data['day_totals'], $data['queue_totals'], (int) $data['total']);
    }

    public function isEmpty(): bool
    {
        return $this->agents === [];
    }

    /**
     * Calls of one agent on one day in one queue.
     */
    public function count(array $agent, string $day, string $queue): int
    {
        return $agent['by_day'][$day][$queue] ?? 0;
    }

    /**
     * Calls of one agent on one day, every queue.
     */
    public function dayCount(array $agent, string $day): int
    {
        return array_sum($agent['by_day'][$day] ?? []);
    }
}
