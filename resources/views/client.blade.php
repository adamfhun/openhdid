<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" style="{{ $branding->cssVariables() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $branding->appName() }}</title>
    @include('favicons')
    <script>window.__BRANDING__ = @json($branding->toArray());</script>
    @vite(['resources/css/client.css', 'resources/js/client/main.js'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
