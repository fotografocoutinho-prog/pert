<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#c2410c">
    <meta name="robots" content="noindex">
    <title>{{ config('app.name', 'ObraConnect') }}</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    <div id="app"></div>
    <noscript>O ObraConnect precisa de JavaScript ativo no browser.</noscript>
</body>
</html>
