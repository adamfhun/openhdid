<?php

namespace App\Clients;

use Illuminate\Support\Str;
use Normalizer;

/**
 * One spelling for a package name wherever two names are compared: the
 * directory, the settings lists and the forms write the same package in
 * different ways (case, accents, stray spaces), and none of those is meant
 * to be a different package. Special characters are kept.
 */
class PackageName
{
    public static function normalize(?string $package): ?string
    {
        $name = trim((string) $package);

        if ($name === '') {
            return null;
        }

        if (class_exists(Normalizer::class)) {
            $name = Normalizer::normalize($name, Normalizer::FORM_KC) ?: $name;
        }

        $name = mb_strtolower(Str::ascii($name));
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return $name === '' ? null : $name;
    }

    public static function same(?string $a, ?string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }
}
