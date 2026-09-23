<?php

namespace App\Identification;

use Illuminate\Support\Str;

/**
 * Case-, accent- and whitespace-insensitive form of an answer, hashed for
 * automated comparison (IVR / future self-service checks).
 */
class AnswerNormalizer
{
    public function normalize(string $answer): string
    {
        $value = Str::ascii(mb_strtolower(trim($answer)));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /**
     * Keyed like every other secret lookup in the project (PIN, one-time
     * codes): the answers come from a small vocabulary, so a plain digest
     * next to the encrypted answer would be a dictionary lookup for anyone
     * holding a database copy without the application key.
     */
    public function hash(string $answer): string
    {
        return hash_hmac('sha256', 'client-answer:'.$this->normalize($answer), (string) config('app.key'));
    }

    public function matches(string $given, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($given));
    }
}
