<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0275b3">
    <meta property="og:image" content="{{ url('/brand/og-image.png') }}">
    <title inertia>{{ config('app.name') }}</title>
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/brand/nubicta-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/brand/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.webmanifest">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
