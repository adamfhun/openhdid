<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Normalises any human-typed phone number to E.164 so that caller matching
 * is exact. Returns null for anything that cannot be parsed.
 */
class PhoneNormalizer
{
    public function __construct(
        private readonly PhoneNumberUtil $util,
        private readonly string $defaultRegion,
    ) {}

    public function normalize(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);

        // As typed first ("06 30 ...", "+36 30 ...", "0036 30 ..."); then the
        // call-center way: country code without the plus sign ("3630...").
        return $this->parseValid($raw) ?? $this->parseValid('+'.preg_replace('/\D+/', '', $raw));
    }

    private function parseValid(string $raw): ?string
    {
        if ($raw === '+' || $raw === '') {
            return null;
        }

        try {
            $number = $this->util->parse($raw, $this->defaultRegion);
        } catch (NumberParseException) {
            return null;
        }

        if (! $this->util->isValidNumber($number)) {
            return null;
        }

        return $this->util->format($number, PhoneNumberFormat::E164);
    }

    /**
     * @param  iterable<int, string|null>  $raws
     * @return list<string>
     */
    public function normalizeMany(iterable $raws): array
    {
        $result = [];

        foreach ($raws as $raw) {
            $normalized = $this->normalize($raw);

            if ($normalized !== null) {
                $result[$normalized] = true;
            }
        }

        return array_keys($result);
    }
}
