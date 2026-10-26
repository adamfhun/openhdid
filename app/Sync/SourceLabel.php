<?php

namespace App\Sync;

use App\Support\InputLimits;

/**
 * The name a run keeps of its source (sync_runs.file_name): a file name,
 * or the EMD address without its credentials, query string and fragment
 * (a query may carry a key), cut to fit the column.
 */
final class SourceLabel
{
    public static function of(?string $source): ?string
    {
        if ($source === null || $source === '') {
            return $source;
        }

        $parts = parse_url($source);

        if (is_array($parts) && isset($parts['host'])) {
            $source = (isset($parts['scheme']) ? $parts['scheme'].'://' : '//')
                .$parts['host']
                .(isset($parts['port']) ? ':'.$parts['port'] : '')
                .($parts['path'] ?? '');
        }

        return mb_substr($source, 0, InputLimits::NAME);
    }
}
