<?php

namespace App\Sync;

/**
 * The source returned far fewer rows than last time: most likely a truncated
 * export, so the run is refused before it could close accounts.
 */
class SuspiciousSourceException extends EmptySourceException {}
