<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="application-name" content="{{ config('app.name') }}">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
        <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#171716" media="(prefers-color-scheme: dark)">

        <meta property="og:type" content="website">
        <meta property="og:title" content="facturac.ao · Facturação electrónica para Angola">
        <meta property="og:description" content="Software certificado pela AGT (FE/419/AGT/2026). Passe facturas, recibos e notas, comunicadas à AGT, com SAF-T e contas correntes.">
        <meta name="twitter:card" content="summary_large_image">
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:locale" content="pt_AO">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:image" content="{{ url('/brand/share-card.png') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="facturac.ao: Facturação sempre acabou em .ao. Software certificado pela AGT.">
        <meta name="twitter:image" content="{{ url('/brand/share-card.png') }}">
        <link rel="canonical" href="{{ url()->current() }}">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon-32x32.png" type="image/png" sizes="32x32">
        <link rel="icon" href="/favicon-16x16.png" type="image/png" sizes="16x16">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="mask-icon" href="/safari-pinned-tab.svg" color="#f9b233">
        <link rel="manifest" href="/site.webmanifest">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
