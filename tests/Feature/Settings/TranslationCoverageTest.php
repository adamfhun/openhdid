<?php

use Illuminate\Support\Facades\File;

/**
 * Every sentence the panel or the server shows goes through __() with an
 * English key, and the Hungarian text has to be in lang/hu.json, or the
 * Hungarian interface shows English. Keys built at run time (a prefix ending
 * in a dot, completed with a value) are not literal sentences.
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

                if (! str_ends_with($key, '.') && ! array_key_exists($key, $translations)) {
                    $missing[] = $key.' ('.$file->getRelativePathname().')';
                }
            }
        }
    }

    expect($missing)->toBe([]);
});
