{{-- Browser and home-screen icons, shared by the client portal and the staff panel. --}}
@if ($branding->hasCustomFavicon())
    <link rel="icon" href="{{ $branding->faviconUrl() }}">
    {{-- Without a link iOS fetches /apple-touch-icon.png, the shipped icon, instead of the uploaded one. --}}
    <link rel="apple-touch-icon" href="{{ $branding->faviconUrl() }}">
@else
    <link rel="icon" href="{{ $branding->faviconUrl() }}" type="image/svg+xml">
    {{-- Fallback for browsers without SVG icon support, and the home-screen icon. --}}
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}" sizes="16x16 32x32 48x48">
    <link rel="apple-touch-icon" href="{{ asset(\App\Branding\Branding::DEFAULT_TOUCH_ICON) }}">
@endif
