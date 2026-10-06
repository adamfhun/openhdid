<?php

namespace App\Sync;

/**
 * The EMD ID list query failed; carries the status codes for the run report.
 */
class IdListException extends SourceFormatException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
