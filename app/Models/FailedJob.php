<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A job the worker gave up on, as Laravel stores it in failed_jobs. Read
 * only: retrying, forgetting and pruning go through the framework's failed
 * job provider (QueueMaintenance), never through this model.
 */
class FailedJob extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'failed_at' => 'datetime',
        ];
    }

    public function getConnectionName(): ?string
    {
        $connection = config('queue.failed.database');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnectionName();
    }

    public function getTable(): string
    {
        return (string) config('queue.failed.table', 'failed_jobs');
    }

    /**
     * The first line of the exception: the message without the stack trace
     * and without the "in /path/to/file.php:123" tail PHP appends to it.
     */
    public function exceptionSummary(): string
    {
        $firstLine = trim((string) strtok((string) $this->exception, "\n"));

        return trim((string) preg_replace('/ in \S+(?:\(\d+\))?(?: : eval\(\)\'d code)?:\d+$/', '', $firstLine));
    }
}
