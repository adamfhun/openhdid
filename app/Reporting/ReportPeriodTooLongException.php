<?php

namespace App\Reporting;

use RuntimeException;

/**
 * The requested report period is wider than UserReport::MAX_DAYS.
 */
class ReportPeriodTooLongException extends RuntimeException {}
