<?php

namespace App\Clients;

use App\Models\Client;

/**
 * What the automatic link endings did while a sync run collects them: the
 * links ended, and the linked clients reopened because their closed sponsor
 * stopped sponsoring. The endings happen deep in model hooks (a package the
 * directory changed, a sponsor reopened without premium), so the run cannot
 * count them itself; outside a run nothing is collected.
 */
class LinkEndReport
{
    private bool $collecting = false;

    /** @var array<string, int> links ended, by ended_reason */
    private array $ended = [];

    /** @var list<array{name: string, email: ?string, sponsor: ?string, sponsor_external_id: ?int}> */
    private array $reopened = [];

    public function start(): void
    {
        $this->collecting = true;
        $this->ended = [];
        $this->reopened = [];
    }

    public function stop(): void
    {
        $this->collecting = false;
        $this->ended = [];
        $this->reopened = [];
    }

    public function linkEnded(string $reason): void
    {
        if ($this->collecting) {
            $this->ended[$reason] = ($this->ended[$reason] ?? 0) + 1;
        }
    }

    public function reopened(Client $linked, ?Client $sponsor): void
    {
        if ($this->collecting) {
            $this->reopened[] = [
                'name' => $linked->name,
                'email' => $linked->email,
                'sponsor' => $sponsor?->name,
                'sponsor_external_id' => $sponsor?->externalRecord?->external_id,
            ];
        }
    }

    /**
     * What was collected since the last take; collecting goes on.
     *
     * @return array{ended: array<string, int>, reopened: list<array{name: string, email: ?string, sponsor: ?string, sponsor_external_id: ?int}>}
     */
    public function take(): array
    {
        $taken = ['ended' => $this->ended, 'reopened' => $this->reopened];
        $this->ended = [];
        $this->reopened = [];

        return $taken;
    }
}
