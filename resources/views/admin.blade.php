<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light" data-menu-styles="dark" data-toggled="close">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Admin | {{ $appName }}</title>

        <script>
            window.__DEFAULT_DASHBOARD_LOCALE__ = @json($defaultDashboardLocale);
        </script>
        <script>
            (function () {
                var storedLocale = localStorage.getItem('admin_locale');
                var storedDirection = localStorage.getItem('admin_direction');
                var defaultLocale = window.__DEFAULT_DASHBOARD_LOCALE__ || null;
                var locale = storedLocale || (defaultLocale && defaultLocale.code) || 'en';
                var direction = storedDirection
                    || (defaultLocale && defaultLocale.direction)
                    || (locale === 'ar' ? 'rtl' : 'ltr');

                document.documentElement.setAttribute('dir', direction);
                document.documentElement.setAttribute('lang', locale);
            })();
        </script>

        @if (! empty($branding['favicon_ico']))
            <link rel="icon" href="{{ $branding['favicon_ico'] }}" type="image/x-icon">
        @else
            <link rel="icon" href="{{ asset('dashboard/assets/images/brand-logos/favicon.ico') }}" type="image/x-icon">
        @endif
        @if (! empty($branding['favicon_32']))
            <link rel="icon" href="{{ $branding['favicon_32'] }}" sizes="32x32" type="image/png">
        @endif
        @if (! empty($branding['favicon_16']))
            <link rel="icon" href="{{ $branding['favicon_16'] }}" sizes="16x16" type="image/png">
        @endif
        @if (! empty($branding['apple_touch_icon']))
            <link rel="apple-touch-icon" href="{{ $branding['apple_touch_icon'] }}">
        @endif
        @if (! empty($branding['web_manifest']))
            <link rel="manifest" href="{{ $branding['web_manifest'] }}">
        @endif

        <script>
            window.__PLATFORM_BRANDING__ = @json($branding);
        </script>
        <link id="style" rel="stylesheet" href="/dashboard/assets/libs/bootstrap/css/bootstrap.min.css">
        <script>
            (function () {
                var link = document.getElementById('style');

                if (link && document.documentElement.getAttribute('dir') === 'rtl') {
                    link.href = '/dashboard/assets/libs/bootstrap/css/bootstrap.rtl.min.css';
                }
            })();
        </script>
        <link rel="stylesheet" href="{{ asset('dashboard/assets/css/styles.min.css') }}">
        <link rel="stylesheet" href="{{ asset('dashboard/assets/css/icons.min.css') }}">
        <link rel="stylesheet" href="{{ asset('dashboard/assets/libs/node-waves/waves.min.css') }}">
        <link rel="stylesheet" href="{{ asset('dashboard/assets/libs/simplebar/simplebar.min.css') }}">
        <link rel="stylesheet" href="{{ asset('dashboard/assets/libs/swiper/swiper-bundle.min.css') }}">
        <link rel="stylesheet" href="{{ asset('css/dorr-fonts.css') }}">
        <link rel="stylesheet" href="{{ asset('css/admin-dashboard.css') }}">

        <style>
            #app {
                min-height: 100vh;
            }

            html:not(.admin-app-ready) #app {
                visibility: hidden;
            }
        </style>

        @vite(['resources/js/app.js'])
    </head>
    <body>
        <div id="app"></div>

        <script src="{{ asset('dashboard/assets/libs/@popperjs/core/umd/popper.min.js') }}"></script>
        <script src="{{ asset('dashboard/assets/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
        <script src="{{ asset('dashboard/assets/libs/swiper/swiper-bundle.min.js') }}"></script>
    </body>
</html>
