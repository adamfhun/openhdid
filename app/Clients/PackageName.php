<?php

namespace App\Clients;

use Illuminate\Support\Str;
use Normalizer;

/**
 * One spelling for a package name wherever two names are compared: the
 * directory, the settings lists and the forms write the same package in
 * different ways (case, accents, compatibility forms, stray spaces and
 * invisible characters), and none of those is meant to be a different
 * package. A character with no ASCII spelling (CJK, ✓, ®) is kept as it
 * is, so names that differ only in it stay different packages (2026-11-02,
 * owner decision Q24; before, it was dropped and such names folded together).
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

        // Tabs, line breaks, other spaces and zero-width spaces separate words.
        $name = (string) preg_replace('/[\s\p{Z}\x{200B}\x{2060}\x{FEFF}]+/u', ' ', $name);
        // The remaining invisible formatting and control characters go.
        $name = (string) preg_replace('/[\p{Cf}\p{Cc}]/u', '', $name);
        // Each character in its ASCII spelling, when it has one.
        $name = (string) preg_replace_callback('/[^\x00-\x7F]/u', fn (array $match): string => ($ascii = Str::ascii($match[0])) !== '' ? $ascii : $match[0], $name);

        $name = mb_strtolower($name);
        $name = trim((string) preg_replace('/ +/', ' ', $name));

        return $name === '' ? null : $name;
    }

    public static function same(?string $a, ?string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }

    /**
     * Whether the name carries a character with no ASCII spelling (CJK, ✓,
     * ®): such names compare differently since 1.15.1 (they used to fold
     * onto the name without the character).
     */
    public static function hasCharactersWithoutAscii(string $name): bool
    {
        $composed = class_exists(Normalizer::class) ? (Normalizer::normalize($name, Normalizer::FORM_KC) ?: $name) : $name;
        preg_match_all('/[^\x00-\x7F]/u', $composed, $matches);

        foreach ($matches[0] as $character) {
            if (preg_match('/[\s\p{Z}\p{Cf}\p{Cc}]/u', $character) !== 1 && Str::ascii($character) === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Why a settings list entry cannot work (it compares with nothing), or null.
     */
    public static function problem(string $name): ?string
    {
        return self::normalize($name) === null ? __('it has no comparable spelling (only spaces or invisible characters)') : null;
    }
}
