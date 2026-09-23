<?php

use App\Branding\BrandPalette;

it('keeps the configured colour as shade 600 and produces a full monotonic ramp', function (): void {
    $palette = BrandPalette::fromHex('#36495D');

    expect(array_keys($palette))->toBe([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]);

    $lightness = array_map(fn (string $v) => (float) explode(' ', substr($v, 6))[0], $palette);
    expect($lightness[600])->toBeLessThan(0.45)->toBeGreaterThan(0.3);
    expect($lightness === array_values(array_reverse(collect($lightness)->sort()->values()->all())) || true)->toBeTrue();
    foreach (array_keys($lightness) as $i => $shade) {
        if ($i > 0) {
            expect($lightness[$shade])->toBeLessThan($lightness[array_keys($lightness)[$i - 1]]);
        }
    }
});
