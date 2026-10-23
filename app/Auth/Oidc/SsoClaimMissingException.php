<?php

namespace App\Auth\Oidc;

use App\Auth\LoginRejection;
use RuntimeException;

/**
 * The tokens carry no claim that could find an account (no usable e-mail
 * address, or no employee id in that mode). Not audited by the resolver:
 * the browser sign-in records it with the reason, the mobile token exchange
 * stays neutral towards the caller and records it as invalid credentials.
 */
class SsoClaimMissingException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $diagnosis  what was looked for and what the tokens carried, values excluded
     */
    public function __construct(public readonly LoginRejection $reason, public readonly array $diagnosis)
    {
        parent::__construct(__($reason->message()));
    }
}
