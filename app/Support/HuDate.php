<?php

namespace App\Support;

/**
 * Hungarian date and time formats (MSZ ISO 8601 house style): year first,
 * dotted, with a space after each dot; 24-hour time.
 */
final class HuDate
{
    public const DATE = 'Y. m. d.';

    public const DATETIME = 'Y. m. d. H:i';

    public const DATETIME_SECONDS = 'Y. m. d. H:i:s';

    public const TIME = 'H:i';

    public const TIME_SECONDS = 'H:i:s';

    public const MONTH_DAY = 'm. d.';

    /**
     * A fragment typed into a table search box, in the shape the stored
     * timestamp has ("2026-10-23 09:54:00"), so that `LIKE` finds it on every
     * database: the dotted Hungarian date parts become dashes ("10. 23." →
     * "10-23"), the dot before the time becomes the space ("23. 09:5" →
     * "23 09:5"); a plain time ("09:54") or an ISO fragment is left as is.
     */
    public static function searchFragment(string $search): string
    {
        $fragment = preg_replace('/\s*\.\s*/', '-', trim($search)) ?? '';
        $fragment = preg_replace('/-(?=\d{1,2}:)/', ' ', $fragment) ?? '';

        return trim($fragment, '- ');
    }
}
