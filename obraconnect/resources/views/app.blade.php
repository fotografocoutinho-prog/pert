<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#c2410c">
    <meta name="robots" content="noindex">
    {{-- Caminho base da instalação (vazio na raiz do domínio, ex.: /obras/obraconnect numa subpasta). --}}
    <meta name="app-base" content="{{ rtrim(request()->getBasePath(), '/') }}">
    <title>{{ config('app.name', 'ObraConnect') }}</title>
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" href="{{ asset('pwa/icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('pwa/icon-192.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    <div id="app"></div>
    <noscript>O ObraConnect precisa de JavaScript ativo no browser.</noscript>
</body>
</html>
