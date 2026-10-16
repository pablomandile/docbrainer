<!DOCTYPE html>
{{-- data-text lo escribe el servidor (no hay opción "según el sistema"): sin script, sin parpadeo --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-text="{{ ($textSize ?? \App\Enums\TextSize::default())->value }}"
    @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- Al cambiar un ícono: subir el ?v= (el CDN y Chrome mobile cachean las URLs fijas) --}}
        <link rel="icon" href="/favicon.ico?v=1" sizes="any">
        <link rel="icon" href="/icons/favicon-96.png?v=1" type="image/png" sizes="96x96">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=1">
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="#3d2463">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="DocBrainer">

        {{-- beforeinstallprompt dispara apenas carga, normalmente ANTES de que monte Vue:
             se captura acá y el botón "Instalar app" lo lee de window.__pwaInstall --}}
        <script>
            (function () {
                window.__pwaInstall = { prompt: null, installed: false };

                window.addEventListener('beforeinstallprompt', function (e) {
                    e.preventDefault(); // el banner automático no; lo lanza el botón
                    window.__pwaInstall.prompt = e;
                    window.dispatchEvent(new CustomEvent('pwa:installable'));
                });

                window.addEventListener('appinstalled', function () {
                    window.__pwaInstall.prompt = null;
                    window.__pwaInstall.installed = true;
                    window.dispatchEvent(new CustomEvent('pwa:installed'));
                });
            })();
        </script>

        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', function () {
                    navigator.serviceWorker.register('/sw.js').catch(function () {});
                });
            }
        </script>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @vite(['resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
