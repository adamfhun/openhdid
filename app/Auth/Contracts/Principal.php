<?php

namespace App\Auth\Contracts;

use App\Enums\PrincipalType;
use App\Models\ExternalRecord;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Common surface of the two account types (User, Client) so that login,
 * account closure and sync code can be written once.
 */
interface Principal extends Authenticatable
{
    public function principalType(): PrincipalType;

    /** @return BelongsTo<ExternalRecord, $this> */
    public function externalRecord(): BelongsTo;

    public function getEmail(): string;

    public function isClosed(): bool;

    public function close(string $reason): void;

    public function reopen(): void;
}
