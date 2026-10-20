<?php

namespace App\Enums;

/**
 * How an answer typed by the agent compared with the stored one in a blind
 * question-and-answer check (AnswerMatcher).
 */
enum AnswerCheckResult: string
{
    /** Within the typo tolerance: the question is accepted and the answer shown. */
    case Matched = 'matched';

    /** A little beyond the tolerance: one retry, then "cannot decide". */
    case Near = 'near';

    /** Surely not the stored answer: the question is rejected. */
    case Mismatch = 'mismatch';

    public function label(): string
    {
        return match ($this) {
            self::Matched => __('Matches'),
            self::Near => __('Close, but does not match'),
            self::Mismatch => __('Does not match'),
        };
    }
}
