<?php

namespace App\Filament;

use App\Branding\Branding;
use App\Branding\BrandPalette;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;

/**
 * Applies the admin-configured branding to a Filament panel: name, logo,
 * browser icons, the primary palette, and the full colour scheme as CSS variables
 * consumed by the panel theme.
 */
class BrandedPanel
{
    public static function apply(Panel $panel): Panel
    {
        $branding = fn (): Branding => app(Branding::class);

        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            function () use ($branding): HtmlString {
                $css = ':root { '.$branding()->cssVariables().' }';

                if ($background = $branding()->adminLoginBackgroundUrl()) {
                    $css .= ' .fi-simple-layout { background-image: linear-gradient(rgb(0 0 0 / 0.35), rgb(0 0 0 / 0.35)), url("'.e($background).'"); background-size: cover; background-position: center; }';
                }

                return new HtmlString('<style>'.$css.'</style>');
            },
        );

        // The panel's own favicon() emits a single untyped link; the shared
        // partial adds the SVG type, the ICO fallback and the iOS icon.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): HtmlString => new HtmlString(view('favicons', ['branding' => $branding()])->render()),
        );

        return $panel
            ->brandName(fn (): string => $branding()->appName())
            ->brandLogo(fn (): ?string => $branding()->logoUrl())
            ->colors(fn (): array => [
                'primary' => BrandPalette::fromHex($branding()->primaryColor()),
                'info' => BrandPalette::fromHex($branding()->accentColor()),
            ]);
    }
}
