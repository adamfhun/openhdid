<?php

use App\Auth\LoginRejection;
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

/**
 * The portal names a refused sign-in by the machine readable reason (an SSO
 * return carries only ?error=<reason>), so every LoginRejection case needs a
 * sentence in loginErrors.js and that sentence a Hungarian text in i18n.js;
 * otherwise the page stays silent or shows English.
 */
it('gives the client portal a translated message for every login refusal reason', function (): void {
    $errors = (string) file_get_contents(resource_path('js/client/loginErrors.js'));
    $dictionary = (string) file_get_contents(resource_path('js/client/i18n.js'));
    preg_match_all("/^\\s*([a-z_]+): '((?:[^'\\\\]|\\\\.)*)',$/m", $errors, $matches, PREG_SET_ORDER);
    $messages = collect($matches)->mapWithKeys(fn (array $match): array => [$match[1] => $match[2]]);

    $unmapped = collect(LoginRejection::cases())
        ->map(fn (LoginRejection $reason): string => $reason->value)
        ->reject(fn (string $reason): bool => $messages->has($reason))
        ->values()
        ->all();
    $untranslated = $messages
        ->reject(fn (string $message): bool => str_contains($dictionary, "'".$message."':") || str_contains($dictionary, '"'.$message.'":'))
        ->keys()
        ->all();

    expect($unmapped)->toBe([])
        ->and($untranslated)->toBe([]);
});
