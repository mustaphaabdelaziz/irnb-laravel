<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Apply the saved (or system) light/dark choice before paint to avoid a flash. --}}
        <script>
            (function () {
                try {
                    var t = localStorage.getItem('theme');
                    var dark = t ? t === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                    document.documentElement.classList.toggle('dark', dark);
                } catch (e) {}
            })();
        </script>

        @php
            $websiteConfig = \App\Models\WebsiteConfig::singleton();
            $appTitle = $websiteConfig->club_short_name ?? config('app.name', 'IRNB');
            $branding = $websiteConfig->branding ?? [];
            $logoUrl = $branding['logo'] ?? null;
            $primaryVars = \App\Support\Theme::cssVars($branding);
            $themeColor = \App\Support\Theme::themeColor($branding);
        @endphp
        <title inertia>{{ $appTitle }}</title>
        @if($logoUrl)
            <link rel="icon" type="image/png" href="{{ $logoUrl }}">
            <link rel="apple-touch-icon" href="{{ $logoUrl }}">
        @endif

        <!-- Fonts: Inter (Latin) + Cairo (Arabic), with Noto Sans Arabic fallback -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800,900|cairo:400,500,600,700,800,900|noto-sans-arabic:400,500,600,700&display=swap" rel="stylesheet" />
        <meta name="theme-color" content="{{ $themeColor }}">
        <meta name="color-scheme" content="light dark">

        <!-- Club primary color. Tailwind strips @layer at build, so app.css's default
             :root loads after this and would win on source order — use :root:root
             (specificity 0-2-0) so the saved club color reliably overrides it. -->
        <style>:root:root { {!! $primaryVars !!} }</style>

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
