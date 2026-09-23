<?php

namespace App\Branding;

use Filament\Support\Colors\Color;

/**
 * Builds a Filament shade ramp in which the configured colour is shade 600,
 * the shade Filament uses for buttons, links and active states. Lighter
 * shades fade towards white with less chroma, darker shades towards black,
 * so a deep brand blue still yields usable tints for backgrounds and badges.
 */
class BrandPalette
{
    /** shade => target lightness for the light side (absolute) or factor for the dark side */
    private const LIGHT = [50 => 0.975, 100 => 0.95, 200 => 0.90, 300 => 0.82, 400 => 0.71, 500 => null];

    private const DARK = [700 => 0.84, 800 => 0.70, 900 => 0.56, 950 => 0.42];

    /**
     * @return array<int, string> shade => oklch()
     */
    public static function fromHex(string $hex): array
    {
        [$l, $c, $h] = self::parse(Color::convertToOklch($hex));
        $l = min(max($l, 0.2), 0.8);

        $palette = [];

        foreach (self::LIGHT as $shade => $target) {
            $target ??= ($l + 0.71) / 2;
            $target = max($target, $l + 0.04);
            $palette[$shade] = self::oklch($target, $c * (1 - $target) / max(1 - $l, 0.01) * 0.9 + 0.005, $h);
        }

        $palette[600] = self::oklch($l, $c, $h);

        foreach (self::DARK as $shade => $factor) {
            $palette[$shade] = self::oklch($l * $factor, $c * (0.6 + 0.4 * $factor), $h);
        }

        return $palette;
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private static function parse(string $oklch): array
    {
        preg_match('/oklch\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)/', $oklch, $m);

        return [(float) $m[1], (float) $m[2], (float) $m[3]];
    }

    private static function oklch(float $l, float $c, float $h): string
    {
        return sprintf('oklch(%.4f %.4f %.2f)', min($l, 0.995), max($c, 0), $h);
    }
}
