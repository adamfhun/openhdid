<?php

namespace App\CallCenter;

use App\Models\User;
use RuntimeException;

/**
 * Thrown when an agent takes a call that another agent already holds and
 * did not ask to take it over.
 */
class CallAlreadyClaimedException extends RuntimeException
{
    public function __construct(public readonly User $holder)
    {
        parent::__construct(__(':name has already taken this call.', ['name' => $holder->name]));
    }
}
