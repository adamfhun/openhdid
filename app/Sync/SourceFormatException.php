<?php

namespace App\Sync;

use RuntimeException;

/**
 * The source is not in the shape the mapping expects (missing header
 * columns, missing data path in the API response, runaway pagination).
 */
class SourceFormatException extends RuntimeException {}
