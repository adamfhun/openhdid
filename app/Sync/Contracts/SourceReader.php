<?php

namespace App\Sync\Contracts;

use App\Enums\SyncSource;
use App\Sync\ExternalRecordDto;
use App\Sync\SkippedRow;

interface SourceReader
{
    public function source(): SyncSource;

    public function label(): ?string;

    /**
     * Rows in source order; a row the reader cannot turn into a record is
     * yielded as a SkippedRow so the run can report it.
     *
     * @return iterable<int, ExternalRecordDto|SkippedRow>
     */
    public function read(): iterable;

    /**
     * Request details for the run report (status codes, IDs sent); empty
     * for readers without a remote request worth reporting.
     *
     * @return array<string, array<string, mixed>>
     */
    public function details(): array;
}
