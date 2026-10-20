<?php

namespace App\Identification;

use App\Enums\AnswerCheckResult;
use Normalizer;

/**
 * Compares the answer an agent typed with the stored one for the blind
 * question-and-answer check. Both sides are NFKC-normalised, then case,
 * accents, punctuation and spaces are dropped (AnswerNormalizer). The
 * digits must be the same (1985 is not 1986); otherwise up to the
 * configured number of typos (letter swapped, missing, extra, or two
 * neighbours swapped) still match, except for short answers.
 */
class AnswerMatcher
{
    /** Answers shorter than this (normalised, without spaces) must match exactly. */
    public const EXACT_BELOW = 5;

    /** Typos beyond the tolerance that still count as "close": worth one retry. */
    public const NEAR_MARGIN = 2;

    public const MAX_TOLERANCE = 3;

    public function __construct(private readonly AnswerNormalizer $normalizer) {}

    /**
     * @return array{result: AnswerCheckResult, distance: int}
     */
    public function compare(string $stored, string $given, int $tolerance): array
    {
        $expected = $this->comparable($stored);
        $actual = $this->comparable($given);
        $distance = self::distance($expected, $actual);

        if ($actual === '' || self::digits($expected) !== self::digits($actual)) {
            return ['result' => AnswerCheckResult::Mismatch, 'distance' => $distance];
        }

        $allowed = strlen($expected) < self::EXACT_BELOW ? 0 : max(0, min($tolerance, self::MAX_TOLERANCE));

        $result = match (true) {
            $distance <= $allowed => AnswerCheckResult::Matched,
            $distance <= $allowed + self::NEAR_MARGIN && $distance * 2 <= strlen($expected) => AnswerCheckResult::Near,
            default => AnswerCheckResult::Mismatch,
        };

        return ['result' => $result, 'distance' => $distance];
    }

    public function comparable(string $answer): string
    {
        $composed = Normalizer::normalize($answer, Normalizer::FORM_KC);

        return str_replace(' ', '', $this->normalizer->normalize(is_string($composed) ? $composed : $answer));
    }

    /**
     * Optimal string alignment distance (Levenshtein plus swapped
     * neighbours) of two ASCII strings.
     */
    public static function distance(string $a, string $b): int
    {
        $lengthA = strlen($a);
        $lengthB = strlen($b);

        if ($lengthA === 0 || $lengthB === 0) {
            return max($lengthA, $lengthB);
        }

        $beforePrevious = [];
        $previous = range(0, $lengthB);

        for ($i = 1; $i <= $lengthA; $i++) {
            $current = [$i];

            for ($j = 1; $j <= $lengthB; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $current[$j] = min($previous[$j] + 1, $current[$j - 1] + 1, $previous[$j - 1] + $cost);

                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $current[$j] = min($current[$j], $beforePrevious[$j - 2] + 1);
                }
            }

            $beforePrevious = $previous;
            $previous = $current;
        }

        return $previous[$lengthB];
    }

    private static function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }
}
