<?php

namespace App\CallCenter;

use App\Models\Client;

/**
 * Outcome of matching a caller's number against the clients' numbers: the
 * single client it belongs to, or nothing — either because nobody has the
 * number or because several clients share it (ambiguous, never guessed).
 */
final readonly class PhoneMatch
{
    public function __construct(
        public ?Client $client,
        public bool $ambiguous = false,
    ) {}

    public static function none(): self
    {
        return new self(null);
    }
}
