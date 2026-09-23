<?php

namespace App\Auth;

use RuntimeException;

class LoginRejectedException extends RuntimeException
{
    public function __construct(public readonly LoginRejection $reason)
    {
        parent::__construct(__($reason->message()));
    }
}
