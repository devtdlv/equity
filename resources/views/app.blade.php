<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Equity') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|jetbrains-mono:400,500,600&display=swap" rel="stylesheet" />

        <script>
            (function () {
                try {
                    var storageKey = 'equity-theme-preference';
                    var stored = window.localStorage ? window.localStorage.getItem(storageKey) : null;
                    var preference = stored === 'light' || stored === 'dark' || stored === 'system' ? stored : 'system';
                    var systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                    var resolved = preference === 'system' ? (systemDark ? 'dark' : 'light') : preference;
                    var root = document.documentElement;
                    root.classList.toggle('dark', resolved === 'dark');
                    root.dataset.theme = resolved;
                    root.dataset.themePreference = preference;
                    root.style.colorScheme = resolved;
                } catch (e) {
                    // Fallback gracefully when localStorage or matchMedia is unavailable
                }
            })();
        </script>

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body class="bg-surface text-ink font-sans antialiased selection:bg-accent-subtle selection:text-accent-strong">
        @inertia
    </body>
</html>
