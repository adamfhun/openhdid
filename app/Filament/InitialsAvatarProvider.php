<?php

namespace App\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The staff member's initials as an inline SVG image, drawn in the browser. The
 * Filament default (UiAvatarsProvider) loads the picture from ui-avatars.com and
 * so sends every staff member's full name to a third party on each page; on an
 * intranet without internet access it is a broken image.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = mb_strtoupper(collect(preg_split('/\s+/u', trim(Filament::getNameForDefaultAvatar($record))) ?: [])
            // Leading punctuation ("[SYSTEM] Admin") does not become an initial.
            ->map(fn (string $segment): string => mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $segment), 0, 1))
            ->filter(fn (string $letter): bool => $letter !== '')
            ->take(2)
            ->join(''));

        $background = Color::convertToHex(FilamentColor::getColor('gray')[950] ?? Color::Gray[950]);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="'.htmlspecialchars($background, ENT_QUOTES | ENT_XML1).'"/>'
            .'<text x="32" y="32" dy=".35em" text-anchor="middle" fill="#ffffff" font-size="26" font-weight="500"'
            .' font-family="ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif">'
            .htmlspecialchars($initials, ENT_QUOTES | ENT_XML1)
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
