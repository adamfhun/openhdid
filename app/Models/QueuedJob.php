<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A job waiting in (or reserved from) the database queue, as Laravel stores
 * it in the jobs table. Read only: the worker owns the rows; the Queues page
 * only deletes waiting ones through QueueMaintenance.
 */
class QueuedJob extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'reserved_at' => 'datetime',
            'available_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function getConnectionName(): ?string
    {
        $connection = config('queue.connections.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnectionName();
    }

    public function getTable(): string
    {
        return (string) config('queue.connections.database.table', 'jobs');
    }

    public function isReserved(): bool
    {
        return $this->reserved_at !== null;
    }
}
