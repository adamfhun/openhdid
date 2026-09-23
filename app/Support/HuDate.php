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
}
