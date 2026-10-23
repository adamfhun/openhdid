<?php

use Illuminate\Support\Facades\File;

/**
 * Every sentence the panel or the server shows goes through __() with an
 * English key, and the Hungarian text has to be in lang/hu.json, or the
 * Hungarian interface shows English. Keys built at run time (a prefix such
 * as "permission." completed with a value) are not literal sentences; a
 * sentence that merely ends with a full stop is, and is checked too
 * (until 2026-10-23 every such sentence was skipped).
 */
it('has a Hungarian translation for every literal interface sentence', function (): void {
    $translations = json_decode((string) file_get_contents(lang_path('hu.json')), true);
    $missing = [];

    foreach ([app_path(), resource_path('views'), base_path('routes')] as $directory) {
        foreach (File::allFiles($directory) as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $file->getContents(), $matches);

            foreach ($matches[1] as $key) {
                $key = str_replace("\\'", "'", $key);

                $isRuntimePrefix = str_ends_with($key, '.') && ! str_contains($key, ' ');

                if (! $isRuntimePrefix && ! array_key_exists($key, $translations)) {
                    $missing[] = $key.' ('.$file->getRelativePathname().')';
                }
            }
        }
    }

    expect($missing)->toBe([]);
});
